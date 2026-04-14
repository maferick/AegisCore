<?php

declare(strict_types=1);

namespace App\Services\Eve;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Services\Eve\Sso\EveSsoClient;
use App\Services\Eve\Sso\EveSsoException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Return a usable (fresh) access token for a donor's EveMarketToken row.
 *
 * The Python market poller owns sustained polling (ADR-0004 § Live
 * polling), but the Laravel plane ALSO needs fresh tokens for the
 * interactive `/account/settings` picker — the donor's "list
 * structures I have access to" search round-trips through ESI
 * during an HTTP request.
 *
 * Concurrency story: Python and Laravel might both try to refresh
 * the same row. We coordinate via MariaDB row-level locks:
 *
 *   BEGIN;
 *   SELECT * FROM eve_market_tokens WHERE id = ? FOR UPDATE;
 *   -- if fresh → return access_token, ROLLBACK (release lock)
 *   -- if stale → POST /v2/oauth/token, UPDATE row, COMMIT
 *
 * Whichever process grabs the lock first refreshes; the other
 * picks up the freshly-rotated token when the lock releases. No
 * double-rotation (which would invalidate the stored refresh_token
 * and lock us out until a manual re-auth).
 *
 * This is the pattern ADR-0002 § phase-2 #12 flagged: row-level
 * advisory lock when a second sustained polling caller appears.
 * The Python side already uses `SELECT ... FOR UPDATE` via
 * `python/market_poller/auth.py`; this is the Laravel twin.
 *
 * NOTE: we DO NOT wrap an ESI call inside the transaction — that
 * would hold the row lock for seconds. The pattern is:
 *   1. Open tx, SELECT FOR UPDATE.
 *   2. Refresh CCP-side (network, ~hundreds of ms).
 *   3. UPDATE row. COMMIT.
 *   4. RETURN the new access_token. THEN do the ESI call.
 * The lock is only held during the refresh network round-trip,
 * which is unavoidable — if we released before UPDATEing, two
 * processes could each get a fresh token and only one UPDATE would
 * win, leaving the loser's refresh_token stale.
 */
final class MarketTokenAuthorizer
{
    public function __construct(
        private readonly EveSsoClient $sso,
    ) {}

    /**
     * Return an access_token safe to send to ESI as a Bearer,
     * refreshing in-place if the stored token is stale. The row's
     * access_token / refresh_token / expires_at / scopes are
     * updated transactionally.
     *
     * Refresh happens when `expires_at <= now + 60s` — same
     * 60-second future-bias the Python poller uses. Inside that
     * window, an in-flight request that takes a moment to fly to
     * ESI won't 401 mid-flight.
     *
     * Caller MUST pass an EveMarketToken belonging to the currently
     * authenticated user. We do not re-check here (the interactive
     * caller owns that policy); the underlying DB row's user_id is
     * the ground truth for the Python poller's invariant check.
     *
     * @throws RuntimeException on refresh failure — surfaces a
     *         user-facing "please re-authorise" message.
     */
    public function freshAccessToken(EveMarketToken $token): string
    {
        // Transaction covers read-under-lock → CCP round-trip → UPDATE.
        return DB::transaction(function () use ($token) {
            /** @var EveMarketToken|null $locked */
            $locked = EveMarketToken::query()
                ->lockForUpdate()
                ->find($token->id);
            if ($locked === null) {
                throw new RuntimeException(
                    'Market token vanished between fetch and lock — re-authorise to proceed.'
                );
            }

            if ($locked->isAccessTokenFresh()) {
                // Another process (Python poller, another HTTP
                // request) already refreshed while we were waiting on
                // the lock. Return the fresh token from the row.
                return (string) $locked->access_token;
            }

            try {
                $refreshed = $this->sso->refreshAccessToken($locked->refresh_token);
            } catch (EveSsoException $e) {
                Log::warning('market token refresh failed', [
                    'token_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'character_id' => $locked->character_id,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    'Could not refresh market access — re-authorise your character on /account/settings.',
                    previous: $e,
                );
            }

            $locked->update([
                'access_token' => $refreshed->accessToken,
                'refresh_token' => $refreshed->refreshToken,
                'expires_at' => now()->addSeconds(max(0, $refreshed->expiresIn)),
                'scopes' => $refreshed->scopes,
                'character_name' => $refreshed->characterName,
            ]);

            Log::info('market token refreshed (laravel plane)', [
                'token_id' => $locked->id,
                'user_id' => $locked->user_id,
                'character_id' => $locked->character_id,
            ]);

            return $refreshed->accessToken;
        });
    }
}
