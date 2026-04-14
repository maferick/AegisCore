<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use Spatie\LaravelData\Data;

/**
 * One solar system as the renderer sees it.
 *
 * `x` / `y` are the 2D-projected pixel-space-ready coordinates produced
 * by the provider after applying the chosen {@see \App\Reference\Map\Enums\ProjectionMode}.
 * Raw 3D coordinates are not exposed — the renderer never needs them
 * and forwarding them just bloats the JSON payload (~8000 systems).
 */
class SystemDto extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public float $x,
        public float $y,
        public int $regionId,
        public int $constellationId,
        public ?float $securityStatus,
        public ?string $securityClass,
        public bool $hub = false,
        public int $stationsCount = 0,
    ) {}
}
