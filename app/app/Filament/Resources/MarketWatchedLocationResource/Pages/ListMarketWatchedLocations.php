<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketWatchedLocationResource\Pages;

use App\Filament\Resources\MarketWatchedLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Index page for /admin/market-watched-locations.
 *
 * Nothing fancy — the Resource's `table()` does all the work. The
 * "New" button at the top sends the operator to the Create page.
 */
class ListMarketWatchedLocations extends ListRecords
{
    protected static string $resource = MarketWatchedLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
