<?php

declare(strict_types=1);

namespace App\Reference\Map\Enums;

/**
 * Top-level scope of a map render.
 *
 * `UNIVERSE` and `REGION` / `CONSTELLATION` map onto Cypher queries
 * that the renderer's data provider issues; `SUBGRAPH` lets callers
 * supply an arbitrary set of system IDs (e.g. a route, a doctrine
 * travel path, a battle theatre) and get just those systems back.
 */
enum MapScope: string
{
    case UNIVERSE = 'universe';
    case REGION = 'region';
    case CONSTELLATION = 'constellation';
    case SUBGRAPH = 'subgraph';
}
