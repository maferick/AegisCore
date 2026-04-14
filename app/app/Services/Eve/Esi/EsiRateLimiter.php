<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Per-group floating-window throttle for ESI requests.
 *
 * Backed by the `eve.esi.cache_store` cache (Redis in prod). Key families,
 * all with TTLs so stale entries fall out automatically:
 *
 *   esi:rl:state:{group}    — last-known (remaining, reset_at) for the group.
 *                             Updated from `X-Ratelimit-*` response headers.
 *                             TTL = (reset_at - now) + safety pad.
 *
 *   esi:rl:backoff:{group}  — epoch the group is held until. Set when ESI
 *                             returns 429/420 with `Retry-After`.
 *                             TTL = retry_after + safety pad.
 *
 *   esi:rl:backoff:_global  — same shape as group backoff, but applies to
 *                             every URL. Set whenever a 429 lands without
 *                             enough metadata to pin a group.
 *
 *   esi:rl:url_group:{hash} — which group a URL belongs to, learned from
 *                             the response. Lets `preflight()` look up the
 *                             group before sending. TTL = 1 day; route
 *                             groupings rarely change between SDE bumps.
 *
 *   esi:rl:error            — last-known (remaining, reset_at) for the
 *                             global error-limit budget (CCP's legacy
 *                             100-errors-per-minute ceiling reported via
 *                             `X-ESI-Error-Limit-Remain` /
 *                             `X-ESI-Error-Limit-Reset`). Mutually
 *                             exclusive with the bucket headers on any
 *                             single response, but applies to *every* URL
 *                             regardless of group — one error budget per
 *                             IP. TTL = (reset_at - now) + safety pad.
 *
 * Why no "tokens-this-window" counter of our own? CCP's
 * `X-Ratelimit-Remaining` is the source of truth — re-counting tokens
 * locally just compounds drift on every retry / parallel worker. We
 * reactively trust the header and add a configurable safety margin so the
 * last few tokens stay reserved for retries / out-of-band traffic.
 *
 * **Not a distributed lock.** Two workers can race past `preflight()` at
 * the same time. That's fine: the safety margin absorbs small overshoots,
 * and 429 -> backoff is the safety net. Tightening this requires a Lua
 * script + atomic counter; punted until concurrent imports demonstrate the
 * margin isn't enough.
 *
 * See ADR-0002 § ESI client (revised) for the full reasoning.
 */
final class EsiRateLimiter
{
    private const KEY_STATE = 'esi:rl:state:';

    private const KEY_BACKOFF = 'esi:rl:backoff:';

    private const KEY_URL_GROUP = 'esi:rl:url_group:';

    private const KEY_ERROR_STATE = 'esi:rl:error';

    private const GLOBAL_GROUP = '_global';

    // url_group cache lives a day — long enough to amortise the lookup over
    // a typical import run, short enough that route reshuffling between SDE
    // bumps doesn't keep stale group names indefinitely.
    private const URL_GROUP_TTL_SECONDS = 86400;

    public function __construct(
        private readonly Repository $cache,
        /** Refuse to send when remaining bucket tokens drop below this. */
        private readonly int $safetyMargin,
        /**
         * Refuse to send when the global error budget
         * (`X-ESI-Error-Limit-Remain`) drops to or below this. Distinct
         * from the bucket safety margin because the error budget is one
         * IP-wide counter whose overflow trips 420 for every route.
         */
        private readonly int $errorSafetyMargin,
    ) {}

    /**
     * Build from `config('eve.esi')` — same store the conditional-GET cache
     * uses, so all ESI metadata lives in one place.
     */
    public static function fromConfig(): self
    {
        $cfg = config('eve.esi');

        return new self(
            cache: Cache::store((string) ($cfg['cache_store'] ?? 'redis')),
            safetyMargin: (int) ($cfg['rate_limit_safety_margin'] ?? 5),
            errorSafetyMargin: (int) ($cfg['error_limit_safety_margin'] ?? 10),
        );
    }

