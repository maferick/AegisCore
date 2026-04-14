<?php

declare(strict_types=1);

namespace App\Reference\Map\Enums;

/**
 * Granularity of the universe-scope render.
 *
 * `AGGREGATED` (default) returns one node per region positioned at the
 * region centroid plus inter-region jump-edge counts. Fast first paint
 * for the cluster overview.
 *
 * `DENSE` returns every solar system + every stargate edge. ~8000
 * systems + ~7500 edges; perfectly renderable in SVG with the
 * virtualization in `render.js` but markedly heavier than aggregated.
 */
enum UniverseDetail: string
{
    case AGGREGATED = 'aggregated';
    case DENSE = 'dense';
}
