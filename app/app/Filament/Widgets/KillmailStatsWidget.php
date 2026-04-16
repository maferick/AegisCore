<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard widget: killmail ingestion + enrichment stats at a glance.
 *
 * Shows total killmails, enrichment progress, and recent ingestion
 * throughput. Queries are lightweight (COUNT + indexed WHERE) and
 * tolerate empty tables gracefully.
 */
class KillmailStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Killmail Pipeline';

    protected ?string $description = 'Ingestion + enrichment progress.';

    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $total = (int) DB::table('killmails')->count();

        if ($total === 0) {
            return [
                Stat::make('Total Killmails', '0')
                    ->description('No killmails ingested yet')
                    ->color('gray')
                    ->icon('heroicon-o-bolt-slash'),
            ];
        }

        $enriched = (int) DB::table('killmails')->whereNotNull('enriched_at')->count();
        $pending = $total - $enriched;
        $enrichedPct = $total > 0 ? round(($enriched / $total) * 100, 1) : 0;

        // Recent ingestion: last 24h.
        $last24h = (int) DB::table('killmails')
            ->where('ingested_at', '>=', now()->subDay())
            ->count();

        // Date range.
        $oldest = DB::table('killmails')->min('killed_at');
        $newest = DB::table('killmails')->max('killed_at');

        $dateRange = $oldest && $newest
            ? substr((string) $oldest, 0, 10).' → '.substr((string) $newest, 0, 10)
            : '—';

        // Total ISK destroyed (enriched killmails only).
        $totalIsk = DB::table('killmails')
            ->whereNotNull('enriched_at')
            ->sum('total_value');
        $totalIskFormatted = self::formatIsk((float) $totalIsk);

        return [
            Stat::make('Total Killmails', number_format($total))
                ->description($dateRange)
                ->color('primary')
                ->icon('heroicon-o-bolt'),

            Stat::make('Enriched', number_format($enriched)." ({$enrichedPct}%)")
                ->description($pending > 0 ? number_format($pending).' pending' : 'All enriched')
                ->color($pending > 0 ? 'warning' : 'success')
                ->icon($pending > 0 ? 'heroicon-o-clock' : 'heroicon-o-check-circle'),

            Stat::make('Last 24h', number_format($last24h))
                ->description('Killmails ingested')
                ->color($last24h > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-arrow-trending-up'),

            Stat::make('ISK Destroyed', $totalIskFormatted)
                ->description('Total enriched value')
                ->color('danger')
                ->icon('heroicon-o-fire'),
        ];
    }

    private static function formatIsk(float $isk): string
    {
        if ($isk >= 1_000_000_000_000) {
            return number_format($isk / 1_000_000_000_000, 1).'T';
        }
        if ($isk >= 1_000_000_000) {
            return number_format($isk / 1_000_000_000, 1).'B';
        }
        if ($isk >= 1_000_000) {
            return number_format($isk / 1_000_000, 1).'M';
        }

        return number_format($isk, 0);
    }
}
