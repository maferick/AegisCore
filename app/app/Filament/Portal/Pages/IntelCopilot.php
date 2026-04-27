<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Portal chat page for the Intel Copilot broker.
 *
 * The page itself is a thin shell — every turn of the conversation is
 * handled by the embedded ``<livewire:intel-copilot-chat />`` component.
 * Keeping state off the Filament page avoids the "Livewire cannot
 * serialise ..." trap we hit on ``BattleTheaterDetail``.
 */
class IntelCopilot extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Intel Copilot';

    protected static string|UnitEnum|null $navigationGroup = 'Tools';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Intel Copilot';

    protected static ?string $slug = 'intel-copilot';

    protected string $view = 'filament.portal.pages.intel-copilot';
}
