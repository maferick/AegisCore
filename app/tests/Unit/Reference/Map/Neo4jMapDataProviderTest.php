<?php

declare(strict_types=1);

namespace Tests\Unit\Reference\Map;

use App\Reference\Map\Data\MapOptions;
use App\Reference\Map\Data\UniverseRequest;
use App\Reference\Map\Enums\MapScope;
use App\Reference\Map\Enums\ProjectionMode;
use App\Reference\Map\Enums\UniverseDetail;
use App\Reference\Map\Neo4jMapDataProvider;
use ArrayIterator;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SummarizedResult;
use Mockery;
use Tests\TestCase;

/**
 * Drives Neo4jMapDataProvider against a mocked Laudis client. We don't
 * need a live Neo4j here — every Cypher round trip is intercepted and
 * the focus is on the assembly logic: 2D projection, bbox, station +
 * jump rollups, hops clamping.
 */
final class Neo4jMapDataProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_region_assembles_systems_jumps_and_top_down_projection(): void
    {
        $client = Mockery::mock(ClientInterface::class);

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(r:Region \{id: \$rid\}\)<-\[:IN_REGION\]/'), ['rid' => 10000002])
            ->andReturn($this->fakeRows([
                $this->systemRow(30000142, 'Jita', [
                    'region_id' => 10000002, 'constellation_id' => 20000020,
                    'security_status' => 0.94, 'security_class' => 'B',
                    'hub' => true, 'position_x' => 100.0, 'position_z' => -200.0,
                ], stationCount: 4),
                $this->systemRow(30000144, 'Perimeter', [
                    'region_id' => 10000002, 'constellation_id' => 20000020,
                    'security_status' => 0.91, 'security_class' => 'B',
                    'hub' => false, 'position_x' => 50.0, 'position_z' => -100.0,
                ], stationCount: 1),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(c:Constellation\)-\[:IN_REGION\]/'), ['rid' => 10000002])
            ->andReturn($this->fakeRows([
                $this->constellationRow(20000020, 'Kimotoro', 10000002),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(r:Region \{id: \$rid\}\) RETURN r/'), ['rid' => 10000002])
            ->andReturn($this->fakeRows([
                $this->regionRow(10000002, 'The Forge'),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(s1:System \{region_id: \$rid\}\)-\[:JUMPS_TO\]/'), ['rid' => 10000002])
            ->andReturn($this->fakeRows([
                $this->edgeRow(30000142, 30000144),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(s:System \{region_id: \$rid\}\)-\[:HAS_STATION\]/'), ['rid' => 10000002])
            ->andReturn($this->fakeRows([
                $this->stationRow(60003760, 30000142),
            ]));

        $provider = new Neo4jMapDataProvider($client);
        $payload = $provider->getRegion(10000002, new MapOptions(projection: ProjectionMode::TOP_DOWN_XZ));

        self::assertSame(MapScope::REGION, $payload->scope);
        self::assertCount(2, $payload->systems);
        self::assertCount(1, $payload->jumps);
        self::assertCount(1, $payload->stations);
        self::assertCount(1, $payload->regions);
        self::assertCount(1, $payload->constellations);

        // TOP_DOWN_XZ → (x, -z). Jita at x=100, z=-200 → (100, 200).
        $jita = $payload->systems[0];
        self::assertSame(30000142, $jita->id);
        self::assertSame(100.0, $jita->x);
        self::assertSame(200.0, $jita->y);
        self::assertTrue($jita->hub);
        self::assertSame(4, $jita->stationsCount);
    }

    public function test_get_region_with_position_2d_uses_2d_columns(): void
    {
        $client = Mockery::mock(ClientInterface::class);

        $client->shouldReceive('run')
            ->andReturnUsing(function (string $cypher) {
                if (str_contains($cypher, 'OPTIONAL MATCH (s)-[:HAS_STATION]')
                    && str_contains($cypher, 'IN_REGION')) {
                    return $this->fakeRows([
                        $this->systemRow(30000142, 'Jita', [
                            'region_id' => 10000002,
                            'constellation_id' => 20000020,
                            'security_status' => 0.94,
                            'security_class' => 'B',
                            'hub' => true,
                            'position_x' => 100.0,
                            'position_z' => -200.0,
                            'position2d_x' => 7.5,
                            'position2d_y' => -3.25,
                        ], stationCount: 1),
                    ]);
                }

                return $this->fakeRows([]);
            });

        $provider = new Neo4jMapDataProvider($client);
        $payload = $provider->getRegion(10000002, new MapOptions(projection: ProjectionMode::POSITION_2D));

        self::assertCount(1, $payload->systems);
        self::assertSame(7.5, $payload->systems[0]->x);
        self::assertSame(-3.25, $payload->systems[0]->y);
    }

    public function test_get_subgraph_clamps_hops_and_passes_anchor_ids(): void
    {
        $client = Mockery::mock(ClientInterface::class);

        // Hops > 4 should clamp to 4 in the variable-length pattern.
        $client->shouldReceive('run')
            ->with(Mockery::pattern('/JUMPS_TO\*1\.\.4/'), Mockery::on(function (array $p) {
                return ($p['ids'] ?? null) === [30000142, 30045349];
            }))
            ->andReturn($this->fakeRows([
                $this->idRow(30000142),
                $this->idRow(30045349),
                $this->idRow(30002659),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/^MATCH \(s:System\) WHERE s.id IN \$ids /'), Mockery::on(function (array $p) {
                return count($p['ids'] ?? []) === 3;
            }))
            ->andReturn($this->fakeRows([
                $this->systemRow(30000142, 'Jita', []),
                $this->systemRow(30045349, 'Thera', []),
                $this->systemRow(30002659, 'Dodixie', []),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(s1:System\)-\[:JUMPS_TO\]/'), Mockery::any())
            ->andReturn($this->fakeRows([
                $this->edgeRow(30000142, 30002659),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(s:System\)-\[:HAS_STATION\]/'), Mockery::any())
            ->andReturn($this->fakeRows([]));

        $provider = new Neo4jMapDataProvider($client);
        $payload = $provider->getSubgraph([30000142, 30045349], hops: 99, options: new MapOptions());

        self::assertSame(MapScope::SUBGRAPH, $payload->scope);
        self::assertCount(3, $payload->systems);
        self::assertCount(1, $payload->jumps);
    }

    public function test_get_subgraph_with_hops_zero_skips_expansion_query(): void
    {
        $client = Mockery::mock(ClientInterface::class);

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/JUMPS_TO\*/'), Mockery::any())
            ->never();

        // System fetch with the anchor IDs.
        $client->shouldReceive('run')
            ->with(Mockery::pattern('/^MATCH \(s:System\) WHERE s.id IN \$ids /'), Mockery::on(function (array $p) {
                return ($p['ids'] ?? null) === [30000142];
            }))
            ->andReturn($this->fakeRows([
                $this->systemRow(30000142, 'Jita', []),
            ]));

        // Both jumps + stations follow-ups return empty.
        $client->shouldReceive('run')->andReturn($this->fakeRows([]));

        $provider = new Neo4jMapDataProvider($client);
        $payload = $provider->getSubgraph([30000142], hops: 0, options: new MapOptions());

        self::assertCount(1, $payload->systems);
    }

    public function test_get_subgraph_returns_empty_payload_for_empty_anchor_set(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('run');

        $provider = new Neo4jMapDataProvider($client);
        $payload = $provider->getSubgraph([], hops: 2, options: new MapOptions());

        self::assertSame(MapScope::SUBGRAPH, $payload->scope);
        self::assertCount(0, $payload->systems);
        self::assertCount(0, $payload->jumps);
    }

    public function test_get_universe_aggregated_emits_region_centroids_and_inter_region_edges(): void
    {
        $client = Mockery::mock(ClientInterface::class);

        $client->shouldReceive('run')
            ->withArgs(fn (string $cypher): bool => str_contains($cypher, 'MATCH (r:Region)')
                && str_contains($cypher, 'OPTIONAL MATCH (r)<-[:IN_REGION]'))
            ->andReturn($this->fakeRows([
                $this->regionRowWithCount(10000002, 'The Forge', 423),
                $this->regionRowWithCount(10000043, 'Domain', 379),
            ]));

        $client->shouldReceive('run')
            ->withArgs(fn (string $cypher): bool => str_contains($cypher, 'MATCH (r1:Region)<-[:IN_REGION]'))
            ->andReturn($this->fakeRows([
                $this->edgeRowWithWeight(10000002, 10000043, 5),
            ]));

        $provider = new Neo4jMapDataProvider($client);
        $payload = $provider->getUniverse(new UniverseRequest(detail: UniverseDetail::AGGREGATED));

        self::assertSame(MapScope::UNIVERSE, $payload->scope);
        self::assertCount(2, $payload->regions);
        self::assertCount(0, $payload->systems);
        self::assertCount(1, $payload->jumps);
        self::assertSame(423, $payload->regions[0]->systemCount);
        self::assertSame('region', $payload->jumps[0]->kind);
    }

    public function test_get_universe_dense_emits_systems_and_jumps(): void
    {
        $client = Mockery::mock(ClientInterface::class);

        $client->shouldReceive('run')
            ->withArgs(fn (string $cypher): bool => str_starts_with($cypher, 'MATCH (s:System) '))
            ->andReturn($this->fakeRows([
                $this->systemRow(30000142, 'Jita', []),
                $this->systemRow(30000144, 'Perimeter', []),
            ]));

        $client->shouldReceive('run')
            ->with(Mockery::pattern('/MATCH \(s1:System\)-\[:JUMPS_TO\]/'), Mockery::any())
            ->andReturn($this->fakeRows([
                $this->edgeRow(30000142, 30000144),
            ]));

        $provider = new Neo4jMapDataProvider($client);
        $payload = $provider->getUniverse(new UniverseRequest(
            detail: UniverseDetail::DENSE,
            options: new MapOptions(includeStations: false),
        ));

        self::assertSame(MapScope::UNIVERSE, $payload->scope);
        self::assertCount(2, $payload->systems);
        self::assertCount(1, $payload->jumps);
        self::assertCount(0, $payload->stations);
    }

    // ------------------------------------------------------------------
    // Mock-row factories
    // ------------------------------------------------------------------

    /**
     * Wrap an array of associative rows into an iterable that mimics
     * the laudis result: `foreach`-able and exposes ->first().
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeRows(array $rows): SummarizedResult
    {
        $records = array_map(
            static fn (array $r) => new class($r) {
                public function __construct(private array $data) {}

                public function get(string $key): mixed
                {
                    return $this->data[$key] ?? null;
                }
            },
            $rows,
        );

        $result = Mockery::mock(SummarizedResult::class);
        $result->shouldReceive('getIterator')->andReturn(new ArrayIterator($records));
        $result->shouldReceive('first')->andReturn($records[0] ?? null);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function systemRow(int $id, string $name, array $extra, int $stationCount = 0): array
    {
        $node = array_merge([
            'id' => $id,
            'name' => $name,
            'region_id' => 0,
            'constellation_id' => 0,
            'security_status' => null,
            'security_class' => null,
            'hub' => false,
            'position_x' => 0.0,
            'position_z' => 0.0,
        ], $extra);

        return ['s' => $node, 'station_count' => $stationCount];
    }

    /** @return array<string, mixed> */
    private function regionRow(int $id, string $name): array
    {
        return [
            'r' => [
                'id' => $id, 'name' => $name,
                'position_x' => 0.0, 'position_z' => 0.0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function regionRowWithCount(int $id, string $name, int $count): array
    {
        return [
            'r' => [
                'id' => $id, 'name' => $name,
                'position_x' => 0.0, 'position_z' => 0.0,
            ],
            'system_count' => $count,
        ];
    }

    /** @return array<string, mixed> */
    private function constellationRow(int $id, string $name, int $regionId): array
    {
        return [
            'c' => [
                'id' => $id, 'name' => $name, 'region_id' => $regionId,
                'position_x' => 0.0, 'position_z' => 0.0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function edgeRow(int $a, int $b): array
    {
        return ['a' => $a, 'b' => $b];
    }

    /** @return array<string, mixed> */
    private function edgeRowWithWeight(int $a, int $b, int $weight): array
    {
        return ['a' => $a, 'b' => $b, 'weight' => $weight];
    }

    /** @return array<string, mixed> */
    private function stationRow(int $id, int $systemId): array
    {
        return [
            'st' => ['id' => $id, 'name' => 'Some Station', 'type_id' => 1529, 'owner_id' => 1000035],
            'sid' => $systemId,
        ];
    }

    /** @return array<string, mixed> */
    private function idRow(int $id): array
    {
        return ['id' => $id];
    }
}
