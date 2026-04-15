<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoalitionEntityLabelResource\Pages;

use App\Filament\Resources\CoalitionEntityLabelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Index page for /admin/coalition-entity-labels. The resource's
 * `table()` does all the work; this page just plugs in the
 * "New label" button.
 */
class ListCoalitionEntityLabels extends ListRecords
{
    protected static string $resource = CoalitionEntityLabelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
