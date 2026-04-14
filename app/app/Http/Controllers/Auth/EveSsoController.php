<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\EveDonationsToken;
use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Domains\UsersCharacters\Models\EveServiceToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Eve\Sso\EveSsoClient;
use App\Services\Eve\Sso\EveSsoException;
use App\Services\Eve\Sso\EveSsoToken;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * /auth/eve — EVE SSO entry + callback for all four flows.
 *
 * Four flows share the same callback URL so the registered CCP app
 * only needs one redirect URI on file:
 *
 *   GET  /auth/eve                       → `redirect()`         (login, publicData)
 *   GET  /auth/eve/service-redirect      → `redirectAsService()` (admin-only,
 *                                                                 elevated scopes)
 *   GET  /auth/eve/donations-redirect    → `redirectAsDonations()` (admin-only,
 *                                                                   wallet-read,
 *                                                                   character-locked)
 *   GET  /auth/eve/market-redirect       → `redirectAsMarket()`  (donor-gated,
 *                                                                 per-user,
 *                                                                 character-linked)
 *   GET  /auth/eve/callback              → `callback()`         (dispatches by
 *                                                                session marker)
 *
 * The session stashes `eve_sso.flow ∈ {'login', 'service', 'donations', 'market'}`
 * alongside the PKCE state + verifier when redirecting; the callback
 * reads that marker to pick the right handler. Bare callbacks (no
 * marker, e.g. someone pasting the URL) fall through to the safer
 * login handler.
 *
 * ADR-0002 § Token kinds + ADR-0004 § Live polling for the policy split:
 *   - login flow: `publicData` scope, token discarded after identity
 *     extraction, identity persisted to characters/users.
 *   - service flow: full scope set from `EVE_SSO_SERVICE_SCOPES`,
 *     access + refresh tokens stored encrypted in `eve_service_tokens`,
 *     consumed by the Python execution plane and Laravel callers that
 *     need authed ESI access.
 *   - donations flow: single-character, single-scope (wallet-read),
 *     access + refresh tokens stored encrypted in `eve_donations_tokens`,
 *     consumed by the donations wallet poller. Locked to one
 *     character ID via `EVE_SSO_DONATIONS_CHARACTER_ID` — wrong-character
 *     authorisations bounce with an error rather than upserting.
 *   - market flow: donor self-service. Each donor authorises their
 *     OWN character with `esi-markets.structure_markets.v1`. Stored
 *     encrypted in `eve_market_tokens`, keyed on character_id, bound
 *     to the authorising user. The callback refuses characters not
 *     already linked to the authorising user (account-takeover
 *     defence) and non-donor users (the gate that makes this a
 *     donor benefit).
 */
class EveSsoController extends Controller
{
    // Session keys — scoped out of the usual Laravel 'auth.*' namespace so
    // they can't collide with Laravel's session intents.
    private const SESSION_STATE = 'eve_sso.state';
    private const SESSION_VERIFIER = 'eve_sso.code_verifier';
    private const SESSION_FLOW = 'eve_sso.flow';

    private const FLOW_LOGIN = 'login';
    private const FLOW_SERVICE = 'service';
    private const FLOW_DONATIONS = 'donations';
    private const FLOW_MARKET = 'market';

    // ---------------------------------------------------------------------
    // Login flow — anyone, publicData scope
    // ---------------------------------------------------------------------

    public function redirect(Request $request): RedirectResponse
    {
        try {
            $sso = EveSsoClient::fromConfig();
        } catch (EveSsoException $e) {
            Log::warning('EVE SSO misconfigured', ['error' => $e->getMessage()]);

            return redirect()->route('home')
                ->with('error', 'EVE SSO is not configured on this server.');
        }

        $redirect = $sso->authorize(config('eve.sso.login_scopes'));

        $request->session()->put(self::SESSION_STATE, $redirect->state);
        $request->session()->put(self::SESSION_VERIFIER, $redirect->codeVerifier);
        $request->session()->put(self::SESSION_FLOW, self::FLOW_LOGIN);

        return redirect()->away($redirect->url);
    }

