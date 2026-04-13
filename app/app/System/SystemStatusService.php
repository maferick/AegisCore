<?php

declare(strict_types=1);

namespace App\System;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Probes each backend AegisCore depends on and returns a short snapshot
 * the admin System Status widget can render as coloured cards.
 *
 * Scope: is-the-light-on checks. Not a replacement for real monitoring;
 * deeper diagnostics still live in Horizon, OpenSearch Dashboards, Neo4j
 * browser, InfluxDB UI.
 *
 * Each probe has a tight timeout (configurable, defaults to 1s) and is
 * wrapped in try/catch so one dead service never breaks the whole page.
 * Results are cached briefly so Filament's polling widget doesn't hammer
 * the backends on every refresh.
 */
class SystemStatusService
{
    /**
     * Cache key for the full snapshot. Bumping this invalidates every
     * cached result across pods on deploy.
     */
    private const CACHE_KEY = 'aegiscore:system-status:v1';

    /**
     * How long to cache each snapshot. Short enough that an operator
     * sees a failure within ~15s, long enough that widget polling
     * (default 5s) hits cache most of the time.
     */
    private const CACHE_TTL_SECONDS = 15;

    /**
     * Per-probe timeout in seconds. Generous enough for a real health
     * check over the internal Docker network, tight enough that six
     * dead services still render the page in under ~10s worst case.
     */
    private const PROBE_TIMEOUT_SECONDS = 1;

