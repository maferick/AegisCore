<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntityClassificationOverrideResource\Pages;

use App\Filament\Resources\EntityClassificationOverrideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Index page for /admin/entity-classification-overrides. Resource-level
 * `table()` does the heavy lifting; this page plugs in the "New
 * override" header button.
 */
class ListEntityClassificationOverrides extends ListRecords
{
    protected static string $resource = EntityClassificationOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
