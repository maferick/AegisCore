<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class Standings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Standings';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 52;

    protected static ?string $title = 'Corp & Alliance Standings';

    protected string $view = 'filament.portal.pages.standings';
}
