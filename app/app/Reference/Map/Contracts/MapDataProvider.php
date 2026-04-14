<?php

declare(strict_types=1);

namespace App\Reference\Map\Contracts;

use App\Reference\Map\Data\MapOptions;
use App\Reference\Map\Data\MapPayload;
use App\Reference\Map\Data\UniverseRequest;

/**
 * Source of map data for the renderer module.
 *
 * Implementations:
 *   - {@see \App\Reference\Map\Neo4jMapDataProvider} — production source,
 *     reads from the graph projection produced by the
 *     `graph_universe_sync` Python tool.
 *
 * The contract is intentionally narrow: the controller knows nothing
 * about the underlying store, and a future MariaDB-only fallback can
 * slot in without touching the HTTP / Blade / JS layers.
 */
interface MapDataProvider
{
    public function getUniverse(UniverseRequest $request): MapPayload;

    public function getRegion(int $regionId, MapOptions $options): MapPayload;

    public function getConstellation(int $constellationId, MapOptions $options): MapPayload;

    /**
     * @param  array<int, int>  $systemIds  Anchor systems for the subgraph.
     * @param  int  $hops  How many JUMPS_TO hops to expand outward (0 = anchors only).
     */
    public function getSubgraph(array $systemIds, int $hops, MapOptions $options): MapPayload;
}
