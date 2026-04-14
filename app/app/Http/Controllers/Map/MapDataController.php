<?php

declare(strict_types=1);

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Controller;
use App\Http\Requests\Map\MapDataRequest;
use App\Reference\Map\Contracts\MapDataProvider;
use App\Reference\Map\Data\MapOptions;
use App\Reference\Map\Data\UniverseRequest;
use App\Reference\Map\Enums\MapScope;
use App\Reference\Map\Enums\ProjectionMode;
use App\Reference\Map\Enums\UniverseDetail;
use Illuminate\Http\JsonResponse;

/**
 * Public JSON endpoint for the map renderer.
 *
 * Routed as `GET /internal/map/{scope}` with `throttle:60,1`. Returns
 * the same `MapPayload` JSON for every scope — only the populated
 * collections (systems / jumps / regions / constellations / stations)
 * vary by scope.
 *
 * "internal" in the path is a soft signal to ops that this is meant
 * for the AegisCore renderer; it is intentionally unauthenticated
 * because all data is SDE-derived (CCP-public).
 */
class MapDataController extends Controller
{
    public function __invoke(MapDataRequest $request, MapDataProvider $provider): JsonResponse
    {
        $scope = $request->routeScope();
        $options = $this->buildOptions($request);

        $payload = match ($scope) {
            MapScope::UNIVERSE => $provider->getUniverse(new UniverseRequest(
                detail: UniverseDetail::from((string) ($request->input('detail') ?? UniverseDetail::AGGREGATED->value)),
                options: $options,
                regionIds: array_map('intval', (array) $request->input('region_ids', [])),
            )),
            MapScope::REGION => $provider->getRegion(
                regionId: (int) $request->input('region_id'),
                options: $options,
            ),
            MapScope::CONSTELLATION => $provider->getConstellation(
                constellationId: (int) $request->input('constellation_id'),
                options: $options,
            ),
            MapScope::SUBGRAPH => $provider->getSubgraph(
                systemIds: array_map('intval', (array) $request->input('system_ids', [])),
                hops: (int) ($request->input('hops') ?? 0),
                options: $options,
            ),
        };

        return new JsonResponse($payload->toArray());
    }

    private function buildOptions(MapDataRequest $request): MapOptions
    {
        return new MapOptions(
            projection: ProjectionMode::from((string) ($request->input('projection') ?? ProjectionMode::AUTO->value)),
            includeJumps: $request->boolean('include_jumps', true),
            includeStations: $request->boolean('include_stations', true),
            labelLimit: (int) ($request->input('label_limit') ?? 0),
        );
    }
}
