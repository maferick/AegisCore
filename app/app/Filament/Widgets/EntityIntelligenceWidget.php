<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard widget: entity intelligence from the killmail pipeline.
 *
 * Uses fast queries only — counts from esi_entity_names (small table)
 * and simple COUNT(*) on indexed columns. No expensive COUNT(DISTINCT)
 * over unions that caused timeouts.
 */
class EntityIntelligenceWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Entity Intelligence';

    protected ?string $description = 'Entity name resolution from killmail participants.';

    protected static ?int $sort = 6;

    protected function getStats(): array
    {
        // All counts from esi_entity_names — small table, fast.
        $namesByCategory = DB::table('esi_entity_names')
            ->selectRaw('category, COUNT(*) as cnt')
            ->groupBy('category')
            ->pluck('cnt', 'category');

        $namedChars = (int) ($namesByCategory['character'] ?? 0);
        $namedCorps = (int) ($namesByCategory['corporation'] ?? 0);
        $namedAlliances = (int) ($namesByCategory['alliance'] ?? 0);
        $totalNamed = (int) array_sum($namesByCategory->all());

        if ($totalNamed === 0) {
            return [
                Stat::make('Name Cache', '0')
                    ->description('No entities resolved yet')
                    ->color('gray')
                    ->icon('heroicon-o-user-group'),
            ];
        }

        // Simple fast counts from main tables (indexed, no DISTINCT).
        $totalKillmails = (int) DB::table('killmails')->count();
        $totalAttackers = (int) DB::table('killmail_attackers')->count();

        return [
            Stat::make('Characters', number_format($namedChars))
                ->description('Names resolved')
                ->color($namedChars > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-user'),

            Stat::make('Corporations', number_format($namedCorps))
                ->description('Names resolved')
                ->color($namedCorps > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-building-office'),

            Stat::make('Alliances', number_format($namedAlliances))
                ->description('Names resolved')
                ->color($namedAlliances > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-flag'),

            Stat::make('Name Cache', number_format($totalNamed))
                ->description(number_format($totalKillmails).' kills / '.number_format($totalAttackers).' participants')
                ->color('primary')
                ->icon('heroicon-o-bookmark'),
        ];
    }
}
