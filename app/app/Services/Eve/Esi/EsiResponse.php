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
 *                     The raw {@see EsiClient} transport leaves `$body` null
 *                     on 304; the {@see CachedEsiClient} decorator replays
 *                     the cached body into `$body` while keeping this flag
 *                     true, so callers who want a "nothing changed since last
 *                     poll" short-circuit (e.g. wallet pollers) still have
 *                     it, and callers who want the body now have that too.
 *  - `$rateLimit`   — raw `X-Ratelimit-*` header values; useful for metrics
 *                     + structured logs but not load-bearing for retries.
 *  - `$stale`       — set by {@see CachedEsiClient} when it falls back to a
 *                     cached body after a transient upstream failure (5xx /
 *                     timeout) within the configured stale-if-error window.
 *                     Always false when coming off the wire.
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
        public readonly bool $stale = false,
    ) {}
}
