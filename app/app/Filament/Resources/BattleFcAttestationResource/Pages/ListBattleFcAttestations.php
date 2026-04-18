<?php

declare(strict_types=1);

namespace App\Filament\Resources\BattleFcAttestationResource\Pages;

use App\Filament\Resources\BattleFcAttestationResource;
use Filament\Resources\Pages\ListRecords;

class ListBattleFcAttestations extends ListRecords
{
    protected static string $resource = BattleFcAttestationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
