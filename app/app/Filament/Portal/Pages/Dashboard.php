<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Overview';

    protected ?string $heading = 'My Overview';

    protected ?string $subheading = 'Character summary and recent activity.';
}
