<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\UsersCharacters\Models\Character;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Eve\Sso\EveSsoClient;
use App\Services\Eve\Sso\EveSsoException;
use App\Services\Eve\Sso\EveSsoToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * /auth/eve — EVE SSO login entry + callback.
 *
 * Two actions:
 *
 *   GET  /auth/eve            → `redirect()`
 *       Builds the /v2/oauth/authorize URL, stashes the PKCE state +
 *       code-verifier in session, sends the user to login.eveonline.com.
 *
 *   GET  /auth/eve/callback   → `callback()`
 *       Validates state, exchanges the `code` for a token, decodes the
 *       JWT identity, upserts Character + linked User, logs in the
 *       session, redirects to the intended URL (defaulting to /admin).
 *
 * ADR-0002 § Token kinds: the login token is discarded after identity
 * extraction. Phase 1 keeps no refresh token, no encrypted-at-rest state.
 */
class EveSsoController extends Controller
{
    // Session keys — scoped out of the usual Laravel 'auth.*' namespace so
    // they can't collide with Laravel's session intents.
    private const SESSION_STATE = 'eve_sso.state';
    private const SESSION_VERIFIER = 'eve_sso.code_verifier';

    public function redirect(Request $request): RedirectResponse
    {
        try {
            $sso = EveSsoClient::fromConfig();
        } catch (EveSsoException $e) {
            Log::warning('EVE SSO misconfigured', ['error' => $e->getMessage()]);

            return redirect()->route('filament.admin.auth.login')
                ->withErrors(['email' => 'EVE SSO is not configured on this server.']);
        }

        $redirect = $sso->authorize(config('eve.sso.login_scopes'));

        $request->session()->put(self::SESSION_STATE, $redirect->state);
        $request->session()->put(self::SESSION_VERIFIER, $redirect->codeVerifier);

        return redirect()->away($redirect->url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $codeVerifier = $request->session()->pull(self::SESSION_VERIFIER);

        // User declined consent, or CCP surfaced an upstream error. Both
        // arrive as ?error=<code>&error_description=<msg>. Log the CCP
        // code but show the user a generic failure — we don't leak OAuth
        // internals into the UI.
        if ($request->has('error')) {
            Log::info('EVE SSO callback returned error', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->backToLogin('EVE SSO login was cancelled or rejected.');
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code === '' || $state === '' || $expectedState === null || $codeVerifier === null) {
            return $this->backToLogin('EVE SSO callback is missing required fields.');
        }

        // Constant-time state compare — we stash a random-generated value
        // we know about, so this is really about defence-in-depth (no
        // early-return leak if a tampered state-prefix matches).
        if (! hash_equals((string) $expectedState, $state)) {
            Log::warning('EVE SSO callback state mismatch', [
                'ip' => $request->ip(),
            ]);

            return $this->backToLogin('EVE SSO login expired or was tampered with. Try again.');
        }

        try {
            $sso = EveSsoClient::fromConfig();
            $token = $sso->exchangeCode($code, (string) $codeVerifier);
        } catch (EveSsoException $e) {
            Log::warning('EVE SSO token exchange failed', ['error' => $e->getMessage()]);

            return $this->backToLogin('EVE SSO login failed. Please try again.');
        }

        $user = $this->upsertCharacterAndUser($token);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        Log::info('EVE SSO login', [
            'character_id' => $token->characterId,
            'character_name' => $token->characterName,
            'user_id' => $user->id,
        ]);

        return redirect()->intended(route('filament.admin.pages.dashboard'));
    }

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

    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('filament.admin.auth.login')
            ->withErrors(['email' => $message]);
    }
}
