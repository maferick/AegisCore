<?php

declare(strict_types=1);

namespace App\Services\Eve\Sso;

/**
 * Result of a successful `/v2/oauth/token` exchange.
 *
 * Carries both the raw OAuth token fields and the identity claims we
 * decoded out of the JWT (so callers don't repeat the decode step).
 *
 * Phase 1 discards every field except `$characterId` + `$characterName`
 * once the User + Character rows are upserted — login tokens are not
 * stored. Phase-2 service-character tokens will use a different DTO
 * when token storage lands.
 *
 * @property-read string[] $scopes Scopes actually granted (JWT `scp` claim,
 *                                 may be narrower than requested).
 */
final class EveSsoToken
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
