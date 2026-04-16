<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class AccountIdentity extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Identity';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Identity & Coalition';

    protected string $view = 'filament.portal.pages.account-identity';
}
