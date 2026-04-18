<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipClassCategoryMappingResource\Pages;

use App\Filament\Resources\ShipClassCategoryMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShipClassCategoryMapping extends CreateRecord
{
    protected static string $resource = ShipClassCategoryMappingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['computed_at'] ??= now();
        return $data;
    }
}
