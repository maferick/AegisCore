<?php

declare(strict_types=1);

namespace App\System;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Queries the read-only docker-socket-proxy sidecar for container state
 * and returns a list of {@see DockerContainer} snapshots the
 * /admin/container-status page can render as a table.
 *
 * Shape mirrors {@see SystemStatusService}:
 *   - single public `snapshot()` + `fresh()` pair;
 *   - one probe, caught + degraded rather than allowed to 500 the page;
 *   - a short cache so Filament polling doesn't hammer the proxy.
 *
 * We deliberately call the list endpoint once per refresh rather than
 * walking individual `/containers/{id}/json` calls — list responses
 * include enough to render the table (Name, Image, State, Status,
 * Created) and a single HTTP hit is bounded by the caller's timeout.
 * Health status comes out of the `Status` field's suffix (Docker
 * appends `(healthy)` / `(unhealthy)` / `(health: starting)` when a
 * healthcheck is declared — cheaper than a second round-trip per row).
 */
class DockerStatusService
{
    /**
     * Cache key for the full snapshot. Bumping this invalidates every
     * cached result across pods on deploy.
     */
    private const CACHE_KEY = 'aegiscore:docker-status:v1';

    /**
     * Error-marker cache key — used when the proxy is unreachable. We
     * cache the failure briefly too so a broken proxy doesn't turn into
     * a 5s-polling HTTP-hammer.
     */
    private const ERROR_CACHE_KEY = 'aegiscore:docker-status:error:v1';

    /**
     * How long the happy-path snapshot lives in cache. Long enough that
     * widget polling at 5s mostly hits cache, short enough that a
     * container crash shows up within ~10s.
     */
    private const CACHE_TTL_SECONDS = 5;

    /**
     * How long a *failed* probe is cached. A little longer than the
     * success TTL so a spinning-polling-widget doesn't keep rattling a
     * dead proxy, but still short enough that a fix is visible quickly.
     */
    private const ERROR_CACHE_TTL_SECONDS = 10;

    public function __construct(
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * Force a fresh probe, bypassing the cache. Useful for tests and a
     * future "Refresh" button on the admin page.
     */
    public function fresh(): DockerSnapshot
    {
        $snapshot = $this->probe();

        try {
            $ttl = $snapshot->isError()
                ? self::ERROR_CACHE_TTL_SECONDS
                : self::CACHE_TTL_SECONDS;
            $this->cacheStore()->put(self::CACHE_KEY, $snapshot, $ttl);
        } catch (Throwable $e) {
            // Cache write failures never break the page.
        }

        return $snapshot;
    }

    /**
     * Return the cached snapshot, or run a fresh probe on cache miss.
     */
    public function snapshot(): DockerSnapshot
    {
        try {
            /** @var mixed $cached */
            $cached = $this->cacheStore()->get(self::CACHE_KEY);
        } catch (Throwable $e) {
            $cached = null;
        }

        if ($cached instanceof DockerSnapshot) {
            return $cached;
        }

        return $this->fresh();
    }

    /**
     * Hit the proxy and fold the JSON response into a list of
     * {@see DockerContainer} DTOs.
     */
    private function probe(): DockerSnapshot
    {
        $host = (string) config('aegiscore.docker.host');
        if ($host === '') {
            return DockerSnapshot::unconfigured();
        }

        $timeout = max(1, (int) config('aegiscore.docker.timeout_seconds', 2));

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->get(rtrim($host, '/').'/containers/json', ['all' => 'true']);
        } catch (Throwable $e) {
            return DockerSnapshot::error($this->trimMessage($e->getMessage()));
        }

        if (! $response->successful()) {
            return DockerSnapshot::error('Docker API returned HTTP '.$response->status());
        }

        /** @var mixed $raw */
        $raw = $response->json();
        if (! is_array($raw)) {
            return DockerSnapshot::error('Docker API returned non-array payload');
        }

        $containers = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $containers[] = $this->fromApiRow($row);
        }

        // Sort by name for a stable table order — Docker's list
        // endpoint orders by creation time, which shuffles whenever
        // operators rebuild individual services.
        usort(
            $containers,
            static fn (DockerContainer $a, DockerContainer $b): int => strcmp($a->name, $b->name),
        );

        return DockerSnapshot::ok($containers);
    }

    /**
     * Map one entry from `/containers/json` to our DTO. Defensive about
     * missing / unexpected keys — the Docker API version on the host
     * may predate the one we test against.
     *
     * @param  array<string, mixed>  $row
     */
    private function fromApiRow(array $row): DockerContainer
    {
        // Docker returns Names as an array of all attached names
        // (network aliases etc.), each prefixed with '/'. We only
        // show the first one, sans leading slash, to match the
        // `docker compose ps` display.
        $name = '';
        $names = $row['Names'] ?? null;
        if (is_array($names) && isset($names[0]) && is_string($names[0])) {
            $name = ltrim($names[0], '/');
        }
        if ($name === '' && isset($row['Id']) && is_string($row['Id'])) {
            // Fall back to the short container ID if no name was set.
            $name = substr($row['Id'], 0, 12);
        }

        $image = is_string($row['Image'] ?? null) ? $row['Image'] : '';
        $state = DockerContainerState::fromRaw(
            is_string($row['State'] ?? null) ? $row['State'] : null,
        );
        $status = is_string($row['Status'] ?? null) ? $row['Status'] : '';

        // `Created` is an epoch second for when the container was
        // created. For running containers this is "close enough" to
        // the start time for the uptime column — the list endpoint
        // doesn't expose `State.StartedAt`, and making a second call
        // per row just to split those apart would triple the probe cost.
        $createdAt = is_int($row['Created'] ?? null) ? $row['Created'] : null;

        return new DockerContainer(
            name: $name,
            image: $image,
            state: $state,
            healthStatus: $this->parseHealthFromStatus($status),
            startedAtUnix: $state === DockerContainerState::RUNNING ? $createdAt : null,
            statusLine: $status,
        );
    }

    /**
     * Extract the health suffix Docker appends to the status line
     * when a container has a healthcheck declared.
     *
     *   "Up 3 hours (healthy)"          → "healthy"
     *   "Up 2 minutes (unhealthy)"      → "unhealthy"
     *   "Up 10 seconds (health: starting)" → "starting"
     *   "Exited (0) 2 hours ago"        → null
     *   "Up About an hour"              → null (no healthcheck declared)
     */
    private function parseHealthFromStatus(string $status): ?string
    {
        if (! preg_match('/\((?:health: )?([a-z]+)\)/i', $status, $matches)) {
            return null;
        }

        $candidate = strtolower($matches[1]);

        // Guard: avoid misreading the numeric exit-code suffix on
        // exited containers ("Exited (137) 5 minutes ago") as a health
        // state. The regex above only matches alpha, but be explicit.
        return in_array($candidate, ['healthy', 'unhealthy', 'starting'], true)
            ? $candidate
            : null;
    }

    /**
     * Keep error details short enough that a stat card / table row
     * doesn't wrap into ugly multi-line territory.
     */
    private function trimMessage(string $message): string
    {
        $oneLine = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (mb_strlen($oneLine) <= 120) {
            return $oneLine;
        }

        return mb_substr($oneLine, 0, 117).'…';
    }

    private function cacheStore(): CacheRepository
    {
        return $this->cache ?? Cache::store();
    }
}
