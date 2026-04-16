<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use App\Providers\AppServiceProvider;

/**
 * Transport contract for esi.evetech.net.
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
     * absolute URLs are passed through so callers hitting the new unversioned
     * ESI at `https://esi.evetech.net` (no `/latest/`) don't need a second
     * client instance.
     *
     * `$headers` is an optional map of extra request headers. The main caller
     * today is the standings fetcher, which needs `X-Compatibility-Date` on
     * every new-ESI request. `Authorization`, `If-None-Match`, and
     * `If-Modified-Since` are reserved — the transport owns those and will
     * overwrite any caller-supplied values to keep conditional-GET correct.
     * Headers are factored into the payload cache key so a compat-date bump
     * doesn't replay a stale body.
     *
     * `$forceRefresh` disables the conditional-GET for this one call. Used by
     * {@see CachedEsiClient} to recover from cache drift — when the payload
     * cache has been evicted but the upstream validator cache still has an
     * ETag, a normal call would send `If-None-Match`, get a 304, and leave
     * the caller with `body: null` (no payload to replay). The decorator
     * detects that drift, retries with `forceRefresh: true`, and the inner
     * transport omits conditional headers so CCP sends a full body that can
     * be cached again. Individual callers normally don't set this flag.
     *
     * @param  array<string, scalar|array<int, scalar>>  $query
     * @param  array<string, string>  $headers
     *
     * @throws EsiRateLimitException on 429 / 420 (also raised pre-flight when
     *                               a known group is in cooldown longer than
     *                               `rate_limit_max_wait_seconds`).
     * @throws EsiException on any other 4xx / 5xx
     */
    public function get(
        string $path,
        array $query = [],
        ?string $bearerToken = null,
        array $headers = [],
        bool $forceRefresh = false,
    ): EsiResponse;

    /**
     * POST to an ESI endpoint (e.g. /universe/names/).
     *
     * Same rate limiting, User-Agent, and error handling as GET.
     * No conditional-GET caching (POST responses aren't cacheable).
     *
     * @param  array<int|string, mixed>  $body  JSON-serializable request body.
     * @param  array<string, string>  $headers
     *
     * @throws EsiRateLimitException on 429 / 420
     * @throws EsiException on any other 4xx / 5xx
     */
    public function post(
        string $path,
        array $body = [],
        ?string $bearerToken = null,
        array $headers = [],
    ): EsiResponse;
}
