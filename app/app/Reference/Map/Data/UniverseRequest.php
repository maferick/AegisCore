<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use App\Reference\Map\Enums\UniverseDetail;
use Spatie\LaravelData\Data;

/**
 * Universe-scope request tuning.
 *
 * `detail` chooses between the aggregated region-centroid view (default,
 * fast first paint) and the dense per-system view (~8k nodes + edges).
 *
 * `regionIds`, when non-empty, restricts the dense view to a subset of
 * regions — useful for "show me lowsec and nullsec only" cuts. Ignored
 * for the aggregated view.
 */
class UniverseRequest extends Data
{
    public function __construct(
        public UniverseDetail $detail = UniverseDetail::AGGREGATED,
        public MapOptions $options = new MapOptions(),
        /** @var array<int, int> */
        public array $regionIds = [],
    ) {}
}
