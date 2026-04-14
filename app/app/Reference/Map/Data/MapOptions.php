<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use App\Reference\Map\Enums\ProjectionMode;
use Spatie\LaravelData\Data;

/**
 * Per-request rendering knobs handed to a {@see \App\Reference\Map\Contracts\MapDataProvider}.
 *
 * Defaults match the renderer's "show me a useful region map" baseline:
 * include jumps + stations, AUTO projection, no label cap.
 */
class MapOptions extends Data
{
    public function __construct(
        public ProjectionMode $projection = ProjectionMode::AUTO,
        public bool $includeJumps = true,
        public bool $includeStations = true,
        /** Optional cap on labels emitted client-side. 0 = no cap. */
        public int $labelLimit = 0,
    ) {}

    public static function default(): self
    {
        return new self();
    }
}
