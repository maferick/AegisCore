<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Portal dashboard widget: the logged-in user's character at a glance.
 */
class MyCharacterWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'My Character';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();
        $character = $user?->characters()->first();

        if (! $character) {
            return [
                Stat::make('Character', 'Not linked')
                    ->description('Link an EVE character via SSO')
                    ->color('gray')
                    ->icon('heroicon-o-user'),
            ];
        }

        $charId = $character->character_id;
        $charName = $character->name;

        // Count killmails where this character was the victim.
        $deaths = (int) DB::table('killmails')
            ->where('victim_character_id', $charId)
            ->count();

        // Count killmails where this character was an attacker.
        $kills = (int) DB::table('killmail_attackers')
            ->where('character_id', $charId)
            ->count();

        // Total ISK lost (as victim).
        $iskLost = (float) DB::table('killmails')
            ->where('victim_character_id', $charId)
            ->whereNotNull('enriched_at')
            ->sum('total_value');

        // Corp + alliance from the character record.
        $corpName = null;
        $allianceName = null;

        if ($character->corporation_id) {
            $corpName = DB::table('esi_entity_names')
                ->where('entity_id', $character->corporation_id)
                ->value('name');
        }
        if ($character->alliance_id) {
            $allianceName = DB::table('esi_entity_names')
                ->where('entity_id', $character->alliance_id)
                ->value('name');
        }

        $affiliation = $corpName ?? 'Unknown corp';
        if ($allianceName) {
            $affiliation .= " / {$allianceName}";
        }

        return [
            Stat::make($charName, $affiliation)
                ->description('Character #'.$charId)
                ->color('primary')
                ->icon('heroicon-o-user'),

            Stat::make('Kills', number_format($kills))
                ->description('As attacker')
                ->color($kills > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-bolt'),

            Stat::make('Deaths', number_format($deaths))
                ->description(self::formatIsk($iskLost).' ISK lost')
                ->color($deaths > 0 ? 'danger' : 'gray')
                ->icon('heroicon-o-x-circle'),
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