    public function __construct(
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * Force a fresh probe, bypassing the cache. Useful for tests and for
     * a future "Refresh" button on the admin page.
     *
     * @return array<int, SystemStatus>
     */
    public function fresh(): array
    {
        $statuses = $this->probeAll();

        // A flaky cache backend shouldn't 500 the admin page — we can
        // always recompute on the next poll. Swallow write failures
        // here; the snapshot is still returned to the caller.
        try {
            $this->cacheStore()->put(self::CACHE_KEY, $statuses, self::CACHE_TTL_SECONDS);
        } catch (Throwable $e) {
            // Intentionally ignored — see comment above.
        }

        return $statuses;
    }

    /**
     * Return the cached snapshot, or a fresh one if the cache is cold.
     *
     * @return array<int, SystemStatus>
     */
    public function snapshot(): array
    {
        // Cache read is the hot path — but if the cache backend itself
        // is flaky (Redis under pressure, deserialization error on a
        // stale payload after a deploy), we must never let that 500 the
        // page. Fall back to a live probe on any failure.
        try {
            /** @var mixed $cached */
            $cached = $this->cacheStore()->get(self::CACHE_KEY);
        } catch (Throwable $e) {
            $cached = null;
        }

        if (is_array($cached) && $cached !== [] && $this->isValidSnapshot($cached)) {
            return $cached;
        }

        return $this->fresh();
    }

    /**
     * Guard against a cached payload that deserialized into something
     * unexpected — e.g. an older schema after a deploy, or partial data
     * from a cache-write that raced a TTL. Safer to re-probe than to
     * hand the widget garbage.
     *
     * @param  array<mixed>  $cached
     */
    private function isValidSnapshot(array $cached): bool
    {
        foreach ($cached as $entry) {
            if (! $entry instanceof SystemStatus) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, SystemStatus>
     */
    private function probeAll(): array
    {
        return [
            $this->probeDatabase(),
            $this->probeRedis(),
            $this->probeHorizon(),
            $this->probeOpenSearch(),
            $this->probeInfluxDb(),
            $this->probeNeo4j(),
        ];
    }

    /**
     * MariaDB — the canonical system of record. A dead DB means the app
     * is essentially unusable, so this one going red should be the first
     * thing an operator sees.
     */
    private function probeDatabase(): SystemStatus
    {
        try {
            $started = microtime(true);
            DB::connection()->select('SELECT 1');
            $elapsedMs = (int) ((microtime(true) - $started) * 1000);

            return new SystemStatus(
                name: 'MariaDB',
                level: SystemStatusLevel::OK,
                detail: 'Connected ('.$elapsedMs.' ms)',
            );
        } catch (Throwable $e) {
            return new SystemStatus(
                name: 'MariaDB',
                level: SystemStatusLevel::DOWN,
                detail: $this->trimMessage($e->getMessage()),
            );
        }
    }

    /**
     * Redis backs cache, sessions, and the Laravel queue Horizon
     * supervises. Losing it doesn't lose data (per the data-ownership
     * rule) but breaks queue dispatch and logs everyone out.
     */
    private function probeRedis(): SystemStatus
    {
        try {
            /** @var mixed $pong */
            $pong = Redis::connection()->command('ping');

            // phpredis returns `true` / `+PONG`, predis returns a
            // Status object stringifying to `PONG`. Treat any of those
            // as OK; anything else as degraded.
            $pongString = is_bool($pong) ? ($pong ? 'PONG' : '') : (string) $pong;
            $ok = $pong === true
                || strcasecmp($pongString, 'PONG') === 0
                || strcasecmp($pongString, '+PONG') === 0;

            if (! $ok) {
                return new SystemStatus(
                    name: 'Redis',
                    level: SystemStatusLevel::DEGRADED,
                    detail: 'Unexpected PING reply: '.$pongString,
                );
            }

            return new SystemStatus(
                name: 'Redis',
                level: SystemStatusLevel::OK,
                detail: 'Cache, sessions, queues',
            );
        } catch (Throwable $e) {
            return new SystemStatus(
                name: 'Redis',
                level: SystemStatusLevel::DOWN,
                detail: $this->trimMessage($e->getMessage()),
            );
        }
    }

    /**
     * Horizon supervises the Laravel queue. If no master supervisor is
     * registered in Redis, Horizon isn't running — jobs will pile up
     * but not execute. Surfaces as orange (degraded): the app still
     * serves traffic, but background work is stalled.
     */
    private function probeHorizon(): SystemStatus
    {
        $repositoryClass = 'Laravel\\Horizon\\Contracts\\MasterSupervisorRepository';
        if (! interface_exists($repositoryClass) && ! class_exists($repositoryClass)) {
            return new SystemStatus(
                name: 'Horizon',
                level: SystemStatusLevel::UNKNOWN,
                detail: 'Horizon not installed',
            );
        }

        try {
            /** @var object $repository */
            $repository = app($repositoryClass);
            /** @var array<int, object>|\Countable $masters */
            $masters = $repository->all();
            $count = is_countable($masters) ? count($masters) : 0;

            if ($count === 0) {
                return new SystemStatus(
                    name: 'Horizon',
                    level: SystemStatusLevel::DEGRADED,
                    detail: 'No master supervisor running',
                );
            }

            return new SystemStatus(
                name: 'Horizon',
                level: SystemStatusLevel::OK,
                detail: $count.' master'.($count === 1 ? '' : 's').' running',
            );
        } catch (Throwable $e) {
            return new SystemStatus(
                name: 'Horizon',
                level: SystemStatusLevel::DOWN,
                detail: $this->trimMessage($e->getMessage()),
            );
        }
    }

    /**
     * OpenSearch — derived search/aggregation store. Cluster health has
     * its own colours (green/yellow/red) that map nicely to ours:
     *   green  → OK
     *   yellow → DEGRADED (writable, some shards unassigned)
     *   red    → DOWN (some indices unavailable)
     */
    private function probeOpenSearch(): SystemStatus
    {
        $host = (string) config('aegiscore.opensearch.host');
        if ($host === '') {
            return new SystemStatus(
                name: 'OpenSearch',
                level: SystemStatusLevel::UNKNOWN,
                detail: 'Host not configured',
            );
        }

        try {
            $response = Http::timeout(self::PROBE_TIMEOUT_SECONDS)
                ->connectTimeout(self::PROBE_TIMEOUT_SECONDS)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(rtrim($host, '/').'/_cluster/health');

            if (! $response->successful()) {
                return new SystemStatus(
                    name: 'OpenSearch',
                    level: SystemStatusLevel::DOWN,
                    detail: 'HTTP '.$response->status(),
                );
            }

            $status = strtolower((string) $response->json('status', 'unknown'));
            $level = match ($status) {
                'green' => SystemStatusLevel::OK,
                'yellow' => SystemStatusLevel::DEGRADED,
                'red' => SystemStatusLevel::DOWN,
                default => SystemStatusLevel::UNKNOWN,
            };

            return new SystemStatus(
                name: 'OpenSearch',
                level: $level,
                detail: 'Cluster '.$status,
            );
        } catch (Throwable $e) {
            return new SystemStatus(
                name: 'OpenSearch',
                level: SystemStatusLevel::DOWN,
                detail: $this->trimMessage($e->getMessage()),
            );
        }
    }

    /**
     * InfluxDB 2.x — derived metrics store. `/ping` is unauthenticated
     * and returns 204 No Content with X-Influxdb-Version header when
     * the server is up.
     */
    private function probeInfluxDb(): SystemStatus
    {
        $host = (string) config('aegiscore.influxdb.host');
        if ($host === '') {
            return new SystemStatus(
                name: 'InfluxDB',
                level: SystemStatusLevel::UNKNOWN,
                detail: 'Host not configured',
            );
        }

        try {
            $response = Http::timeout(self::PROBE_TIMEOUT_SECONDS)
                ->connectTimeout(self::PROBE_TIMEOUT_SECONDS)
                ->get(rtrim($host, '/').'/ping');

            $status = $response->status();
            // Influx returns 204 on healthy ping; accept any 2xx though.
            if ($status < 200 || $status >= 300) {
                return new SystemStatus(
                    name: 'InfluxDB',
                    level: SystemStatusLevel::DOWN,
                    detail: 'HTTP '.$status,
                );
            }

            $version = $response->header('X-Influxdb-Version');

            return new SystemStatus(
                name: 'InfluxDB',
                level: SystemStatusLevel::OK,
                detail: $version !== '' ? 'v'.$version : 'Ping OK',
            );
        } catch (Throwable $e) {
            return new SystemStatus(
                name: 'InfluxDB',
                level: SystemStatusLevel::DOWN,
                detail: $this->trimMessage($e->getMessage()),
            );
        }
    }

    /**
     * Neo4j — derived graph store. Two-stage probe:
     *
     *   1. TCP reach on the Bolt port (1s timeout). Bounds the total
     *      probe time so a dead host can't drag the whole widget past
     *      its cache TTL. A failed TCP check also dodges the longer
     *      default timeouts the Bolt driver would otherwise inherit.
     *   2. If TCP is up AND credentials are configured, run
     *      `RETURN 1 AS ok` via {@link https://github.com/neo4j-php/neo4j-php-client}
     *      (already in composer.json). This catches the "Bolt port
     *      answers but the query planner is hung / auth is wrong"
     *      degraded case the old TCP-only check silently marked green.
     *
     * Three-state output:
     *   TCP fails                    → DOWN       (red)
     *   TCP ok, Cypher throws        → DEGRADED   (orange)
     *   TCP ok, RETURN 1 === 1       → OK         (green)
     *   TCP ok, no credentials       → OK with a note that only TCP
     *                                   was checked (phase-1 default
     *                                   .env ships with NEO4J_PASSWORD
     *                                   blank).
     *
     * Still read-only — AGENTS.md "Laravel does not write to Neo4j"
     * is about domain projection, not health probes; this is the same
     * pattern we use for MariaDB (`SELECT 1`).
     */
    private function probeNeo4j(): SystemStatus
    {
        try {
            $host = (string) config('aegiscore.neo4j.host');
            if ($host === '') {
                return new SystemStatus(
                    name: 'Neo4j',
                    level: SystemStatusLevel::UNKNOWN,
                    detail: 'Host not configured',
                );
            }

            // parse_url returns false for malformed URLs — guard so we
            // don't hit "Cannot access offset of type string on bool".
            $parsed = parse_url($host);
            if (! is_array($parsed)) {
                return new SystemStatus(
                    name: 'Neo4j',
                    level: SystemStatusLevel::UNKNOWN,
                    detail: 'Invalid host: '.$host,
                );
            }

            $hostname = $parsed['host'] ?? null;
            $port = $parsed['port'] ?? 7687;
            if (! is_string($hostname) || $hostname === '') {
                return new SystemStatus(
                    name: 'Neo4j',
                    level: SystemStatusLevel::UNKNOWN,
                    detail: 'Invalid host: '.$host,
                );
            }

            // Stage 1 — bounded TCP reachability.
            $tcpError = $this->tcpReach($hostname, (int) $port);
            if ($tcpError !== null) {
                return new SystemStatus(
                    name: 'Neo4j',
                    level: SystemStatusLevel::DOWN,
                    detail: $this->trimMessage($tcpError),
                );
            }

            // Stage 2 — Cypher ping (skipped when we don't have
            // credentials; fall back to the TCP-only signal and flag
            // it in the detail line so ops knows it's shallow).
            $user = (string) config('aegiscore.neo4j.user');
            $password = (string) config('aegiscore.neo4j.password');
            if ($user === '' || $password === '') {
                return new SystemStatus(
                    name: 'Neo4j',
                    level: SystemStatusLevel::OK,
                    detail: 'Bolt '.$hostname.':'.$port.' (unauthenticated check)',
                );
            }

            if (! class_exists('Laudis\\Neo4j\\ClientBuilder')) {
                // Shouldn't happen — laudis/neo4j-php-client is in
                // composer.json — but better to degrade than to fatal
                // on a missing class.
                return new SystemStatus(
                    name: 'Neo4j',
                    level: SystemStatusLevel::OK,
                    detail: 'Bolt '.$hostname.':'.$port.' (client missing)',
                );
            }

            return $this->cypherPing($host, $hostname, (int) $port, $user, $password);
        } catch (Throwable $e) {
            return new SystemStatus(
                name: 'Neo4j',
                level: SystemStatusLevel::DOWN,
                detail: $this->trimMessage($e->getMessage()),
            );
        }
    }

    /**
     * Bounded TCP reach check. Returns null on success, a short error
     * message on failure. Installs a scoped error handler so strict
     * reporters (laravel/pail etc.) can't promote the fsockopen warning
     * to an unhandled exception.
     */
    private function tcpReach(string $hostname, int $port): ?string
    {
        $suppressed = null;
        set_error_handler(static function (int $_, string $message) use (&$suppressed): bool {
            $suppressed = $message;

            return true;
        });

        try {
            $errno = 0;
            $errstr = '';
            $socket = fsockopen($hostname, $port, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            return $errstr !== '' ? $errstr : ($suppressed ?? 'Connection refused');
        }

        fclose($socket);

        return null;
    }

    /**
     * Run `RETURN 1 AS ok` via the laudis Neo4j client. Any throwable
     * (auth failure, Bolt handshake error, query timeout) downgrades to
     * DEGRADED — the port is reachable, so something's half-alive
     * rather than completely dead.
     *
     * `$host` is the full connection URL (e.g. `bolt://neo4j:7687`) the
     * driver needs; `$hostname` / `$port` are just for the human-readable
     * detail line.
     */
    private function cypherPing(string $host, string $hostname, int $port, string $user, string $password): SystemStatus
    {
        try {
            $client = \Laudis\Neo4j\ClientBuilder::create()
                ->withDriver('health', $host, \Laudis\Neo4j\Authentication\Authenticate::basic($user, $password))
                ->withDefaultDriver('health')
                ->build();

            $started = microtime(true);
            $result = $client->run('RETURN 1 AS ok');
            $elapsedMs = (int) ((microtime(true) - $started) * 1000);

            $row = $result->first();
            $ok = $row->get('ok');

            if ((int) $ok !== 1) {
                return new SystemStatus(
                    name: 'Neo4j',
                    level: SystemStatusLevel::DEGRADED,
                    detail: 'Unexpected RETURN 1 reply: '.(is_scalar($ok) ? (string) $ok : gettype($ok)),
                );
            }

            return new SystemStatus(
                name: 'Neo4j',
                level: SystemStatusLevel::OK,
                detail: 'Cypher '.$hostname.':'.$port.' ('.$elapsedMs.' ms)',
            );
        } catch (Throwable $e) {
            return new SystemStatus(
                name: 'Neo4j',
                level: SystemStatusLevel::DEGRADED,
                detail: 'Bolt reachable, query failed: '.$this->trimMessage($e->getMessage()),
            );
        }
    }

    /**
     * Keep error details short enough that a stat card's description
     * line doesn't wrap into ugly multi-line territory.
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
