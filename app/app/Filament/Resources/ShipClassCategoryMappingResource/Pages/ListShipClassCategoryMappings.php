<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipClassCategoryMappingResource\Pages;

use App\Filament\Resources\ShipClassCategoryMappingResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShipClassCategoryMappings extends ListRecords
{
    protected static string $resource = ShipClassCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('unclassified')
                ->label('Review unclassified ships')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->url(ShipClassCategoryMappingResource::getUrl('unclassified')),
        ];
    }
}