    /**
     * How long should the caller wait before sending this request?
     *
     * Returns 0.0 when good to go. Positive seconds when held — by any of:
     * a 429 backoff, a depleted group bucket, or a near-exhausted global
     * error budget. Caller decides whether to sleep, `release()` a queue
     * job, or surface the wait as a 503-style error.
     */
    public function preflight(string $url): float
    {
        $now = time();

        // Global backoff trumps everything — a recent 429 with no group
        // identity blocks the whole client.
        $globalBackoff = (int) ($this->cache->get(self::KEY_BACKOFF.self::GLOBAL_GROUP) ?? 0);
        if ($globalBackoff > $now) {
            return (float) ($globalBackoff - $now);
        }

        // Global error budget — the legacy 100-errors-per-minute ceiling
        // applies across every route, so a depleted budget holds the whole
        // client until the window resets. Same safety-margin logic as the
        // bucket limit but on the error counter: we reserve a handful of
        // errors so a single retry loop can't drive us into 420 land.
        $errorState = $this->cache->get(self::KEY_ERROR_STATE);
        if (is_array($errorState) && isset($errorState['remaining'], $errorState['reset_at'])) {
            $errRemaining = (int) $errorState['remaining'];
            $errResetAt = (int) $errorState['reset_at'];
            if ($errResetAt > $now && $errRemaining <= $this->errorSafetyMargin) {
                return (float) ($errResetAt - $now);
            }
        }

        $group = $this->groupForUrl($url);
        if ($group === null) {
            // First-time URL — we don't yet know its group, so the only
            // signal we can act on is the global backoff (already checked).
            // Allow the request; the response will populate the group map
            // for next time.
            return 0.0;
        }

        $groupBackoff = (int) ($this->cache->get(self::KEY_BACKOFF.$group) ?? 0);
        if ($groupBackoff > $now) {
            return (float) ($groupBackoff - $now);
        }

        $state = $this->cache->get(self::KEY_STATE.$group);
        if (! is_array($state) || ! isset($state['remaining'], $state['reset_at'])) {
            // No state (hot start) or unexpected serialised shape (cache
            // store rotated). Either way: fall through and let `record()`
            // reseed from the next response.
            return 0.0;
        }

        $remaining = (int) $state['remaining'];
        $resetAt = (int) $state['reset_at'];

        if ($resetAt <= $now) {
            // Window already rolled over. CCP will hand us a fresh budget
            // on the next response. Allow and let `record()` reseed.
            return 0.0;
        }

        if ($remaining > $this->safetyMargin) {
            return 0.0;
        }

        // Below margin — wait until the window resets so we don't burn the
        // last few tokens on speculative traffic.
        return (float) ($resetAt - $now);
    }

