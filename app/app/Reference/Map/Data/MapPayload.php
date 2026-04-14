<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use App\Reference\Map\Enums\MapScope;
use App\Reference\Map\Enums\ProjectionMode;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Final result of a map data fetch — everything the JS renderer needs.
 *
 * Bbox is `[minX, minY, maxX, maxY]` in the same coordinate space as
 * the system / region / constellation `(x, y)` values. The renderer
 * uses it to fit the viewport on first paint without scanning all
 * nodes.
 *
 * `buildNumber` lets the renderer cross-check that all instances on
 * a page share the same SDE snapshot (otherwise positions could drift
 * if a reload happened mid-render).
 */
class MapPayload extends Data
{
    public function __construct(
        public MapScope $scope,
        public ProjectionMode $projection,
        /** @var array{0:float,1:float,2:float,3:float} */
        public array $bbox,

        /** @var DataCollection<int, SystemDto> */
        #[DataCollectionOf(SystemDto::class)]
        public DataCollection $systems,

        /** @var DataCollection<int, JumpDto> */
        #[DataCollectionOf(JumpDto::class)]
        public DataCollection $jumps,

        /** @var DataCollection<int, RegionDto> */
        #[DataCollectionOf(RegionDto::class)]
        public DataCollection $regions,

        /** @var DataCollection<int, ConstellationDto> */
        #[DataCollectionOf(ConstellationDto::class)]
        public DataCollection $constellations,

        /** @var DataCollection<int, StationDto> */
        #[DataCollectionOf(StationDto::class)]
        public DataCollection $stations,

        public ?int $buildNumber,
        public string $generatedAt,
    ) {}

    /**
     * Compute a bounding box from a list of (x, y) tuples.
     *
     * Returned tuple is padded by 5% of the larger dimension so nodes
     * on the bbox edges aren't clipped by the viewport's own padding.
     *
     * @param  array<int, array{0:float,1:float}>  $points
     * @return array{0:float,1:float,2:float,3:float}
     */
    public static function computeBbox(array $points): array
    {
        if ($points === []) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $minX = $maxX = $points[0][0];
        $minY = $maxY = $points[0][1];

        foreach ($points as [$x, $y]) {
            if ($x < $minX) {
                $minX = $x;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($y < $minY) {
                $minY = $y;
            }
            if ($y > $maxY) {
                $maxY = $y;
            }
        }

        $padX = ($maxX - $minX) * 0.05;
        $padY = ($maxY - $minY) * 0.05;

        return [$minX - $padX, $minY - $padY, $maxX + $padX, $maxY + $padY];
    }
}
