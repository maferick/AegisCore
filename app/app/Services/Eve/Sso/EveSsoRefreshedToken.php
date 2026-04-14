<?php

declare(strict_types=1);

namespace App\Services\Eve\Sso;

/**
 * Result of a successful `/v2/oauth/token` refresh exchange.
 *
 * Shape-identical to {@see EveSsoToken} (same OAuth fields + same JWT
 * claims) but kept as a separate type so that callers can be explicit
 * about provenance — "this token came from a refresh, not the initial
 * code exchange".
 *
 * IMPORTANT — token rotation: EVE SSO v2 returns a NEW `refresh_token`
 * on every refresh call. Callers MUST persist `$refreshToken` even
 * when it looks unchanged; failing to do so eventually invalidates the
 * stored credential and we lose ESI access until a manual re-auth.
 * See {@see EveSsoClient::refreshAccessToken()}.
 *
 * @property-read string[] $scopes Scopes actually granted on this refresh
 *                                 (JWT `scp` claim — can drift if the
 *                                 user revoked one in their EVE account
 *                                 settings since the previous refresh).
 */
final class EveSsoRefreshedToken
{
    /**
     * @param string[] $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
        public readonly int $characterId,
        public readonly string $characterName,
        public readonly array $scopes,
    ) {}
}
