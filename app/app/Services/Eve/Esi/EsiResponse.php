<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

/**
 * Normalised result returned by `EsiClient::get()` (and friends later).
 *
 * Surfaces both the decoded body and the bits of ESI-specific metadata a
 * caller most often needs:
 *
 *  - `$notModified` — true when ESI returned 304 (ETag / Last-Modified hit).
 *                     In that case `$body` is whatever our conditional-GET
 *                     cache had before, or an empty array if this was a
 *                     cold call. Callers MUST check this flag before using
 *                     `$body` — we do not replay cache bodies, we just
 *                     signal freshness.
 *  - `$rateLimit`   — raw `X-Ratelimit-*` header values; useful for metrics
 *                     + structured logs but not load-bearing for retries.
 *
 * The shape is intentionally small. Phase 1 callers are synchronous ("did
 * this token grant the scopes we think it did") and want a plain decoded
 * body; the expanded surface (paginated iterators, typed DTOs) lands
 * alongside the Python polling plane.
 */
final class EsiResponse
{
    /**
     * @param array<int|string, mixed>|null $body
     * @param array<string, string>         $rateLimit
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
        public readonly bool $notModified,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
        public readonly ?string $expires,
        public readonly array $rateLimit,
    ) {}
}