    // ---------------------------------------------------------------------
    // Service flow — admin-only, full scope set, tokens stored
    // ---------------------------------------------------------------------

    public function redirectAsService(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            // The route is `auth`-gated for the session check, but admin
            // gating is policy that lives on the User model — apply it
            // here so non-admin sessions can't trigger the elevated SSO
            // round-trip even with a forged URL.
            abort(403, 'Admin access required to authorise the service character.');
        }

        try {
            $sso = EveSsoClient::fromConfig();
        } catch (EveSsoException $e) {
            Log::warning('EVE SSO misconfigured (service flow)', ['error' => $e->getMessage()]);

            return redirect()->route('filament.admin.pages.dashboard')
                ->with('error', 'EVE SSO is not configured on this server.');
        }

        $scopes = config('eve.sso.service_scopes');
        if (empty($scopes)) {
            Log::warning('EVE service character flow attempted with empty EVE_SSO_SERVICE_SCOPES');

            return redirect()->route('filament.admin.pages.dashboard')
                ->with('error', 'EVE_SSO_SERVICE_SCOPES is empty — no scopes to request.');
        }

        $redirect = $sso->authorize($scopes);

        $request->session()->put(self::SESSION_STATE, $redirect->state);
        $request->session()->put(self::SESSION_VERIFIER, $redirect->codeVerifier);
        $request->session()->put(self::SESSION_FLOW, self::FLOW_SERVICE);
        // Audit hook for the upsert; lets us record who clicked Authorise
        // even though the callback runs in a fresh request.
        $request->session()->put('eve_sso.authorized_by_user_id', (int) $user->id);

