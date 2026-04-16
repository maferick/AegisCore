<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payload + freshness cache decorator around {@see EsiClientInterface}.
 *
 * The bare {@see EsiClient} transport caches *validators* (ETag /
 * Last-Modified) so repeat calls can ride conditional-GET and get cheap
 * 304s, but it does not cache the response body — on a 304 it returns
 * `body: null, notModified: true` and pushes the replay problem onto every
 * caller. That makes it a revalidation hint store, not a cache.
 *
 * This decorator upgrades the model:
 *
 *   - Stores the full payload alongside the validators, keyed by URL +
 *     normalised query + auth scope. `esi:payload:<sha256>`.
 *   - Drives freshness from ESI's `Expires` header (CCP's documented
 *     contract) rather than a generic app-wide TTL. Polling earlier than
 *     `Expires` wastes budget and can look like an attempt to circumvent
 *     ESI caching.
 *   - On 304 from the inner transport, replays the cached body and keeps
 *     `notModified: true` so existing short-circuit callers (wallet
 *     pollers etc.) still work, while callers that want a body now have
 *     one regardless.
 *   - Single-flights concurrent fetches through a Redis lock so a
 *     killmail-driven burst of 25 jobs asking for the same character
 *     profile hits ESI exactly once.
 *   - Serves the last-good payload with `stale: true` when the upstream
 *     returns 5xx or times out within the configured stale-if-error
 *     window. 4xx and rate-limit exceptions are never served stale.
 *
 * Not in scope for this first cut (all deferred to follow-ups): per-route
 * cache policies beyond one default, entity-level semantic keys, paginated
 * endpoint coalescing, negative caching for 404, MariaDB durability
 * projection. See the ESI caching discussion on the
 * `claude/review-esi-caching-EuD3C` branch for the layered plan.
 */
final class CachedEsiClient implements EsiClientInterface
{
    /** Bumped when the stored object shape changes; older entries are ignored. */
    private const CACHE_KEY_VERSION = 1;

    /** Prefix deliberately distinct from the inner transport's `esi:cond:` keys. */
    private const KEY_PREFIX = 'esi:payload:';

    private const LOCK_PREFIX = 'esi:payload:lock:';

    public function __construct(
        private readonly EsiClientInterface $inner,
        private readonly Repository $cache,
        /** If ESI omits `Expires`, treat the entry as fresh for this many seconds. */
        private readonly int $fallbackFreshnessSeconds,
        /** How long after `fetched_at` we'll serve a stale body on transient upstream failure. */
        private readonly int $staleIfErrorSeconds,
        /** Upper bound on how long we keep a payload entry in the cache. */
        private readonly int $retentionSeconds,
        /** Max seconds to wait on a single-flight lock held by a peer request before falling through. */
        private readonly int $lockWaitSeconds,
    ) {}

    public static function fromConfig(EsiClientInterface $inner): self
    {
        $cfg = config('eve.esi');
        $store = (string) ($cfg['cache_store'] ?? 'redis');

        return new self(
            inner: $inner,
            cache: Cache::store($store),
            fallbackFreshnessSeconds: (int) ($cfg['payload_fallback_freshness_seconds'] ?? 60),
            staleIfErrorSeconds: (int) ($cfg['payload_stale_if_error_seconds'] ?? 600),
            retentionSeconds: (int) ($cfg['payload_retention_seconds'] ?? 604800),
            lockWaitSeconds: (int) ($cfg['payload_lock_wait_seconds'] ?? 5),
        );
    }

