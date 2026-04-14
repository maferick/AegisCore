<?php

declare(strict_types=1);

namespace Tests\Unit\Reference\Map;

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
use App\Reference\Map\Enums\UniverseDetail;
use App\Reference\Map\Support\MapCache;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class MapCacheTest extends TestCase
{
    public function test_get_universe_replaces_stale_cached_payload_with_fresh_map_payload(): void
    {
        $request = new UniverseRequest(
            detail: UniverseDetail::AGGREGATED,
            options: new MapOptions(
                projection: ProjectionMode::AUTO,
                includeJumps: true,
                includeStations: true,
                labelLimit: 0,
            ),
            regionIds: [],
        );

        $provider = new RecordingMapProvider($this->payload(424242));
        $cache = new MapCache($provider, $this->app['cache'], 'array');

        $argHash = sha1(json_encode([
            'detail' => $request->detail->value,
            'options' => [
                'projection' => $request->options->projection->value,
                'jumps' => $request->options->includeJumps,
                'stations' => $request->options->includeStations,
                'label_limit' => $request->options->labelLimit,
            ],
            'region_ids' => [],
        ], JSON_THROW_ON_ERROR));

        $staleClass = 'Legacy\\MissingMapPayload';
        $stale = unserialize(
            sprintf('O:%d:"%s":0:{}', strlen($staleClass), $staleClass),
            ['allowed_classes' => true],
        );

        $store = $this->app['cache']->store('array');
        $store->forever('map:build_number', 424242);
        $store->forever("map:universe:{$argHash}:424242", $stale);

        $fresh = $cache->getUniverse($request);

        self::assertInstanceOf(MapPayload::class, $fresh);
        self::assertSame(1, $provider->calls);
    }

    private function payload(int $buildNumber): MapPayload
    {
        return new MapPayload(
            scope: MapScope::UNIVERSE,
            projection: ProjectionMode::TOP_DOWN_XZ,
            bbox: [0.0, 0.0, 100.0, 100.0],
            systems: new DataCollection(SystemDto::class, []),
            jumps: new DataCollection(JumpDto::class, []),
            regions: new DataCollection(RegionDto::class, []),
            constellations: new DataCollection(ConstellationDto::class, []),
            stations: new DataCollection(StationDto::class, []),
            buildNumber: $buildNumber,
            generatedAt: '2026-04-14T00:00:00+00:00',
        );
    }
}

final class RecordingMapProvider implements MapDataProvider
{
    public int $calls = 0;

    public function __construct(private readonly MapPayload $payload) {}

    public function getUniverse(UniverseRequest $request): MapPayload
    {
        $this->calls++;

        return $this->payload;
    }

    public function getRegion(int $regionId, MapOptions $options): MapPayload
    {
        return $this->payload;
    }

    public function getConstellation(int $constellationId, MapOptions $options): MapPayload
    {
        return $this->payload;
    }

    public function getSubgraph(array $systemIds, int $hops, MapOptions $options): MapPayload
    {
        return $this->payload;
    }
}
