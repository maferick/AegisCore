<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Reference\Models\SdeVersionCheck;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * /admin/sde-status — ops view of SDE version-drift history.
 *
 * Embeds {@see \App\Filament\Widgets\SdeVersionStatusWidget} as a header
 * widget for the current-status card, and renders the raw
 * `sde_version_checks` table below it using Filament's table builder
 * (styled by Filament's own CSS bundle — we deliberately don't ship a
 * Tailwind build step in phase 1).
 *
 * Lives under the "Settings" navigation group since it's an ops/admin
 * concern, not per-pillar domain data.
 */
class SdeStatus extends Page implements HasTable
{
    use InteractsWithTable;

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

    public function table(Table $table): Table
    {
        return $table
            ->query(SdeVersionCheck::query())
            ->defaultSort('checked_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('checked_at')
                    ->label('Checked')
                    ->dateTime('Y-m-d H:i:s')
                    ->description(fn (SdeVersionCheck $r): string => $r->checked_at->diffForHumans())
                    ->sortable(),

                TextColumn::make('pinned_version')
                    ->label('Pinned')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->limit(24)
                    ->tooltip(fn (SdeVersionCheck $r): ?string => $r->pinned_version),

                TextColumn::make('upstream_version')
                    ->label('Upstream')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->limit(24)
                    ->tooltip(fn (SdeVersionCheck $r): ?string => $r->upstream_version),

                IconColumn::make('is_bump_available')
                    ->label('Bump')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-up-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success'),

                TextColumn::make('http_status')
                    ->label('HTTP')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 300 && $state < 400 => 'info',
                        default => 'danger',
                    }),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->placeholder('—')
                    ->wrap()
                    ->color('danger'),
            ])
            ->filters([
                TernaryFilter::make('is_bump_available')
                    ->label('Bumps only')
                    ->placeholder('All checks')
                    ->trueLabel('Bumps only')
                    ->falseLabel('No-ops only'),
            ])
            ->emptyStateHeading('No checks recorded yet')
            ->emptyStateDescription('The first scheduled run lands at 08:00 UTC. Trigger one now with `make sde-check`.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }
}
