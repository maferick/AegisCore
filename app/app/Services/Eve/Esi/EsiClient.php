<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for esi.evetech.net.
 *
 * Phase-1 scope (ADR-0002 § ESI client):
 *
 *   - Sends the CCP-required `User-Agent` on every call.
 *   - Attaches `Authorization: Bearer <token>` when the caller supplies one.
 *   - Per-URL conditional GET: stores `ETag` + `Last-Modified` in the cache
 *     store, auto-attaches `If-None-Match` / `If-Modified-Since` on the next
 *     request. 3XX responses cost half the tokens of 2XX on ESI's published
 *     rate-limit math, so repeat fetches get noticeably cheaper.
 *   - Logs `X-Ratelimit-*` on every response (debug channel) for offline
 *     analysis; no pre-flight throttling yet — that belongs with the Python
 *     polling plane where it matters.
 *   - On 429 / 420: throws `EsiRateLimitException` carrying `Retry-After`.
 *     Callers decide whether to `release($seconds)` (Horizon) or bubble.
 *
 * Explicitly NOT in scope for phase 1:
 *
 *   - Per-group pre-flight throttling (needs ingest of the OpenAPI spec's
 *     `x-rate-limit` extension; ADR-0002 punts this to the Python poller).
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
        );
    }

    /**
     * GET an ESI endpoint.
     *
     * `$path` is relative to the configured base URL (e.g. `/characters/123/`);
     * absolute URLs are passed through so callers can hit non-default ESI
     * hosts without reconfiguring the client.
     *
     * @param array<string, scalar|array<int, scalar>> $query
     *
     * @throws EsiRateLimitException  on 429 / 420
     * @throws EsiException           on any other 4xx / 5xx
     */
    public function get(string $path, array $query = [], ?string $bearerToken = null): EsiResponse
    {
        $url = $this->resolveUrl($path);
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
            throw new EsiRateLimitException(
                message: "ESI rate-limited: HTTP {$status} on {$url}",
                retryAfter: $this->retryAfterSeconds($response),
                status: $status,
                responseBody: $response->body(),
                url: $url,
            );
        }

        if ($status === 304) {
            // Conditional-GET cache hit. Body is empty on the wire; caller
            // MUST check `notModified` before using the body field.
            return new EsiResponse(
                status: 304,
                body: null,
                notModified: true,
                etag: $response->header('ETag') ?: null,
                lastModified: $response->header('Last-Modified') ?: null,
                expires: $response->header('Expires') ?: null,
                rateLimit: $rateLimit,
            );
        }

        if ($status >= 400) {
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

        return new EsiResponse(
            status: $status,
            body: $response->json(),
            notModified: false,
            etag: $etag,
            lastModified: $lastModified,
            expires: $response->header('Expires') ?: null,
            rateLimit: $rateLimit,
        );
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
     * @param array<string, scalar|array<int, scalar>> $query
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
     */
    private function extractRateLimitHeaders(Response $response): array
    {
        $headers = [];
        foreach (['X-Ratelimit-Group', 'X-Ratelimit-Limit', 'X-Ratelimit-Remaining', 'X-Ratelimit-Used'] as $name) {
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
