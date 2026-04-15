<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoalitionEntityLabelResource\Pages;

use App\Filament\Resources\CoalitionEntityLabelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page for /admin/coalition-entity-labels/{id}/edit.
 *
 * The form blocks changes to entity_type and entity_id on edit (see
 * resource form comment) so the row's identity stays stable. Everything
 * else — bloc, relationship, source, is_active, raw_label — can be
 * edited.
 */
class EditCoalitionEntityLabel extends EditRecord
{
    protected static string $resource = CoalitionEntityLabelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
