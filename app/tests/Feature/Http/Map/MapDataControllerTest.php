<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Map;

use App\Reference\Map\Contracts\MapDataProvider;
use App\Reference\Map\Data\ConstellationDto;
use App\Reference\Map\Data\JumpDto;
use App\Reference\Map\Data\MapOptions;
use App\Reference\Map\Data\MapPayload;
use App\Reference\Map\Data\RegionDto;
use App\Reference\Map\Data\StationDto;
use App\Reference\Map\Data\SystemDto;
use App\Reference\Map\Data\UniverseRequest;
use App\Reference\Map\Enums\MapScope;
use App\Reference\Map\Enums\ProjectionMode;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Covers the public /internal/map/{scope} endpoint.
 *
 * The provider is swapped for an in-test fake so we don't need a live
 * Neo4j; the fake records the calls it received so we can assert that
 * controller params are forwarded correctly per scope.
 */
final class MapDataControllerTest extends TestCase
{
    private FakeMapDataProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeMapDataProvider();
        $this->app->instance(MapDataProvider::class, $this->fake);
    }

    public function test_universe_scope_returns_payload_json(): void
    {
        $this->fake->payload = $this->payload(MapScope::UNIVERSE);

        $response = $this->getJson('/internal/map/universe');

        $response->assertOk()
            ->assertJsonStructure([
                'scope', 'projection', 'bbox',
                'systems', 'jumps', 'regions', 'constellations', 'stations',
                'buildNumber', 'generatedAt',
            ])
            ->assertJsonPath('scope', 'universe');
    }

    public function test_region_scope_requires_region_id(): void
    {
        $this->getJson('/internal/map/region')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['region_id']);
    }

    public function test_region_scope_forwards_params_to_provider(): void
    {
        $this->fake->payload = $this->payload(MapScope::REGION);

        $this->getJson('/internal/map/region?region_id=10000002&include_jumps=0')
            ->assertOk();

        self::assertSame(10000002, $this->fake->lastRegionId);
        self::assertNotNull($this->fake->lastOptions);
        self::assertFalse($this->fake->lastOptions->includeJumps);
    }

    public function test_constellation_scope_requires_constellation_id(): void
    {
        $this->getJson('/internal/map/constellation')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['constellation_id']);
    }

    public function test_subgraph_scope_requires_system_ids(): void
    {
        $this->getJson('/internal/map/subgraph')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['system_ids']);
    }

    public function test_subgraph_scope_clamps_hops_validation(): void
    {
        $this->getJson('/internal/map/subgraph?system_ids[]=30000142&hops=99')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hops']);
    }

    public function test_subgraph_scope_with_hops_in_range_is_accepted(): void
    {
        $this->fake->payload = $this->payload(MapScope::SUBGRAPH);

        $this->getJson('/internal/map/subgraph?system_ids[]=30000142&system_ids[]=30045349&hops=2')
            ->assertOk();

        self::assertSame([30000142, 30045349], $this->fake->lastSystemIds);
        self::assertSame(2, $this->fake->lastHops);
    }

    public function test_unknown_scope_returns_404(): void
    {
        // Catch-all: an unknown scope falls into the controller's
        // routeScope() guard which aborts(404).
        $this->getJson('/internal/map/galaxy')->assertStatus(404);
    }

    public function test_invalid_projection_value_is_rejected(): void
    {
        $this->getJson('/internal/map/universe?projection=hyperboloid')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['projection']);
    }

    private function payload(MapScope $scope): MapPayload
    {
        return new MapPayload(
            scope: $scope,
            projection: ProjectionMode::TOP_DOWN_XZ,
            bbox: [0.0, 0.0, 100.0, 100.0],
            systems: new DataCollection(SystemDto::class, []),
            jumps: new DataCollection(JumpDto::class, []),
            regions: new DataCollection(RegionDto::class, []),
            constellations: new DataCollection(ConstellationDto::class, []),
            stations: new DataCollection(StationDto::class, []),
            buildNumber: 12345,
            generatedAt: '2026-04-14T00:00:00+00:00',
        );
    }
}

/**
 * In-test stand-in. Records what the controller passed and returns
 * the payload set on `$this->payload`.
 */
final class FakeMapDataProvider implements MapDataProvider
{
    public ?MapPayload $payload = null;

    public ?int $lastRegionId = null;

    public ?int $lastConstellationId = null;

    /** @var array<int, int> */
    public array $lastSystemIds = [];

    public int $lastHops = 0;

    public ?MapOptions $lastOptions = null;

    public ?UniverseRequest $lastUniverseRequest = null;

    public function getUniverse(UniverseRequest $request): MapPayload
    {
        $this->lastUniverseRequest = $request;

        return $this->payload ?? throw new \RuntimeException('payload not set');
    }

    public function getRegion(int $regionId, MapOptions $options): MapPayload
    {
        $this->lastRegionId = $regionId;
        $this->lastOptions = $options;

        return $this->payload ?? throw new \RuntimeException('payload not set');
    }

    public function getConstellation(int $constellationId, MapOptions $options): MapPayload
    {
        $this->lastConstellationId = $constellationId;
        $this->lastOptions = $options;

        return $this->payload ?? throw new \RuntimeException('payload not set');
    }

    public function getSubgraph(array $systemIds, int $hops, MapOptions $options): MapPayload
    {
        $this->lastSystemIds = array_values(array_map('intval', $systemIds));
        $this->lastHops = $hops;
        $this->lastOptions = $options;

        return $this->payload ?? throw new \RuntimeException('payload not set');
    }
}
