<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\System\DockerContainer;
use App\System\DockerContainerState;
use App\System\DockerStatusService;
use App\System\SystemStatusLevel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the contract the admin Container Status page relies on:
 *   - the probe returns the list of containers the proxy reports;
 *   - Docker's JSON quirks (name prefix, health-suffix parsing) are
 *     normalised to the shape the page renders;
 *   - errors degrade gracefully — the page never 500s;
 *   - caching actually kicks in so widget polling doesn't hammer the
 *     proxy.
 */
final class DockerStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aegiscore.docker.host', 'http://proxy.test:2375');
        config()->set('aegiscore.docker.timeout_seconds', 2);

        Cache::store()->flush();
    }

    public function test_empty_host_returns_unconfigured_snapshot(): void
    {
        config()->set('aegiscore.docker.host', '');

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertFalse($snapshot->configured);
        self::assertFalse($snapshot->isError());
        self::assertSame([], $snapshot->containers);
    }

    public function test_probe_parses_container_list(): void
    {
        Http::fake([
            'proxy.test*' => Http::response([
                [
                    'Id' => 'abc123',
                    'Names' => ['/php-fpm'],
                    'Image' => 'aegiscore/php-fpm:0.1.1',
                    'State' => 'running',
                    'Status' => 'Up 2 hours (healthy)',
                    'Created' => 1_700_000_000,
                ],
                [
                    'Id' => 'def456',
                    'Names' => ['/market_poll_scheduler'],
                    'Image' => 'aegiscore/market-poller:0.1.0',
                    'State' => 'restarting',
                    'Status' => 'Restarting (1) 3 seconds ago',
                    'Created' => 1_700_000_500,
                ],
            ], 200),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertTrue($snapshot->configured);
        self::assertFalse($snapshot->isError());
        self::assertCount(2, $snapshot->containers);

        // Alphabetical — market_poll_scheduler sorts before php-fpm.
        self::assertSame('market_poll_scheduler', $snapshot->containers[0]->name);
        self::assertSame('php-fpm', $snapshot->containers[1]->name);

        $phpFpm = $snapshot->containers[1];
        self::assertInstanceOf(DockerContainer::class, $phpFpm);
        self::assertSame(DockerContainerState::RUNNING, $phpFpm->state);
        self::assertSame('healthy', $phpFpm->healthStatus);
        self::assertSame(SystemStatusLevel::OK, $phpFpm->level());
        self::assertSame(1_700_000_000, $phpFpm->startedAtUnix);
    }

    public function test_unhealthy_running_container_is_degraded_not_ok(): void
    {
        Http::fake([
            'proxy.test*' => Http::response([
                [
                    'Id' => 'abc',
                    'Names' => ['/nginx'],
                    'Image' => 'nginx:1.27-alpine',
                    'State' => 'running',
                    'Status' => 'Up 5 minutes (unhealthy)',
                    'Created' => 1_700_000_000,
                ],
            ], 200),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertCount(1, $snapshot->containers);
        $nginx = $snapshot->containers[0];
        self::assertSame('unhealthy', $nginx->healthStatus);
        self::assertSame(SystemStatusLevel::DOWN, $nginx->level());
    }

    public function test_starting_healthcheck_is_degraded(): void
    {
        Http::fake([
            'proxy.test*' => Http::response([
                [
                    'Id' => 'abc',
                    'Names' => ['/opensearch'],
                    'Image' => 'opensearchproject/opensearch:3.6.0',
                    'State' => 'running',
                    'Status' => 'Up 10 seconds (health: starting)',
                    'Created' => 1_700_000_000,
                ],
            ], 200),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        $opensearch = $snapshot->containers[0];
        self::assertSame('starting', $opensearch->healthStatus);
        self::assertSame(SystemStatusLevel::DEGRADED, $opensearch->level());
    }

    public function test_exited_container_exit_code_is_not_read_as_health(): void
    {
        // Regression — the status string "Exited (137) 5 minutes ago"
        // contains parens, which an overly-eager regex could read as
        // a health suffix. 137 is a number, not a health state —
        // parseHealthFromStatus must ignore it.
        Http::fake([
            'proxy.test*' => Http::response([
                [
                    'Id' => 'abc',
                    'Names' => ['/oneshot'],
                    'Image' => 'aegiscore/market-poller:0.1.0',
                    'State' => 'exited',
                    'Status' => 'Exited (137) 5 minutes ago',
                    'Created' => 1_700_000_000,
                ],
            ], 200),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertNull($snapshot->containers[0]->healthStatus);
        self::assertSame(SystemStatusLevel::DOWN, $snapshot->containers[0]->level());
    }

    public function test_no_healthcheck_declared_leaves_health_null(): void
    {
        Http::fake([
            'proxy.test*' => Http::response([
                [
                    'Id' => 'abc',
                    'Names' => ['/redis'],
                    'Image' => 'redis:7-alpine',
                    'State' => 'running',
                    'Status' => 'Up About an hour',
                    'Created' => 1_700_000_000,
                ],
            ], 200),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        $redis = $snapshot->containers[0];
        self::assertNull($redis->healthStatus);
        // Running + no healthcheck → inherit lifecycle green.
        self::assertSame(SystemStatusLevel::OK, $redis->level());
    }

    public function test_unknown_state_falls_back_to_unknown_enum(): void
    {
        Http::fake([
            'proxy.test*' => Http::response([
                [
                    'Id' => 'abc',
                    'Names' => ['/future'],
                    'Image' => 'busybox',
                    'State' => 'chrono-displaced',
                    'Status' => 'Something novel',
                    'Created' => 1_700_000_000,
                ],
            ], 200),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertSame(DockerContainerState::UNKNOWN, $snapshot->containers[0]->state);
    }

    public function test_http_failure_returns_error_snapshot(): void
    {
        Http::fake([
            'proxy.test*' => Http::response('boom', 503),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertTrue($snapshot->configured);
        self::assertTrue($snapshot->isError());
        self::assertNotNull($snapshot->error);
        self::assertStringContainsString('503', $snapshot->error);
    }

    public function test_network_exception_returns_error_snapshot(): void
    {
        Http::fake([
            'proxy.test*' => fn () => throw new \RuntimeException('connection refused'),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertTrue($snapshot->isError());
        self::assertSame('connection refused', $snapshot->error);
    }

    public function test_non_array_payload_returns_error(): void
    {
        Http::fake([
            'proxy.test*' => Http::response('"oops"', 200, ['Content-Type' => 'application/json']),
        ]);

        $snapshot = app(DockerStatusService::class)->fresh();

        self::assertTrue($snapshot->isError());
    }

    public function test_snapshot_is_cached(): void
    {
        Http::fake([
            'proxy.test*' => Http::sequence()
                ->push([['Id' => 'a', 'Names' => ['/one'], 'Image' => 'x', 'State' => 'running', 'Status' => 'Up 1s', 'Created' => 1]], 200)
                ->push([['Id' => 'b', 'Names' => ['/two'], 'Image' => 'x', 'State' => 'running', 'Status' => 'Up 1s', 'Created' => 2]], 200),
        ]);

        $service = app(DockerStatusService::class);
        $first = $service->snapshot();
        $second = $service->snapshot();

        self::assertEquals($first, $second);
        self::assertSame('one', $first->containers[0]->name);
        self::assertSame('one', $second->containers[0]->name, 'second snapshot must hit the cache, not the second fake response');
    }

    public function test_fresh_bypasses_cache(): void
    {
        Http::fake([
            'proxy.test*' => Http::sequence()
                ->push([['Id' => 'a', 'Names' => ['/one'], 'Image' => 'x', 'State' => 'running', 'Status' => 'Up 1s', 'Created' => 1]], 200)
                ->push([['Id' => 'b', 'Names' => ['/two'], 'Image' => 'x', 'State' => 'running', 'Status' => 'Up 1s', 'Created' => 2]], 200),
        ]);

        $service = app(DockerStatusService::class);

        $first = $service->snapshot();
        self::assertSame('one', $first->containers[0]->name);

        $second = $service->fresh();
        self::assertSame('two', $second->containers[0]->name);
    }

    public function test_summary_counts_by_derived_level(): void
    {
        Http::fake([
            'proxy.test*' => Http::response([
                ['Id' => '1', 'Names' => ['/green'], 'Image' => 'x', 'State' => 'running', 'Status' => 'Up 1h (healthy)', 'Created' => 1],
                ['Id' => '2', 'Names' => ['/green-no-hc'], 'Image' => 'x', 'State' => 'running', 'Status' => 'Up 1h', 'Created' => 1],
                ['Id' => '3', 'Names' => ['/restart'], 'Image' => 'x', 'State' => 'restarting', 'Status' => 'Restarting (1) 1s ago', 'Created' => 1],
                ['Id' => '4', 'Names' => ['/exit'], 'Image' => 'x', 'State' => 'exited', 'Status' => 'Exited (0) 1h ago', 'Created' => 1],
                ['Id' => '5', 'Names' => ['/sick'], 'Image' => 'x', 'State' => 'running', 'Status' => 'Up 1m (unhealthy)', 'Created' => 1],
            ], 200),
        ]);

        $summary = app(DockerStatusService::class)->fresh()->summary();

        self::assertSame(2, $summary['running']);
        self::assertSame(1, $summary['unhealthy']);
        self::assertSame(2, $summary['stopped']);
        self::assertSame(5, $summary['total']);
    }
}
