<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\KillmailResource\Pages;

use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Domains\KillmailsBattleTheaters\Services\JitaValuationService;
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
        $this->record = Killmail::with(['attackers', 'items'])->findOrFail($record);
    }

    public function getTitle(): string
    {
        $ship = $this->record->victim_ship_type_name ?? 'Kill';

        return "{$ship} — Kill #{$this->record->killmail_id}";
    }

    protected function getViewData(): array
    {
        $km = $this->record;

        // -- Entity names from cache ----------------------------------
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

        // -- Type names + categories from SDE -------------------------
        $allTypeIds = collect([$km->victim_ship_type_id]);
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

        $typeCategoryMap = DB::table('ref_item_types as t')
            ->join('ref_item_groups as g', 'g.id', '=', 't.group_id')
            ->whereIn('t.id', $uniqueTypeIds)
            ->pluck('g.category_id', 't.id');

        $chargeTypeIds = $typeCategoryMap->filter(fn ($catId) => $catId == 8)->keys()->flip();

        // -- On-demand valuation for unenriched killmails -------------
        // If the killmail hasn't been enriched yet, compute item values
        // on the fly using the same Jita valuation service. Values are
        // displayed but NOT persisted — the background enrichment job
        // will write them permanently when it reaches this killmail.
        $itemValues = collect();  // type_id → {unitPrice, source}
        $hullValue = 0.0;
        $fittedValue = 0.0;
        $cargoValue = 0.0;
        $droneValue = 0.0;
        $totalValue = (float) $km->total_value;

        if (! $km->isEnriched() && $km->items->isNotEmpty()) {
            $valuationService = app(JitaValuationService::class);
            $typeIdsToValue = $km->items->pluck('type_id')->push($km->victim_ship_type_id)->unique()->filter()->values()->all();
            $valuations = $valuationService->resolve($typeIdsToValue, $km->killed_at);

            // Compute per-item values in memory.
            $fittedSlots = ['high', 'mid', 'low', 'rig', 'subsystem', 'service'];
            foreach ($km->items as $item) {
                $v = $valuations[$item->type_id] ?? null;
                if ($v && $v->source !== 'unavailable') {
                    $qty = $item->quantity_destroyed + $item->quantity_dropped;
                    $lineTotal = (float) bcmul($v->unitPrice, (string) $qty, 2);
                    $itemValues[$item->id] = $lineTotal;

                    if (in_array($item->slot_category, $fittedSlots)) {
                        $fittedValue += $lineTotal;
                    } elseif ($item->slot_category === 'cargo') {
                        $cargoValue += $lineTotal;
                    } elseif (in_array($item->slot_category, ['drone_bay', 'fighter_bay'])) {
                        $droneValue += $lineTotal;
                    }
                }
            }

            $hullVal = $valuations[$km->victim_ship_type_id] ?? null;
            $hullValue = $hullVal && $hullVal->source !== 'unavailable' ? (float) $hullVal->unitPrice : 0.0;
            $totalValue = $hullValue + $fittedValue + $cargoValue + $droneValue;
        } else {
            $hullValue = (float) $km->hull_value;
            $fittedValue = (float) $km->fitted_value;
            $cargoValue = (float) $km->cargo_value;
            $droneValue = (float) $km->drone_value;
            $totalValue = (float) $km->total_value;
        }

        // -- Group items by slot --------------------------------------
        $itemsBySlot = $km->items->groupBy('slot_category');

        // -- Location -------------------------------------------------
        $systemName = $km->solarSystem?->name ?? 'Unknown';
        $regionName = $km->region?->name ?? 'Unknown';

        return [
            'km' => $km,
            'names' => $names,
            'typeNames' => $typeNames,
            'chargeTypeIds' => $chargeTypeIds,
            'itemValues' => $itemValues,
            'hullValue' => $hullValue,
            'fittedValue' => $fittedValue,
            'cargoValue' => $cargoValue,
            'droneValue' => $droneValue,
            'totalValue' => $totalValue,
            'itemsBySlot' => $itemsBySlot,
            'systemName' => $systemName,
            'regionName' => $regionName,
        ];
    }
}
