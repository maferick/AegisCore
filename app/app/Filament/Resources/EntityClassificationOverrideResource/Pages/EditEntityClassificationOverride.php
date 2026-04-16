<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntityClassificationOverrideResource\Pages;

use App\Filament\Resources\EntityClassificationOverrideResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page for /admin/entity-classification-overrides/{id}/edit.
 *
 * The form pins scope, viewer_context_id, target_entity_type, and
 * target_entity_id on edit because they're part of the uniqueness key.
 * Changing any of them would effectively create a different row —
 * delete + recreate is the supported path for that.
 */
class EditEntityClassificationOverride extends EditRecord
{
    protected static string $resource = EntityClassificationOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
