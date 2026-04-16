<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard widget: entity intelligence from the killmail pipeline.
 *
 * Shows how many unique characters, corporations, and alliances have
 * been observed through killmail ingestion, and how many have been
 * name-resolved in the shared entity cache.
 */
class EntityIntelligenceWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Entity Intelligence';

    protected ?string $description = 'Characters, corps, and alliances observed from killmails.';

    protected static ?int $sort = 6;

    protected function getStats(): array
    {
        $kmTotal = (int) DB::table('killmails')->count();

        if ($kmTotal === 0) {
            return [
                Stat::make('Entities', '0')
                    ->description('No killmails ingested yet')
                    ->color('gray')
                    ->icon('heroicon-o-user-group'),
            ];
        }

        // Unique entities from killmails (victims + attackers combined).
        // Using UNION to deduplicate across victim and attacker roles.
        $uniqueCharacters = (int) DB::selectOne("
            SELECT COUNT(DISTINCT cid) as cnt FROM (
                SELECT victim_character_id as cid FROM killmails WHERE victim_character_id IS NOT NULL
                UNION ALL
                SELECT character_id FROM killmail_attackers WHERE character_id IS NOT NULL
            ) t
        ")->cnt;

        $uniqueCorps = (int) DB::selectOne("
            SELECT COUNT(DISTINCT cid) as cnt FROM (
                SELECT victim_corporation_id as cid FROM killmails WHERE victim_corporation_id IS NOT NULL
                UNION ALL
                SELECT corporation_id FROM killmail_attackers WHERE corporation_id IS NOT NULL
            ) t
        ")->cnt;

        $uniqueAlliances = (int) DB::selectOne("
            SELECT COUNT(DISTINCT cid) as cnt FROM (
                SELECT victim_alliance_id as cid FROM killmails WHERE victim_alliance_id IS NOT NULL
                UNION ALL
                SELECT alliance_id FROM killmail_attackers WHERE alliance_id IS NOT NULL
            ) t
        ")->cnt;

        // Name resolution coverage from the shared cache.
        $namesByCategory = DB::table('esi_entity_names')
            ->selectRaw("category, COUNT(*) as cnt")
            ->groupBy('category')
            ->pluck('cnt', 'category');

        $namedChars = (int) ($namesByCategory['character'] ?? 0);
        $namedCorps = (int) ($namesByCategory['corporation'] ?? 0);
        $namedAlliances = (int) ($namesByCategory['alliance'] ?? 0);
        $totalNamed = (int) DB::table('esi_entity_names')->count();

        $charPct = $uniqueCharacters > 0 ? round(($namedChars / $uniqueCharacters) * 100, 1) : 0;
        $corpPct = $uniqueCorps > 0 ? round(($namedCorps / $uniqueCorps) * 100, 1) : 0;
        $alliancePct = $uniqueAlliances > 0 ? round(($namedAlliances / $uniqueAlliances) * 100, 1) : 0;

        return [
            Stat::make('Characters', number_format($uniqueCharacters))
                ->description("{$namedChars} named ({$charPct}%)")
                ->color($charPct >= 80 ? 'success' : ($charPct >= 30 ? 'warning' : 'danger'))
                ->icon('heroicon-o-user'),

            Stat::make('Corporations', number_format($uniqueCorps))
                ->description("{$namedCorps} named ({$corpPct}%)")
                ->color($corpPct >= 80 ? 'success' : ($corpPct >= 30 ? 'warning' : 'danger'))
                ->icon('heroicon-o-building-office'),

            Stat::make('Alliances', number_format($uniqueAlliances))
                ->description("{$namedAlliances} named ({$alliancePct}%)")
                ->color($alliancePct >= 80 ? 'success' : ($alliancePct >= 30 ? 'warning' : 'danger'))
                ->icon('heroicon-o-flag'),

            Stat::make('Name Cache', number_format($totalNamed))
                ->description('Total entities in esi_entity_names')
                ->color('primary')
                ->icon('heroicon-o-bookmark'),
        ];
    }
}