    public function get(
        string $path,
        array $query = [],
        ?string $bearerToken = null,
        array $headers = [],
        bool $forceRefresh = false,
    ): EsiResponse {
        $key = $this->payloadKey($path, $query, $bearerToken, $headers);
        $entry = $this->loadEntry($key);
        $now = time();

        // On an explicit forced refresh, skip the fresh-hit shortcut
        // so we always go to the wire. The inner transport honours the
        // same flag to omit conditional-GET headers, guaranteeing a
        // full body that we can cache.
        if ($forceRefresh) {
            $entry = null;
        }

        // Fresh hit: never touch the network.
        if ($entry !== null && $now < $entry['expires_at']) {
            Log::debug('esi cache fresh hit', ['key' => $key]);

            return $this->replay($entry, notModified: false);
        }

        // Stale or miss. Try single-flight so a burst of concurrent callers
        // coalesces into one upstream request.
        $lock = $this->cache->lock(self::LOCK_PREFIX.$this->keyHash($path, $query, $bearerToken, $headers), 30);
        $acquired = false;

        try {
            $acquired = $lock->get();
        } catch (Throwable $e) {
            // Lock backend unavailable (array store in tests, etc.) — fall
            // through to a direct fetch; the functional cost is duplicate
            // upstream requests under concurrent load, never a correctness
            // bug.
            Log::debug('esi cache lock unavailable', ['key' => $key, 'reason' => $e->getMessage()]);
            $acquired = true; // pretend we own it; no peer to coordinate with.
        }

        if (! $acquired) {
            $refreshed = $this->waitForPeerRefresh($key, $now);
            if ($refreshed !== null) {
                Log::debug('esi cache coalesced wait hit', ['key' => $key]);

                return $this->replay($refreshed, notModified: false);
            }

            Log::debug('esi cache lock timeout, falling through', ['key' => $key]);
            // Peer didn't populate in time. Fall through without the lock;
            // we'll race them but that's strictly bounded (two requests, not
            // twenty-five) and preferable to starving the caller.
        }

        try {
            $response = $this->inner->get($path, $query, $bearerToken, $headers, $forceRefresh);
        } catch (EsiRateLimitException $e) {
            // Rate-limit throttles are the rate-limiter's job, not the
            // cache's. Never serve stale through a 429/420.
            throw $e;
        } catch (EsiException $e) {
            if ($e->status >= 500 && $entry !== null && $this->withinStaleWindow($entry, $now)) {
                Log::info('esi cache serving stale on 5xx', [
                    'key' => $key,
                    'status' => $e->status,
                    'fetched_at' => $entry['fetched_at'],
                ]);

                return $this->replay($entry, notModified: false, stale: true);
            }

            throw $e;
        } catch (ConnectionException $e) {
            if ($entry !== null && $this->withinStaleWindow($entry, $now)) {
                Log::info('esi cache serving stale on connection error', [
                    'key' => $key,
                    'fetched_at' => $entry['fetched_at'],
                    'reason' => $e->getMessage(),
                ]);

                return $this->replay($entry, notModified: false, stale: true);
            }

            throw $e;
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (LockTimeoutException) {
                    // Lock TTL already elapsed; nothing to release. Rare but
                    // possible if the inner call took longer than the lock
                    // TTL. Not a bug.
                }
            }
        }

        if ($response->notModified) {
            if ($entry === null) {
                // Drift: the inner transport has validators cached from
                // a previous call but our payload entry is gone (evicted,
                // retention lapsed, decorator added after validators
                // were learned, container restart with split cache
                // persistence, etc.). The naked 304 would leak `body:
                // null` to the caller — which the standings fetcher
                // (and probably other paginated callers) reads as
                // "empty page, stop iterating". Recover by retrying
                // once with `forceRefresh: true`: the inner will skip
                // conditional headers, CCP returns a full body, and we
                // repopulate both caches. Never recurses — the retry
                // sets `$forceRefresh` at the top of this method which
                // forces $entry = null, so the second call can't land
                // back in this branch with a cached entry to replay.
                Log::warning('esi cache got 304 with no stored payload, retrying with forced refresh', ['key' => $key]);

                if (! $forceRefresh) {
                    return $this->get($path, $query, $bearerToken, $headers, forceRefresh: true);
                }

                // $forceRefresh was already true AND we still got a 304.
                // CCP shouldn't produce a 304 without a client-supplied
                // validator; log + surface the naked 304 so the drift
                // doesn't loop forever.
                Log::error('esi cache: upstream returned 304 even on forced refresh', ['key' => $key]);

                return $response;
            }

            Log::debug('esi cache revalidated 304', ['key' => $key]);
            $refreshed = $this->refreshFreshness($entry, $response, $now);
            $this->storeEntry($key, $refreshed);

            return $this->replay($refreshed, notModified: true);
        }

        // Successful fresh fetch. Only cache 2xx — error bodies are never
        // persisted by default per the scope-lock contract (negative caching
        // is an explicit per-endpoint opt-in, not a default behaviour).
        if ($response->status >= 200 && $response->status < 300) {
            Log::debug('esi cache refreshed 200', ['key' => $key]);
            $entry = $this->buildEntry($response, $bearerToken, $now);
            $this->storeEntry($key, $entry);
        }

