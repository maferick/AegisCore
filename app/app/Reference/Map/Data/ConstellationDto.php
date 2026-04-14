<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use Spatie\LaravelData\Data;

class ConstellationDto extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $regionId,
        public float $x,
        public float $y,
    ) {}
}
