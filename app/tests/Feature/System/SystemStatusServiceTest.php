<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\System\SystemStatus;
use App\System\SystemStatusLevel;
use App\System\SystemStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the contract the admin widget relies on:
 *   - a snapshot always returns a Status for every backend (one dead
 *     service can't blank the page);
 *   - HTTP-based probes map upstream health to our traffic-light;
 *   - the cache is actually used (widget polling doesn't hammer backends);
 *   - `fresh()` bypasses and rewrites the cache.
 *
 * We don't assert the exact level for Redis / Neo4j / Horizon — those
 * depend on whatever is reachable from the test runner. Instead we
 * assert that a Status is returned and that the service is resilient
 * to them being unavailable.
 */
final class SystemStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin OpenSearch / InfluxDB hosts so Http::fake patterns match
        // regardless of the runner's .env.
        config()->set('aegiscore.opensearch.host', 'http://opensearch.test:9200');
        config()->set('aegiscore.influxdb.host', 'http://influxdb.test:8086');
        config()->set('aegiscore.neo4j.host', 'bolt://neo4j.test:7687');

        // Drop any snapshot cached by an earlier test.
        Cache::store()->flush();
    }

    public function test_snapshot_returns_one_status_per_backend(): void
    {
        Http::fake([
            'opensearch.test*' => Http::response(['status' => 'green'], 200),
            'influxdb.test*' => Http::response('', 204, ['X-Influxdb-Version' => '2.7.0']),
        ]);

        $service = app(SystemStatusService::class);
        $statuses = $service->fresh();

        self::assertCount(6, $statuses);
        $names = array_map(fn (SystemStatus $s): string => $s->name, $statuses);
        self::assertSame(
            ['MariaDB', 'Redis', 'Horizon', 'OpenSearch', 'InfluxDB', 'Neo4j'],
            $names,
        );

        foreach ($statuses as $status) {
            self::assertInstanceOf(SystemStatusLevel::class, $status->level);
        }
    }

    public function test_database_probe_reports_ok_when_connection_works(): void
    {
        Http::fake();

        $db = $this->probeByName('MariaDB');

        self::assertSame(SystemStatusLevel::OK, $db->level);
        self::assertNotNull($db->detail);
        self::assertStringContainsString('Connected', $db->detail);
    }

    public function test_opensearch_yellow_cluster_is_degraded(): void
    {
        Http::fake([
            'opensearch.test*' => Http::response(['status' => 'yellow'], 200),
            'influxdb.test*' => Http::response('', 204),
        ]);

        $status = $this->probeByName('OpenSearch');

        self::assertSame(SystemStatusLevel::DEGRADED, $status->level);
        self::assertSame('Cluster yellow', $status->detail);
    }

    public function test_opensearch_red_cluster_is_down(): void
    {
        Http::fake([
            'opensearch.test*' => Http::response(['status' => 'red'], 200),
            'influxdb.test*' => Http::response('', 204),
        ]);

        self::assertSame(SystemStatusLevel::DOWN, $this->probeByName('OpenSearch')->level);
    }

    public function test_opensearch_http_failure_is_down(): void
    {
        Http::fake([
            'opensearch.test*' => Http::response('boom', 503),
            'influxdb.test*' => Http::response('', 204),
        ]);

        $status = $this->probeByName('OpenSearch');

        self::assertSame(SystemStatusLevel::DOWN, $status->level);
        self::assertSame('HTTP 503', $status->detail);
    }

    public function test_influxdb_ping_success_is_ok(): void
    {
        Http::fake([
            'opensearch.test*' => Http::response(['status' => 'green'], 200),
            'influxdb.test*' => Http::response('', 204, ['X-Influxdb-Version' => '2.7.1']),
        ]);

        $status = $this->probeByName('InfluxDB');

        self::assertSame(SystemStatusLevel::OK, $status->level);
        self::assertSame('v2.7.1', $status->detail);
    }

    public function test_unconfigured_host_is_unknown_not_down(): void
    {
        config()->set('aegiscore.opensearch.host', '');
        Http::fake(['influxdb.test*' => Http::response('', 204)]);

        $status = $this->probeByName('OpenSearch');

        self::assertSame(SystemStatusLevel::UNKNOWN, $status->level);
        self::assertSame('Host not configured', $status->detail);
    }

    public function test_snapshot_is_cached(): void
    {
        Http::fake([
            'opensearch.test*' => Http::response(['status' => 'green'], 200),
            'influxdb.test*' => Http::response('', 204),
        ]);

        $service = app(SystemStatusService::class);
        $first = $service->snapshot();
        $second = $service->snapshot();

        // Identical snapshot back means the cache fed the second call.
        self::assertEquals($first, $second);

        // Count only probes against our HTTP-based backends — one each
        // from the first snapshot, none from the second (served from cache).
        $httpCalls = collect(Http::recorded())->filter(
            fn ($entry) => str_contains((string) $entry[0]->url(), 'opensearch.test')
                || str_contains((string) $entry[0]->url(), 'influxdb.test'),
        )->count();
        self::assertSame(2, $httpCalls);
    }

    public function test_fresh_bypasses_cache(): void
    {
        Http::fake([
            'opensearch.test*' => Http::sequence()
                ->push(['status' => 'green'], 200)
                ->push(['status' => 'red'], 200),
            'influxdb.test*' => Http::response('', 204),
        ]);

        $service = app(SystemStatusService::class);

        $first = $this->byName($service->snapshot(), 'OpenSearch');
        self::assertSame(SystemStatusLevel::OK, $first->level);

        $second = $this->byName($service->fresh(), 'OpenSearch');
        self::assertSame(SystemStatusLevel::DOWN, $second->level);
    }

    public function test_dead_backend_does_not_break_the_snapshot(): void
    {
        // InfluxDB responds with a network failure; the others still
        // need to return a Status.
        Http::fake([
            'opensearch.test*' => Http::response(['status' => 'green'], 200),
            'influxdb.test*' => fn () => throw new \RuntimeException('boom'),
        ]);

        $service = app(SystemStatusService::class);
        $statuses = $service->fresh();

        self::assertCount(6, $statuses);
        $influx = $this->byName($statuses, 'InfluxDB');
        self::assertSame(SystemStatusLevel::DOWN, $influx->level);
    }

    private function probeByName(string $name): SystemStatus
    {
        return $this->byName(app(SystemStatusService::class)->fresh(), $name);
    }

    /**
     * @param  array<int, SystemStatus>  $statuses
     */
    private function byName(array $statuses, string $name): SystemStatus
    {
        foreach ($statuses as $status) {
            if ($status->name === $name) {
                return $status;
            }
        }

        self::fail("No status returned for backend: {$name}");
    }
}
