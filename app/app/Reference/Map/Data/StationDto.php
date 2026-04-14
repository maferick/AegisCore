<?php

declare(strict_types=1);

namespace App\Reference\Map\Data;

use Spatie\LaravelData\Data;

/**
 * NPC station glyph attached to a system.
 *
 * Phase 1 only ships NPC stations (CCP-defined). Player structures
 * arrive in a later phase via ESI ingestion.
 */
class StationDto extends Data
{
    public function __construct(
        public int $id,
        public int $systemId,
        public ?string $name = null,
        public ?int $typeId = null,
        public ?int $ownerId = null,
    ) {}
}
