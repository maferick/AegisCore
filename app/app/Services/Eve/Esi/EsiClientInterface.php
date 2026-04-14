<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use App\Providers\AppServiceProvider;

/**
 * Transport contract for esi.evetech.net GETs.
 *
 * Implementations:
 *
 *   - {@see EsiClient} — thin HTTP transport with conditional-GET (ETag /
 *     Last-Modified) + reactive rate-limit throttling. No payload cache.
 *   - {@see CachedEsiClient} — decorator that adds a payload+freshness cache
 *     on top of the transport: serves fresh bodies without network, replays
 *     cached bodies on 304, drives refresh timing from ESI's `Expires`
 *     header, single-flights concurrent fetches, and returns stale data on
 *     transient upstream failures.
 *
 * The container resolves this interface to `CachedEsiClient` by default so
 * all callers get the cached transport transparently. See
 * {@see AppServiceProvider::register()}.
 */
interface EsiClientInterface
{
    /**
     * GET an ESI endpoint.
     *
     * `$path` is relative to the configured base URL (e.g. `/characters/123/`);
     * absolute URLs are passed through.
     *
     * @param  array<string, scalar|array<int, scalar>>  $query
     *
     * @throws EsiRateLimitException on 429 / 420 (also raised pre-flight when
     *                               a known group is in cooldown longer than
     *                               `rate_limit_max_wait_seconds`).
     * @throws EsiException on any other 4xx / 5xx
     */
    public function get(string $path, array $query = [], ?string $bearerToken = null): EsiResponse;
}