        return redirect()->away($redirect->url);
    }

    // ---------------------------------------------------------------------
    // Donations flow — admin-only, single-character, wallet-read scope
    // ---------------------------------------------------------------------

    public function redirectAsDonations(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            abort(403, 'Admin access required to authorise the donations character.');
        }

        // Donations character must be configured before the admin can
        // start the flow — pointless to round-trip to CCP only to bounce
        // the resulting token because no character is locked in.
        $expectedCharacterId = config('eve.sso.donations.character_id');
        if (! is_int($expectedCharacterId) || $expectedCharacterId <= 0) {
            return redirect()->route('filament.admin.pages.dashboard')
                ->with('error', 'EVE_SSO_DONATIONS_CHARACTER_ID is not configured.');
        }

        try {
            $sso = EveSsoClient::fromConfig();
        } catch (EveSsoException $e) {
            Log::warning('EVE SSO misconfigured (donations flow)', ['error' => $e->getMessage()]);

            return redirect()->route('filament.admin.pages.dashboard')
                ->with('error', 'EVE SSO is not configured on this server.');
        }

        $scopes = config('eve.sso.donations.scopes');
        if (empty($scopes)) {
            Log::warning('EVE donations character flow attempted with empty EVE_SSO_DONATIONS_SCOPES');

            return redirect()->route('filament.admin.pages.dashboard')
                ->with('error', 'EVE_SSO_DONATIONS_SCOPES is empty — no scopes to request.');
        }

        $redirect = $sso->authorize($scopes);

        $request->session()->put(self::SESSION_STATE, $redirect->state);
        $request->session()->put(self::SESSION_VERIFIER, $redirect->codeVerifier);
        $request->session()->put(self::SESSION_FLOW, self::FLOW_DONATIONS);
        $request->session()->put('eve_sso.authorized_by_user_id', (int) $user->id);

        return redirect()->away($redirect->url);
    }

    // ---------------------------------------------------------------------
    // Market flow — donor self-service, per-user, character-linked
    // ---------------------------------------------------------------------

    public function redirectAsMarket(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            // The route is `auth`-gated but belt-and-braces.
            abort(403, 'Login required to authorise market data access.');
        }

        // Donor / admin gate. Non-donor non-admins clicking the button
        // would hit CCP, authorise, come back, and be bounced from the
        // finisher — wasted round-trip. Refuse up-front and surface a
        // friendly "become a donor" CTA instead. Admins bypass as
        // operators (matches the /account/settings UI gate and ADR-0005's
        // intersection rule applied to feature access).
        if (! ($user->isDonor() || $user->isAdmin())) {
            return redirect()->route('account.settings')
                ->with('error', 'Market data access is a donor benefit. Become a donor to enable it.');
        }

        try {
            $sso = EveSsoClient::fromConfig();
        } catch (EveSsoException $e) {
            Log::warning('EVE SSO misconfigured (market flow)', ['error' => $e->getMessage()]);

            return redirect()->route('account.settings')
                ->with('error', 'EVE SSO is not configured on this server.');
        }

        $scopes = config('eve.sso.market_scopes');
        if (empty($scopes)) {
            Log::warning('EVE market flow attempted with empty EVE_SSO_MARKET_SCOPES');

            return redirect()->route('account.settings')
                ->with('error', 'EVE_SSO_MARKET_SCOPES is empty — no scopes to request.');
        }

        $redirect = $sso->authorize($scopes);

        $request->session()->put(self::SESSION_STATE, $redirect->state);
        $request->session()->put(self::SESSION_VERIFIER, $redirect->codeVerifier);
        $request->session()->put(self::SESSION_FLOW, self::FLOW_MARKET);
        // Stash the authorising user ID so the finisher can enforce
        // `token.user_id == authorising_user_id` even though the
        // callback runs in a fresh request.
        $request->session()->put('eve_sso.authorized_by_user_id', (int) $user->id);

        return redirect()->away($redirect->url);
    }

    // ---------------------------------------------------------------------
    // Shared callback — dispatches by session-stashed flow marker
    // ---------------------------------------------------------------------

    public function callback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull(self::SESSION_FLOW, self::FLOW_LOGIN);
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $codeVerifier = $request->session()->pull(self::SESSION_VERIFIER);

        // User declined consent, or CCP surfaced an upstream error. Both
        // arrive as ?error=<code>&error_description=<msg>. Log the CCP
        // code but show the user a generic failure — we don't leak OAuth
        // internals into the UI.
        if ($request->has('error')) {
            Log::info('EVE SSO callback returned error', [
                'flow' => $flow,
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->bounceFromCallback($flow, 'EVE SSO login was cancelled or rejected.');
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code === '' || $state === '' || $expectedState === null || $codeVerifier === null) {
            return $this->bounceFromCallback($flow, 'EVE SSO callback is missing required fields.');
        }

        if (! hash_equals((string) $expectedState, $state)) {
            Log::warning('EVE SSO callback state mismatch', [
                'flow' => $flow,
                'ip' => $request->ip(),
            ]);

            return $this->bounceFromCallback($flow, 'EVE SSO login expired or was tampered with. Try again.');
        }

        try {
            $sso = EveSsoClient::fromConfig();
            $token = $sso->exchangeCode($code, (string) $codeVerifier);
        } catch (EveSsoException $e) {
            Log::warning('EVE SSO token exchange failed', [
                'flow' => $flow,
                'error' => $e->getMessage(),
            ]);

            return $this->bounceFromCallback($flow, 'EVE SSO login failed. Please try again.');
        }

        return match ($flow) {
            self::FLOW_SERVICE => $this->finishServiceFlow($request, $token),
            self::FLOW_DONATIONS => $this->finishDonationsFlow($request, $token),
            self::FLOW_MARKET => $this->finishMarketFlow($request, $token),
            default => $this->finishLoginFlow($request, $token),
        };
    }

    // ---------------------------------------------------------------------
    // Login-flow finisher: identity-only, no token storage
    // ---------------------------------------------------------------------

    private function finishLoginFlow(Request $request, EveSsoToken $token): RedirectResponse
    {
        $user = $this->upsertCharacterAndUser($token);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        Log::info('EVE SSO login', [
            'character_id' => $token->characterId,
            'character_name' => $token->characterName,
            'user_id' => $user->id,
        ]);

        // Default landing page after login depends on whether the user is
        // an admin. ADR-0002 § Admin gate: admins go to /admin (their
        // natural destination), everyone else lands on /. `intended()`
        // honours an explicit pre-login URL if one was stashed.
        $default = $user->canAccessPanel(Filament::getPanel('admin'))
            ? route('filament.admin.pages.dashboard')
            : route('home');

        return redirect()->intended($default);
    }

    // ---------------------------------------------------------------------
    // Service-flow finisher: store encrypted tokens, do NOT log the
    // current user in as the service character
    // ---------------------------------------------------------------------

    private function finishServiceFlow(Request $request, EveSsoToken $token): RedirectResponse
    {
        // Refuse a service-flow callback if the access token came back
        // without the elevated scopes we asked for — usually means the
        // user authorised a different character or revoked something
        // mid-flow. Storing a publicData-only token under the service
        // table would mislead callers into thinking they have ESI access.
        if (count($token->scopes) === 0) {
            Log::warning('EVE service character callback returned no scopes', [
                'character_id' => $token->characterId,
            ]);

            return redirect()->route('filament.admin.pages.dashboard')
                ->with('error', 'Service character authorisation returned no scopes. Try again.');
        }

        $authorizedBy = $request->session()->pull('eve_sso.authorized_by_user_id');
        $expiresAt = now()->addSeconds(max(0, $token->expiresIn));

        EveServiceToken::updateOrCreate(
            ['character_id' => $token->characterId],
            [
                'character_name' => $token->characterName,
                'scopes' => $token->scopes,
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'expires_at' => $expiresAt,
                'authorized_by_user_id' => is_int($authorizedBy) ? $authorizedBy : null,
            ],
        );

        Log::info('EVE service character authorised', [
            'character_id' => $token->characterId,
            'character_name' => $token->characterName,
            'scope_count' => count($token->scopes),
            'expires_at' => $expiresAt->toIso8601String(),
            'authorized_by_user_id' => $authorizedBy,
        ]);

        return redirect()->route('filament.admin.pages.eve-service-character')
            ->with('success', "Authorised {$token->characterName} for ESI service calls.");
    }

    // ---------------------------------------------------------------------
    // Donations-flow finisher: character-locked, single-scope, encrypted
    // token storage. Refuses wrong-character authorisations rather than
    // leaking a bearer token into the database.
    // ---------------------------------------------------------------------

    private function finishDonationsFlow(Request $request, EveSsoToken $token): RedirectResponse
    {
        $expectedCharacterId = config('eve.sso.donations.character_id');

        // Hard character lock. The whole point of this flow is to
        // authorise ONE specific in-game character (the one configured
        // to receive donations). Storing a token belonging to a
        // different character would mean the poller starts reading
        // someone else's wallet — a confidentiality + scope-leakage
        // bug, not just a UX bug.
        if (! is_int($expectedCharacterId) || $token->characterId !== $expectedCharacterId) {
            Log::warning('EVE donations callback returned wrong character', [
                'expected_character_id' => $expectedCharacterId,
                'received_character_id' => $token->characterId,
                'received_character_name' => $token->characterName,
            ]);

            return redirect()->route('filament.admin.pages.eve-donations')
                ->with('error', sprintf(
                    'Authorised character is %s (#%d), but donations character is locked to ID %s. ' .
                    'Log out of EVE SSO (https://login.eveonline.com) and re-authorise as the correct character.',
                    $token->characterName,
                    $token->characterId,
                    $expectedCharacterId === null ? '(unset)' : (string) $expectedCharacterId,
                ));
        }

        // Same defensive check as the service flow: an empty scope set
        // means CCP returned a token that can't actually call ESI on
        // our behalf. Storing it would mislead the poller.
        if (count($token->scopes) === 0) {
            Log::warning('EVE donations callback returned no scopes', [
                'character_id' => $token->characterId,
            ]);

            return redirect()->route('filament.admin.pages.eve-donations')
                ->with('error', 'Donations character authorisation returned no scopes. Try again.');
        }

        $authorizedBy = $request->session()->pull('eve_sso.authorized_by_user_id');
        $expiresAt = now()->addSeconds(max(0, $token->expiresIn));

        EveDonationsToken::updateOrCreate(
            ['character_id' => $token->characterId],
            [
                'character_name' => $token->characterName,
                'scopes' => $token->scopes,
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'expires_at' => $expiresAt,
                'authorized_by_user_id' => is_int($authorizedBy) ? $authorizedBy : null,
            ],
        );

        Log::info('EVE donations character authorised', [
            'character_id' => $token->characterId,
            'character_name' => $token->characterName,
            'scope_count' => count($token->scopes),
            'expires_at' => $expiresAt->toIso8601String(),
            'authorized_by_user_id' => $authorizedBy,
        ]);

        return redirect()->route('filament.admin.pages.eve-donations')
            ->with('success', "Authorised {$token->characterName} for donations wallet polling.");
    }

    // ---------------------------------------------------------------------
    // Market-flow finisher: donor self-service, per-user,
    // character-linkage-enforced. Two security gates:
    //
    //   1. Authorising user must still be a donor (same gate as the
    //      redirect; re-check in case the donation expired mid-flow).
    //   2. Callback character MUST already be linked to the
    //      authorising user (characters.user_id = user_id). Without
    //      this check, a donor could swap characters on CCP's side
    //      mid-flow and end up with a market token bound to THEIR
    //      AegisCore account but backed by a DIFFERENT EVE character —
    //      a cross-account confusion we do not want.
    //
    // ADR-0004 § Structure access is alliance/corp-gated — invariant.
    // ---------------------------------------------------------------------

    private function finishMarketFlow(Request $request, EveSsoToken $token): RedirectResponse
    {
        $authorizedBy = $request->session()->pull('eve_sso.authorized_by_user_id');
        if (! is_int($authorizedBy) || $authorizedBy <= 0) {
            Log::warning('EVE market callback missing authorizing user id');

            return redirect()->route('account.settings')
                ->with('error', 'Market authorisation session expired. Try again.');
        }

        /** @var User|null $user */
        $user = User::find($authorizedBy);
        if ($user === null) {
            Log::warning('EVE market callback: authorising user vanished', [
                'authorized_by_user_id' => $authorizedBy,
            ]);

            return redirect()->route('account.settings')
                ->with('error', 'Market authorisation failed — authorising user not found.');
        }

        if (! ($user->isDonor() || $user->isAdmin())) {
            // Donor status lapsed between redirect and callback (and the
            // user is not an admin). Refuse to store the token rather
            // than silently granting access the donor no longer pays
            // for. Admins bypass as operators — see redirectAsMarket()
            // for the same gate logic.
            Log::info('EVE market callback: authorising user is neither donor nor admin', [
                'user_id' => $user->id,
                'character_id' => $token->characterId,
            ]);

            return redirect()->route('account.settings')
                ->with('error', 'Market data access is a donor benefit. Become a donor to enable it.');
        }

        // Character-linkage gate. The authorised EVE character MUST
        // already be one of this user's linked characters (populated
        // via the login flow). Otherwise an attacker with a partial
        // session hijack could authorise ANY EVE character they
        // control and have that character's ACLs used to pull market
        // data under the victim's AegisCore account — an authorisation-
        // confusion attack.
        $characterLinked = Character::query()
            ->where('user_id', $user->id)
            ->where('character_id', $token->characterId)
            ->exists();
        if (! $characterLinked) {
            Log::warning('EVE market callback: unlinked character', [
                'user_id' => $user->id,
                'character_id' => $token->characterId,
                'character_name' => $token->characterName,
            ]);

            return redirect()->route('account.settings')
                ->with('error', sprintf(
                    'Authorised character %s is not linked to your account. '.
                    'Log in with this character via EVE SSO first, then re-try authorising market data.',
                    $token->characterName,
                ));
        }

        // Scope sanity check. Same defensive check as the other flows:
        // an empty scope set or one missing the functional scope would
        // mean we stored a token the poller can't actually use. Require
        // the market scope explicitly — without it the whole flow is
        // a no-op.
        if (count($token->scopes) === 0 || ! in_array('esi-markets.structure_markets.v1', $token->scopes, true)) {
            Log::warning('EVE market callback returned wrong / missing scopes', [
                'user_id' => $user->id,
                'character_id' => $token->characterId,
                'scopes' => $token->scopes,
            ]);

            return redirect()->route('account.settings')
                ->with('error', 'Market authorisation did not grant the required scope (esi-markets.structure_markets.v1). Try again.');
        }

        $expiresAt = now()->addSeconds(max(0, $token->expiresIn));

        EveMarketToken::updateOrCreate(
            ['character_id' => $token->characterId],
            [
                'user_id' => $user->id,
                'character_name' => $token->characterName,
                'scopes' => $token->scopes,
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'expires_at' => $expiresAt,
            ],
        );

        Log::info('EVE market character authorised', [
            'user_id' => $user->id,
            'character_id' => $token->characterId,
            'character_name' => $token->characterName,
            'scope_count' => count($token->scopes),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return redirect()->route('account.settings')
            ->with('success', "Authorised {$token->characterName} for market-data access.");
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Idempotent upsert of Character + linked User.
     *
     * One transaction so a half-upserted character (row exists, user_id
     * null) can't leave state that looks logged-out-but-known. `User`
     * row gets a synthetic email + random password because the phase-1
     * `users` schema has those NOT NULL; neither is ever used for auth
     * on SSO-created accounts.
     */
    private function upsertCharacterAndUser(EveSsoToken $token): User
    {
        return DB::transaction(function () use ($token) {
            /** @var Character $character */
            $character = Character::firstOrNew(['character_id' => $token->characterId]);
            $character->name = $token->characterName;

            if ($character->user_id === null) {
                $user = User::create([
                    'name' => $token->characterName,
                    // Synthetic — we never send to or authenticate against
                    // this address. Suffix is fixed so ops can identify
                    // SSO-provisioned accounts in a user list.
                    'email' => $token->characterId.'@eve-sso.aegiscore.local',
                    // Unused; hashed so the column can't be used to log in
                    // even if leaked. `Str::random(64)` exceeds bcrypt's
                    // silent-truncation limit so it stays entropy-rich.
                    'password' => Hash::make(Str::random(64)),
                ]);
                $character->user_id = $user->id;
            } else {
                // Refresh User.name to current EVE name on every login.
                // Cheap, and keeps the admin user list aligned with in-game
                // names if someone renames.
                /** @var User $user */
                $user = User::findOrFail($character->user_id);
                if ($user->name !== $token->characterName) {
                    $user->name = $token->characterName;
                    $user->save();
                }
            }

            $character->save();

            return $user;
        });
    }

    /**
     * Pick an honest landing place for callback failures based on the
     * flow that was in progress. Login failures bounce to the panel
     * login form (where the inline error still surfaces). Service-flow
     * + donations-flow failures land back on their respective admin
     * pages where the user clicked Authorise.
     */
    private function bounceFromCallback(string $flow, string $message): RedirectResponse
    {
        return match ($flow) {
            self::FLOW_SERVICE => redirect()
                ->route('filament.admin.pages.eve-service-character')
                ->with('error', $message),
            self::FLOW_DONATIONS => redirect()
                ->route('filament.admin.pages.eve-donations')
                ->with('error', $message),
            self::FLOW_MARKET => redirect()
                ->route('account.settings')
                ->with('error', $message),
            default => redirect()
                ->route('filament.admin.auth.login')
                ->withErrors(['email' => $message]),
        };
    }
}
