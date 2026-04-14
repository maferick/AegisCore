<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\EveDonationsToken;
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
 * /auth/eve — EVE SSO entry + callback for all three flows.
 *
 * Three flows share the same callback URL so the registered CCP app
 * only needs one redirect URI on file:
 *
 *   GET  /auth/eve                       → `redirect()`         (login, publicData)
 *   GET  /auth/eve/service-redirect      → `redirectAsService()` (admin-only,
 *                                                                 elevated scopes)
 *   GET  /auth/eve/donations-redirect    → `redirectAsDonations()` (admin-only,
 *                                                                   wallet-read,
 *                                                                   character-locked)
 *   GET  /auth/eve/callback              → `callback()`         (dispatches by
 *                                                                session marker)
 *
 * The session stashes `eve_sso.flow ∈ {'login', 'service', 'donations'}`
 * alongside the PKCE state + verifier when redirecting; the callback
 * reads that marker to pick the right handler. Bare callbacks (no
 * marker, e.g. someone pasting the URL) fall through to the safer
 * login handler.
 *
 * ADR-0002 § Token kinds for the policy split:
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
            default => redirect()
                ->route('filament.admin.auth.login')
                ->withErrors(['email' => $message]),
        };
    }
}
