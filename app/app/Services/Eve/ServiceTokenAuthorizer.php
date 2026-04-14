<?php

declare(strict_types=1);

namespace App\Services\Eve;

use App\Domains\UsersCharacters\Models\EveServiceToken;
use App\Services\Eve\Sso\EveSsoClient;
use App\Services\Eve\Sso\EveSsoException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Return a usable (fresh) access token for an EveServiceToken row.
 *
 * Twin of MarketTokenAuthorizer for the admin-authorised service
 * character. The Python market poller owns sustained polling (ADR-0004
 * § Live polling), but the Laravel plane needs fresh service-token
 * access for interactive flows as well:
 *
 *   - `/admin/market-watched-locations` structure picker: admins
 *     search for Upwell structures by name using the service
 *     character's `/characters/{id}/search/` endpoint. Discovery is
 *     ACL-gated — ESI only returns structures the character has
 *     docking rights at — so we have to hit ESI live inside an
 *     HTTP request.
 *
 *   - Any future admin-side ESI round-trip that isn't the poller.
 *
 * Concurrency matches MarketTokenAuthorizer: row-level `SELECT ...
 * FOR UPDATE` coordinates with the Python poller so Python and
 * Laravel can't both refresh the same row in parallel and lose the
 * loser's refresh_token to a CCP-side single-use invalidation.
 *
 * Refresh happens when `expires_at <= now + 60s` — matches the
 * Python poller's 60s future-bias so an in-flight ESI call during
 * the window doesn't 401 mid-flight.
 */
final class ServiceTokenAuthorizer
{
    public function __construct(
        private readonly EveSsoClient $sso,
    ) {}

    /**
     * Return an access_token safe to send to ESI as a Bearer,
     * refreshing in-place if the stored token is stale.
     *
     * @throws RuntimeException on refresh failure — surfaces a
     *         user-facing "please re-authorise service character"
     *         message.
     */
    public function freshAccessToken(EveServiceToken $token): string
    {
        // Transaction covers read-under-lock → CCP round-trip → UPDATE.
        // See MarketTokenAuthorizer for the full rationale — the two
        // authorizers deliberately mirror each other so a bug fix in
        // one side can be applied to the other without spelunking.
        return DB::transaction(function () use ($token) {
            /** @var EveServiceToken|null $locked */
            $locked = EveServiceToken::query()
                ->lockForUpdate()
                ->find($token->id);
            if ($locked === null) {
                throw new RuntimeException(
                    'Service token vanished between fetch and lock — re-authorise the service character.'
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
                Log::warning('service token refresh failed', [
                    'token_id' => $locked->id,
                    'character_id' => $locked->character_id,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    'Could not refresh service access — re-authorise the service character on /admin.',
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

            Log::info('service token refreshed (laravel plane)', [
                'token_id' => $locked->id,
                'character_id' => $locked->character_id,
            ]);

            return $refreshed->accessToken;
        });
    }
}
