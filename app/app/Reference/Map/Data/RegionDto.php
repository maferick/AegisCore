<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use Spatie\LaravelData\Data;

/**
 * One region in 2D space.
 *
 * `systemCount` is set when the universe-aggregated view is rendering
 * region centroids; it drives node sizing on the cluster overview.
 */
class RegionDto extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public float $x,
        public float $y,
        public int $systemCount = 0,
    ) {}
}
