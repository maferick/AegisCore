<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for esi.evetech.net.
 *
 * Scope (see ADR-0002 § ESI client):
 *
 *   - Sends the CCP-required `User-Agent` on every call.
 *   - Attaches `Authorization: Bearer <token>` when the caller supplies one.
 *   - Per-URL conditional GET: stores `ETag` + `Last-Modified` in the cache
 *     store, auto-attaches `If-None-Match` / `If-Modified-Since` on the next
 *     request. 3XX responses cost half the tokens of 2XX on ESI's published
 *     rate-limit math, so repeat fetches get noticeably cheaper.
 *   - Reactive rate-limit throttle via {@see EsiRateLimiter}: pre-flight
 *     blocks (or throws) when a known group is in 429 cooldown, running
 *     below `safety_margin` tokens, or when the global error budget is
 *     about to trip 420. State is reseeded from `X-Ratelimit-*` and
 *     `X-ESI-Error-Limit-*` headers on every response.
 *   - On 429 / 420: tells the limiter to back the group off, then throws
 *     `EsiRateLimitException` carrying `Retry-After`. Callers decide
 *     whether to `release($seconds)` (Horizon) or bubble.
 *   - Logs `X-Ratelimit-*` / `X-ESI-Error-Limit-*` on every response
 *     (debug channel).
 *
 * Still NOT in scope:
 *
 *   - OpenAPI-spec-derived pre-flight limit map (the limiter learns each
 *     group's window from the first response instead — cheaper, no
 *     deploy-time spec ingestion).
 *   - Token refresh — login tokens aren't stored in phase 1.
 *   - Pagination helpers.
 *
 * Add those when a real caller demands them; don't speculate.
 */
