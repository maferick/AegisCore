<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoalitionEntityLabelResource\Pages;

use App\Filament\Resources\CoalitionEntityLabelResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for /admin/coalition-entity-labels/create.
 *
 * Auto-resolves entity_name from ESI when the admin leaves it blank.
 */
class CreateCoalitionEntityLabel extends CreateRecord
{
    protected static string $resource = CoalitionEntityLabelResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['entity_name']) && ! empty($data['entity_id'])) {
            $data['entity_name'] = CoalitionEntityLabelResource::resolveEntityName(
                (int) $data['entity_id'],
            );
        }

        return $data;
    }
}
