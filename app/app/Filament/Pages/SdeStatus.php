<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Reference\Models\SdeVersionCheck;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * /admin/sde-status — ops view of SDE version-drift history.
 *
 * Embeds {@see \App\Filament\Widgets\SdeVersionStatusWidget} for the
 * current-status card and paginates the raw `sde_version_checks` table
 * below it. Lives under the "Settings" navigation group since it's an
 * ops/admin concern, not per-pillar domain data.
 */
class SdeStatus extends Page
{
    protected string $view = 'filament.pages.sde-status';

    protected static ?string $title = 'SDE Status';

    protected static ?string $navigationLabel = 'SDE Status';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'sde-status';

    /**
     * Page-level widgets. The dashboard widget is reused here so
     * operators landing on /admin/sde-status see the same status card
     * without scrolling back to the dashboard.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\SdeVersionStatusWidget::class,
        ];
    }

    /**
     * Full history, newest first. Passed to the Blade view.
     *
     * @return LengthAwarePaginator<int, SdeVersionCheck>
     */
    public function getChecks(): LengthAwarePaginator
    {
        return SdeVersionCheck::query()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->paginate(25);
    }
}
