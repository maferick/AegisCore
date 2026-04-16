<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\KillmailResource\Pages;

use App\Filament\Portal\Resources\KillmailResource;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class ViewKillmail extends Page
{
    protected static string $resource = KillmailResource::class;

    protected string $view = 'filament.portal.pages.view-killmail';

    public $record;

    public function mount(int|string $record): void
    {
        $this->record = \App\Domains\KillmailsBattleTheaters\Models\Killmail::with(['attackers', 'items'])
            ->findOrFail($record);
    }

    public function getTitle(): string
    {
        $ship = $this->record->victim_ship_type_name ?? 'Kill';

        return "{$ship} — Kill #{$this->record->killmail_id}";
    }

    protected function getViewData(): array
    {
        $km = $this->record;

        // Resolve entity names from cache.
        $entityIds = collect();
        if ($km->victim_character_id) {
            $entityIds->push($km->victim_character_id);
        }
        if ($km->victim_corporation_id) {
            $entityIds->push($km->victim_corporation_id);
        }
        if ($km->victim_alliance_id) {
            $entityIds->push($km->victim_alliance_id);
        }

        foreach ($km->attackers as $att) {
            foreach (['character_id', 'corporation_id', 'alliance_id', 'faction_id'] as $col) {
                if ($att->{$col}) {
                    $entityIds->push($att->{$col});
                }
            }
        }

        $names = DB::table('esi_entity_names')
            ->whereIn('entity_id', $entityIds->unique()->filter()->values())
            ->pluck('name', 'entity_id');

        // Group items by slot.
        $itemsBySlot = $km->items->groupBy('slot_category');

        // System/region names from ref tables.
        $systemName = $km->solarSystem?->name ?? 'Unknown';
        $regionName = $km->region?->name ?? 'Unknown';

        return [
            'km' => $km,
            'names' => $names,
            'itemsBySlot' => $itemsBySlot,
            'systemName' => $systemName,
            'regionName' => $regionName,
        ];
    }
}
