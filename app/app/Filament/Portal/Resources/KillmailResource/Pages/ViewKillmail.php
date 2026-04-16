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

        // Resolve ALL type names from SDE ref_item_types in one query.
        // Covers items, victim ship, attacker ships, and weapons.
        $allTypeIds = collect();
        $allTypeIds->push($km->victim_ship_type_id);
        foreach ($km->items as $item) {
            $allTypeIds->push($item->type_id);
        }
        foreach ($km->attackers as $att) {
            if ($att->ship_type_id) {
                $allTypeIds->push($att->ship_type_id);
            }
            if ($att->weapon_type_id) {
                $allTypeIds->push($att->weapon_type_id);
            }
        }

        $uniqueTypeIds = $allTypeIds->unique()->filter()->values();

        $typeNames = DB::table('ref_item_types')
            ->whereIn('id', $uniqueTypeIds)
            ->pluck('name', 'id');

        // Build a type_id → category_id map for charge detection.
        // Category 8 = Charge (ammo, crystals, cap boosters, scripts).
        $typeCategoryMap = DB::table('ref_item_types as t')
            ->join('ref_item_groups as g', 'g.id', '=', 't.group_id')
            ->whereIn('t.id', $uniqueTypeIds)
            ->pluck('g.category_id', 't.id');

        // Tag each item as charge or not (used by the template to nest
        // charges under their parent module in fitted slots).
        $chargeTypeIds = $typeCategoryMap->filter(fn ($catId) => $catId == 8)->keys()->flip();

        // Group items by slot.
        $itemsBySlot = $km->items->groupBy('slot_category');

        // System/region names from ref tables.
        $systemName = $km->solarSystem?->name ?? 'Unknown';
        $regionName = $km->region?->name ?? 'Unknown';

        return [
            'km' => $km,
            'names' => $names,
            'typeNames' => $typeNames,
            'chargeTypeIds' => $chargeTypeIds,
            'itemsBySlot' => $itemsBySlot,
            'systemName' => $systemName,
            'regionName' => $regionName,
        ];
    }
}
