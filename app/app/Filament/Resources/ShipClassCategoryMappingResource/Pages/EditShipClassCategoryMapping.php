<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipClassCategoryMappingResource\Pages;

use App\Filament\Resources\ShipClassCategoryMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShipClassCategoryMapping extends EditRecord
{
    protected static string $resource = ShipClassCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
