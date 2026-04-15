<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoalitionEntityLabelResource\Pages;

use App\Filament\Resources\CoalitionEntityLabelResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for /admin/coalition-entity-labels/create.
 *
 * No mutations needed beyond the form defaults — the resource's form
 * already handles raw_label autofill and source defaulting to 'manual'.
 * Uniqueness is enforced at the DB level by
 * `uniq_coalition_labels_entity_raw_src` (on entity_type, entity_id,
 * raw_label, source), so an accidental duplicate surfaces as a
 * constraint violation rather than a silent second row.
 */
class CreateCoalitionEntityLabel extends CreateRecord
{
    protected static string $resource = CoalitionEntityLabelResource::class;
}
