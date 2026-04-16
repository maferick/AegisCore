<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Embeds the full interactive Livewire AccountSettings component inside
 * the portal panel layout. This gives users the same interactive
 * features (coalition change, structure search, standings sync) within
 * the portal's sidebar navigation.
 */
class AccountSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Account Settings';

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = 'Account Settings';

    protected string $view = 'filament.portal.pages.account-settings';
}
