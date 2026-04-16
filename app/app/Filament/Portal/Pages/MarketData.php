<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class MarketData extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Market Data';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 51;

    protected static ?string $title = 'Market Data & Structures';

    protected string $view = 'filament.portal.pages.market-data';
}
