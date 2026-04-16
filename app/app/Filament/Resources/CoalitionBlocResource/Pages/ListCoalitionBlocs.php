<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoalitionBlocResource\Pages;

use App\Filament\Resources\CoalitionBlocResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCoalitionBlocs extends ListRecords
{
    protected static string $resource = CoalitionBlocResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