        return $response;
    }

    /**
     * Poll for a peer's write to the same key. Returns the refreshed entry
     * if one landed within {@see $lockWaitSeconds}, null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function waitForPeerRefresh(string $key, int $startedAt): ?array
    {
        $deadline = $startedAt + $this->lockWaitSeconds;
        $pollMicros = 50_000; // 50ms

        while (time() < $deadline) {
            usleep($pollMicros);
            $entry = $this->loadEntry($key);
            if ($entry !== null && time() < $entry['expires_at']) {
                return $entry;
            }
        }

        return null;
    }

    private function withinStaleWindow(array $entry, int $now): bool
    {
        return $now < ($entry['fetched_at'] + $this->staleIfErrorSeconds);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadEntry(string $key): ?array
    {
        /** @var array<string, mixed>|null $stored */
        $stored = $this->cache->get($key);

        if (! is_array($stored)) {
            return null;
        }

        if (($stored['cache_key_version'] ?? null) !== self::CACHE_KEY_VERSION) {
            return null;
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function storeEntry(string $key, array $entry): void
    {
        $this->cache->put($key, $entry, $this->retentionSeconds);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(EsiResponse $response, ?string $bearerToken, int $now): array
    {
        $expiresAt = $this->parseExpires($response->expires, $now);

        return [
            'body' => $response->body,
            'status' => $response->status,
            'etag' => $response->etag,
            'last_modified' => $response->lastModified,
            'expires' => $response->expires,
            'expires_at' => $expiresAt,
            'fetched_at' => $now,
            'auth_scope_hash' => $this->authScopeHash($bearerToken),
            'cache_key_version' => self::CACHE_KEY_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function refreshFreshness(array $entry, EsiResponse $response, int $now): array
    {
        $entry['expires_at'] = $this->parseExpires($response->expires, $now);
        $entry['fetched_at'] = $now;
        if ($response->expires !== null) {
            $entry['expires'] = $response->expires;
        }
        if ($response->etag !== null) {
            $entry['etag'] = $response->etag;
        }
        if ($response->lastModified !== null) {
            $entry['last_modified'] = $response->lastModified;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function replay(array $entry, bool $notModified, bool $stale = false): EsiResponse
    {
        /** @var array<int|string, mixed>|null $body */
        $body = $entry['body'];

        return new EsiResponse(
            status: (int) $entry['status'],
            body: $body,
            notModified: $notModified,
            etag: $entry['etag'] ?? null,
            lastModified: $entry['last_modified'] ?? null,
            expires: $entry['expires'] ?? null,
            rateLimit: [],
            stale: $stale,
        );
    }

    private function parseExpires(?string $header, int $now): int
    {
        if ($header === null || $header === '') {
            return $now + $this->fallbackFreshnessSeconds;
        }

        $ts = strtotime($header);
        if ($ts === false) {
            return $now + $this->fallbackFreshnessSeconds;
        }

        // ESI sometimes serves an `Expires` in the past under rare edge
        // conditions. Treat that as "refresh immediately" but never as
        // "hold forever" — the fallback gives us a sane minimum dwell.
        if ($ts <= $now) {
            return $now;
        }

        return $ts;
    }

    /**
     * @param  array<string, scalar|array<int, scalar>>  $query
     * @param  array<string, string>  $headers
     */
    private function payloadKey(string $path, array $query, ?string $bearerToken, array $headers = []): string
    {
        return self::KEY_PREFIX.$this->keyHash($path, $query, $bearerToken, $headers);
    }

    /**
     * @param  array<string, scalar|array<int, scalar>>  $query
     * @param  array<string, string>  $headers  Caller-supplied headers. Folded
     *                                          into the key so a compat-date
     *                                          bump doesn't serve a cached
     *                                          body from the old shape.
     */
    private function keyHash(string $path, array $query, ?string $bearerToken, array $headers = []): string
    {
        ksort($query);
        ksort($headers);

        return hash('sha256', implode('|', [
            $path,
            http_build_query($query),
            http_build_query($headers),
            $this->authScopeHash($bearerToken),
        ]));
    }

    /**
     * Auth scope hash isolates authenticated responses from public ones (and
     * from each other). Hashing means we never stash raw bearer tokens in
     * cache keys or entry metadata.
     */
    private function authScopeHash(?string $bearerToken): string
    {
        return hash('sha256', 'esi-auth:'.($bearerToken ?? 'public'));
    }

    /**
     * POST passthrough — no caching for POST requests.
     * Delegates directly to the inner transport for rate limiting.
     */
    public function post(
        string $path,
        array $body = [],
        ?string $bearerToken = null,
        array $headers = [],
    ): EsiResponse {
        return $this->inner->post($path, $body, $bearerToken, $headers);
    }
}