    /**
     * Update group state from a successful (or non-rate-limit failed) ESI
     * response. Always called by the client, regardless of status — even a
     * 304 carries the rate-limit headers.
     *
     * Responses carry either the bucket headers (`X-Ratelimit-*`) or the
     * legacy error-limit headers (`X-ESI-Error-Limit-*`), never both. We
     * handle each branch independently so the caller never has to care
     * which scheme ESI is reporting for a given route.
     */
    public function record(string $url, EsiResponse $response): void
    {
        $headers = $response->rateLimit;

        $this->recordBucket($url, $headers);
        $this->recordErrorLimit($headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function recordBucket(string $url, array $headers): void
    {
        $group = $headers['X-Ratelimit-Group'] ?? null;
        if ($group === null) {
            return;
        }

        $this->rememberGroupForUrl($url, $group);

        $remaining = isset($headers['X-Ratelimit-Remaining'])
            ? (int) $headers['X-Ratelimit-Remaining']
            : null;
        $resetAt = $this->resetEpochFromHeaders($headers);

        if ($remaining === null || $resetAt === null) {
            return;
        }

        // TTL just past the window so the row evaporates after CCP would
        // have rolled the budget; the next request reseeds from headers.
        $ttl = max(1, ($resetAt - time()) + 5);

        $this->cache->put(
            self::KEY_STATE.$group,
            ['remaining' => $remaining, 'reset_at' => $resetAt],
            $ttl,
        );
    }

    /**
     * Persist the global error-limit window from `X-ESI-Error-Limit-*`.
     *
     * CCP's error limit is a fixed-window counter (not sliding): `Remain`
     * drops as we accumulate errors, `Reset` is the whole-second countdown
     * until the window resets and `Remain` jumps back to its ceiling.
     * Overflow trips 420 for every route until the window rolls.
     *
     * @param  array<string, string>  $headers
     */
    private function recordErrorLimit(array $headers): void
    {
        $remaining = $headers['X-ESI-Error-Limit-Remain'] ?? null;
        $resetIn = $headers['X-ESI-Error-Limit-Reset'] ?? null;
        if ($remaining === null || $resetIn === null) {
            return;
        }
        if (! ctype_digit($remaining) || ! ctype_digit($resetIn)) {
            return;
        }

        $resetAt = time() + (int) $resetIn;
        $ttl = max(1, (int) $resetIn + 5);

        $this->cache->put(
            self::KEY_ERROR_STATE,
            ['remaining' => (int) $remaining, 'reset_at' => $resetAt],
            $ttl,
        );
    }

    /**
     * Honour a 429 / 420. `Retry-After` becomes a per-group hold (or a
     * global hold when the response didn't tell us a group). Belt + braces:
     * we set the global hold every time on top of the group hold so a
     * subsequent first-time URL also waits.
     */
    public function backoff(string $url, ?string $group, int $retryAfterSeconds): void
    {
        $until = time() + max(1, $retryAfterSeconds);
        $ttl = max(1, $retryAfterSeconds + 5);

        // Prefer the group from the headers; fall back to whatever we last
        // learned for this URL. Either way, also set the global hold.
        $effectiveGroup = $group ?? $this->groupForUrl($url);
        if ($effectiveGroup !== null) {
            $this->cache->put(self::KEY_BACKOFF.$effectiveGroup, $until, $ttl);
        }
        $this->cache->put(self::KEY_BACKOFF.self::GLOBAL_GROUP, $until, $ttl);
    }

    // ----------------------------------------------------------------------
    // internals
    // ----------------------------------------------------------------------

    private function groupForUrl(string $url): ?string
    {
        $value = $this->cache->get(self::KEY_URL_GROUP.$this->urlKey($url));

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function rememberGroupForUrl(string $url, string $group): void
    {
        $this->cache->put(
            self::KEY_URL_GROUP.$this->urlKey($url),
            $group,
            self::URL_GROUP_TTL_SECONDS,
        );
    }

    /**
     * Hash the URL — keeps key length bounded and avoids leaking query
     * params into Redis key names. Group-mapping is per-URL on purpose:
     * different paths inside the same group still share a state key
     * because the group name comes from the response.
     */
    private function urlKey(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * Derive the absolute epoch when the current window resets, from the
     * rate-limit headers. CCP's `X-Ratelimit-Limit` is `<count>/<window>`
     * (e.g. `150/15m`); we parse the window suffix as the worst-case
     * sliding window length. Returns null if the header shape changes
     * unexpectedly — `record()` then skips state updates rather than
     * extrapolate from garbage.
     *
     * @param  array<string, string>  $headers
     */
    private function resetEpochFromHeaders(array $headers): ?int
    {
        $limit = $headers['X-Ratelimit-Limit'] ?? null;
        if ($limit === null || ! str_contains($limit, '/')) {
            return null;
        }

        [, $windowSpec] = explode('/', $limit, 2);
        $seconds = $this->parseWindowSpec(trim($windowSpec));
        if ($seconds === null) {
            return null;
        }

        return time() + $seconds;
    }

    /**
     * `15m` → 900, `1h` → 3600, `30s` → 30, `60` → 60.
     * Returns null on shapes we don't recognise.
     */
    private function parseWindowSpec(string $spec): ?int
    {
        if ($spec === '') {
            return null;
        }

        if (ctype_digit($spec)) {
            return (int) $spec;
        }

        if (! preg_match('/^(\d+)([smhd])$/i', $spec, $m)) {
            return null;
        }

        $n = (int) $m[1];

        return match (strtolower($m[2])) {
            's' => $n,
            'm' => $n * 60,
            'h' => $n * 3600,
            'd' => $n * 86400,
            default => null,
        };
    }
}
