<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Portal dashboard widget: character card with portrait, corp/alliance
 * logos, and combat stats.
 */
class MyCharacterWidget extends Widget
{
    protected static string $view = 'filament.portal.widgets.my-character';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $user = auth()->user();
        $character = $user?->characters()->first();

        if (! $character) {
            return ['character' => null];
        }

        $charId = $character->character_id;

        $kills = (int) DB::table('killmail_attackers')
            ->where('character_id', $charId)
            ->count();

        $deaths = (int) DB::table('killmails')
            ->where('victim_character_id', $charId)
            ->count();

        $iskLost = (float) DB::table('killmails')
            ->where('victim_character_id', $charId)
            ->whereNotNull('enriched_at')
            ->sum('total_value');

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

        return [
            'character' => $character,
            'corpName' => $corpName,
            'allianceName' => $allianceName,
            'kills' => $kills,
            'deaths' => $deaths,
            'iskLost' => $iskLost,
        ];
    }
}
