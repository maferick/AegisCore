<?php

declare(strict_types=1);

namespace App\Reference\Map\Enums;

/**
 * Which 2D projection the data provider hands the renderer.
 *
 * `TOP_DOWN_XZ` discards `position_y` and uses `(x, -z)` per the CCP
 * "Map Data" reference — the same projection used by community maps.
 *
 * `POSITION_2D` uses CCP's hand-laid schematic positions
 * (`position2d_x`, `position2d_y`); these are nicer for region maps
 * because CCP reorganises clutter, but they aren't always populated.
 *
 * `AUTO` picks `POSITION_2D` when both 2D columns are populated for
 * every system in the result, falling back to `TOP_DOWN_XZ` otherwise.
 * This keeps callers from having to know which dataset they have.
 */
enum ProjectionMode: string
{
    case TOP_DOWN_XZ = 'top_down_xz';
    case POSITION_2D = 'position_2d';
    case AUTO = 'auto';
}
