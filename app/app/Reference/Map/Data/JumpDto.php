<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use Spatie\LaravelData\Data;

/**
 * One stargate edge between two systems.
 *
 * Order isn't significant — the underlying Neo4j relationship is
 * undirected — but we emit `(a, b)` with `a < b` so deduping client-side
 * is trivial.
 *
 * `kind` is reserved for future overlays (jump bridge, wormhole, jump
 * drive); phase 1 only ships stargate edges and the field is always
 * "stargate".
 */
class JumpDto extends Data
{
    public function __construct(
        public int $a,
        public int $b,
        public string $kind = 'stargate',
    ) {}
}
