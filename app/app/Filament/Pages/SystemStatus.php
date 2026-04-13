<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\SystemStatusWidget;
use Filament\Pages\Page;

/**
 * /admin/system-status — operator view of backend health.
 *
 * The whole page is the {@see SystemStatusWidget} rendered full-width.
 * We keep it as a dedicated Page (rather than only a dashboard widget)
 * so operators can deep-link to it during incident response without
 * scrolling past other cards.
 *
 * Lives in the "Monitoring" group alongside Horizon, so everything
 * an operator reaches for when something's wrong is in one place.
 */
class SystemStatus extends Page
{
    protected string $view = 'filament.pages.system-status';

    protected static ?string $title = 'System Status';

    protected static ?string $navigationLabel = 'System Status';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|\UnitEnum|null $navigationGroup = 'Monitoring';

    // Sort lower than Horizon (100) so the glance view is the first
    // monitoring entry an operator sees in the sidebar.
    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'system-status';

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SystemStatusWidget::class,
        ];
    }

    /**
     * Header widgets default to 2 columns; force full-width so the
     * widget's internal 3-column grid can actually lay out.
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