final class EsiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly int $timeoutSeconds,
        private readonly string $cacheStore,
        private readonly int $cacheTtlSeconds,
        private readonly EsiRateLimiter $rateLimiter,
        /** Maximum seconds we'll sleep synchronously when pre-flight says wait. */
        private readonly int $maxWaitSeconds,
    ) {}

    /**
     * Build a client from `config('eve.esi')`. Lazy callers use this; tests
     * construct directly with overrides.
     */
    public static function fromConfig(): self
    {
        $cfg = config('eve.esi');

        return new self(
            baseUrl: (string) ($cfg['base_url'] ?? 'https://esi.evetech.net/latest'),
            userAgent: (string) ($cfg['user_agent'] ?? 'AegisCore/0.1 (+ops@example.com)'),
            timeoutSeconds: (int) ($cfg['timeout_seconds'] ?? 10),
            cacheStore: (string) ($cfg['cache_store'] ?? 'redis'),
            cacheTtlSeconds: (int) ($cfg['cache_ttl_seconds'] ?? 86400),
            rateLimiter: EsiRateLimiter::fromConfig(),
            maxWaitSeconds: (int) ($cfg['rate_limit_max_wait_seconds'] ?? 5),
        );
    }

    /**
     * GET an ESI endpoint.
     *
     * `$path` is relative to the configured base URL (e.g. `/characters/123/`);
     * absolute URLs are passed through so callers can hit non-default ESI
     * hosts without reconfiguring the client.
     *
     * @param  array<string, scalar|array<int, scalar>>  $query
     *
     * @throws EsiRateLimitException on 429 / 420 (also raised pre-flight when
     *                               a known group is in cooldown longer than
     *                               `rate_limit_max_wait_seconds`).
     * @throws EsiException on any other 4xx / 5xx
     */
    public function get(string $path, array $query = [], ?string $bearerToken = null): EsiResponse
    {
        $url = $this->resolveUrl($path);

        // Pre-flight: ask the limiter how long we should wait. If it's a
        // sleep we can absorb in-process, do so; otherwise bubble as a
        // rate-limit exception so Horizon callers can `release($s)`.
        $this->awaitRateLimit($url);

        $cacheKey = $this->cacheKeyFor($url, $query);
        $validators = $this->loadValidators($cacheKey);

        $request = Http::withUserAgent($this->userAgent)
            ->timeout($this->timeoutSeconds)
            ->acceptJson();

        if ($bearerToken !== null) {
            $request = $request->withToken($bearerToken);
        }

        $conditionalHeaders = [];
        if ($validators['etag'] !== null) {
            $conditionalHeaders['If-None-Match'] = $validators['etag'];
        }
        if ($validators['last_modified'] !== null) {
            $conditionalHeaders['If-Modified-Since'] = $validators['last_modified'];
        }
        if ($conditionalHeaders !== []) {
            $request = $request->withHeaders($conditionalHeaders);
        }

        $response = $request->get($url, $query);

        $this->logRateLimit($url, $response);

        return $this->handleResponse($url, $cacheKey, $response);
    }

    /**
     * Block (or throw) until the rate-limiter says this URL can go.
     *
     * Sleeps for short waits (under `maxWaitSeconds`) — useful for ad-hoc
     * controller calls where the burst is small. Longer holds throw
     * `EsiRateLimitException` carrying the wait time, so Horizon jobs can
     * `release($seconds)` instead of pinning a worker.
     */
    private function awaitRateLimit(string $url): void
    {
        $wait = $this->rateLimiter->preflight($url);
        if ($wait <= 0.0) {
            return;
        }

        $waitSeconds = (int) ceil($wait);

        if ($waitSeconds > $this->maxWaitSeconds) {
            throw new EsiRateLimitException(
                message: "ESI rate-limit cooldown active ({$waitSeconds}s > max wait {$this->maxWaitSeconds}s)",
                retryAfter: $waitSeconds,
                status: 429,
                responseBody: '',
                url: $url,
            );
        }

        // PHP `sleep()` only takes whole seconds. Sub-second tail isn't
        // worth chasing — the limiter is reactive to real headers and the
        // safety margin already accounts for clock skew between us and
        // CCP's edge.
        Log::debug('esi rate-limit pre-flight wait', [
            'url' => $url,
            'wait_seconds' => $waitSeconds,
        ]);
        sleep($waitSeconds);
    }

    // ----------------------------------------------------------------------
    // internals
    // ----------------------------------------------------------------------

    private function resolveUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array{etag: string|null, last_modified: string|null}
     */
    private function loadValidators(string $cacheKey): array
    {
        /** @var array{etag?: string, last_modified?: string}|null $stored */
        $stored = Cache::store($this->cacheStore)->get($cacheKey);

        return [
            'etag' => $stored['etag'] ?? null,
            'last_modified' => $stored['last_modified'] ?? null,
        ];
    }

    private function handleResponse(string $url, string $cacheKey, Response $response): EsiResponse
    {
        $status = $response->status();
        $rateLimit = $this->extractRateLimitHeaders($response);

        if ($status === 429 || $status === 420) {
            $retryAfter = $this->retryAfterSeconds($response);
            // Tell the limiter so the next pre-flight on this URL (or any
            // URL in the same group) waits without us bouncing 429 off CCP.
            $this->rateLimiter->backoff($url, $rateLimit['X-Ratelimit-Group'] ?? null, $retryAfter);

            throw new EsiRateLimitException(
                message: "ESI rate-limited: HTTP {$status} on {$url}",
                retryAfter: $retryAfter,
                status: $status,
                responseBody: $response->body(),
                url: $url,
            );
        }

        if ($status === 304) {
            // Conditional-GET cache hit. Body is empty on the wire; caller
            // MUST check `notModified` before using the body field.
            $esiResponse = new EsiResponse(
                status: 304,
                body: null,
                notModified: true,
                etag: $response->header('ETag') ?: null,
                lastModified: $response->header('Last-Modified') ?: null,
                expires: $response->header('Expires') ?: null,
                rateLimit: $rateLimit,
            );
            $this->rateLimiter->record($url, $esiResponse);

            return $esiResponse;
        }

        if ($status >= 400) {
            // 4xx still carries rate-limit headers — record so the limiter
            // sees the (smaller) remaining budget after this errored call.
            $errorResponse = new EsiResponse(
                status: $status,
                body: null,
                notModified: false,
                etag: null,
                lastModified: null,
                expires: null,
                rateLimit: $rateLimit,
            );
            $this->rateLimiter->record($url, $errorResponse);

            throw new EsiException(
                message: "ESI error: HTTP {$status} on {$url}",
                status: $status,
                responseBody: $response->body(),
                url: $url,
            );
        }

        $etag = $response->header('ETag') ?: null;
        $lastModified = $response->header('Last-Modified') ?: null;
        $this->storeValidators($cacheKey, $etag, $lastModified);

        $esiResponse = new EsiResponse(
            status: $status,
            body: $response->json(),
            notModified: false,
            etag: $etag,
            lastModified: $lastModified,
            expires: $response->header('Expires') ?: null,
            rateLimit: $rateLimit,
        );
        $this->rateLimiter->record($url, $esiResponse);

        return $esiResponse;
    }

    private function storeValidators(string $cacheKey, ?string $etag, ?string $lastModified): void
    {
        if ($etag === null && $lastModified === null) {
            return;
        }

        Cache::store($this->cacheStore)->put(
            $cacheKey,
            array_filter([
                'etag' => $etag,
                'last_modified' => $lastModified,
            ]),
            $this->cacheTtlSeconds,
        );
    }

    /**
     * @param  array<string, scalar|array<int, scalar>>  $query
     */
    private function cacheKeyFor(string $url, array $query): string
    {
        // Include the query in the key — same path with different query
        // params is a different cacheable resource from ESI's perspective.
        ksort($query);
        $canonical = $url.'?'.http_build_query($query);

        return 'esi:cond:'.hash('sha256', $canonical);
    }

    /**
     * @return array<string, string>
     *
     * Captures both families of ESI throttle headers. Per CCP's best-practices
     * doc they're mutually exclusive on any single response: the newer
     * `X-Ratelimit-*` bucket headers identify a per-route-group token budget,
     * while the legacy `X-ESI-Error-Limit-*` headers describe a single global
     * error budget (100 errors / fixed minute) whose overflow trips 420. We
     * record whichever the response carries and let `EsiRateLimiter` branch.
     */
    private function extractRateLimitHeaders(Response $response): array
    {
        $headers = [];
        $names = [
            // Bucket-based (sliding window per group).
            'X-Ratelimit-Group',
            'X-Ratelimit-Limit',
            'X-Ratelimit-Remaining',
            'X-Ratelimit-Used',
            // Error-based (global fixed-window error budget).
            'X-ESI-Error-Limit-Remain',
            'X-ESI-Error-Limit-Reset',
        ];
        foreach ($names as $name) {
            $value = $response->header($name);
            if ($value !== '' && $value !== null) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    private function retryAfterSeconds(Response $response): int
    {
        $value = $response->header('Retry-After');
        if ($value === '' || $value === null) {
            // CCP docs say Retry-After is always present on 429, but
            // belt-and-braces: 60 matches the legacy error-limit window.
            return 60;
        }

        // Retry-After can be delta-seconds or an HTTP-date. We support
        // delta-seconds; HTTP-date is rare from ESI.
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return 60;
        }

        return max(1, $ts - time());
    }

    private function logRateLimit(string $url, Response $response): void
    {
        $headers = $this->extractRateLimitHeaders($response);
        if ($headers === []) {
            return;
        }

        Log::debug('esi response', [
            'url' => $url,
            'status' => $response->status(),
        ] + $headers);
    }
}
