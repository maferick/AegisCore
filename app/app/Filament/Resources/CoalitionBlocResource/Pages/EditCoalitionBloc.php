<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoalitionBlocResource\Pages;

use App\Filament\Resources\CoalitionBlocResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCoalitionBloc extends EditRecord
{
    protected static string $resource = CoalitionBlocResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
