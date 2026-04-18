<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\KillmailsBattleTheaters\Models\CharacterCorporationHistory;
use App\Domains\KillmailsBattleTheaters\Models\CorporationAllianceHistory;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Services\Eve\Esi\EsiNameResolver;
use Illuminate\Support\Facades\DB;

/**
 * Shared view-data builder for the killmail detail page. Used by
 * both the authed Filament portal page and the public
 * ``/kills/{id}`` controller so both surfaces stay in lockstep.
 *
 * The only data it exposes comes directly from the killmail (already
 * public via zkillboard) + public SDE tables — no viewer-specific
 * intel flows through here, so the same payload is safe for
 * anonymous callers.
 */
class KillmailViewData
{
    public function __construct(private readonly JitaValuationService $valuation) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Killmail $km): array
    {
        $km->loadMissing(['attackers', 'items']);

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

        $uniqueEntityIds = $entityIds->unique()->filter()->values()->all();
        $resolved = app(EsiNameResolver::class)->resolve($uniqueEntityIds);
        $names = collect($resolved)->mapWithKeys(fn ($entry, $id) => [$id => $entry['name']]);

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
        $itemValues = collect();
        $hullValue = 0.0;
        $fittedValue = 0.0;
        $cargoValue = 0.0;
        $droneValue = 0.0;
        $totalValue = (float) $km->total_value;

        if (! $km->isEnriched() && $km->items->isNotEmpty()) {
            $typeIdsToValue = $km->items->pluck('type_id')->push($km->victim_ship_type_id)->unique()->filter()->values()->all();
            $valuations = $this->valuation->resolve($typeIdsToValue, $km->killed_at);

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

        $itemsBySlot = $km->items->groupBy('slot_category');

        // -- Event-time affiliations ----------------------------------
        $eventTimeCorps = [];
        $allCharIds = collect([$km->victim_character_id])
            ->merge($km->attackers->pluck('character_id'))
            ->filter()
            ->unique();

        foreach ($allCharIds as $charId) {
            $hist = CharacterCorporationHistory::corporationAt((int) $charId, $km->killed_at);
            if ($hist) {
                $eventTimeCorps[(int) $charId] = [
                    'corporation_id' => $hist->corporation_id,
                    'start_date' => $hist->start_date->format('M Y'),
                ];
            }
        }

        $eventCorpIds = collect($eventTimeCorps)->pluck('corporation_id')->unique()->filter()->values()->all();
        if ($eventCorpIds) {
            $corpNames = DB::table('esi_entity_names')
                ->whereIn('entity_id', $eventCorpIds)
                ->pluck('name', 'entity_id');
            foreach ($eventTimeCorps as $charId => &$data) {
                $data['corporation_name'] = $corpNames[$data['corporation_id']] ?? null;
            }
            unset($data);
        }

        // Event-time alliance per corporation — mirrors event-time corp
        // but one level up. Collect every corporation_id that appeared
        // on the mail (victim + attackers), plus the event-time corps
        // we just derived, then ask CorporationAllianceHistory what
        // alliance each was in at killed_at.
        $eventTimeAlliances = [];
        $allCorpIds = collect([$km->victim_corporation_id])
            ->merge($km->attackers->pluck('corporation_id'))
            ->merge(collect($eventTimeCorps)->pluck('corporation_id'))
            ->filter()
            ->unique();
        foreach ($allCorpIds as $corpId) {
            $hist = CorporationAllianceHistory::allianceAt((int) $corpId, $km->killed_at);
            if ($hist && $hist->alliance_id) {
                $eventTimeAlliances[(int) $corpId] = [
                    'alliance_id' => (int) $hist->alliance_id,
                ];
            }
        }
        $eventAllianceIds = collect($eventTimeAlliances)->pluck('alliance_id')->unique()->filter()->values()->all();
        if ($eventAllianceIds) {
            $aNames = DB::table('esi_entity_names')
                ->whereIn('entity_id', $eventAllianceIds)
                ->pluck('name', 'entity_id');
            foreach ($eventTimeAlliances as $cid => &$data) {
                $data['alliance_name'] = $aNames[$data['alliance_id']] ?? null;
            }
            unset($data);
        }

        $systemName = $km->solarSystem?->name ?? 'Unknown';
        $regionName = $km->region?->name ?? 'Unknown';

        // Per-killmail role tag for every character on the mail, from
        // killmail_pilot_role. `role_by_character` is a flat
        // {character_id → role_key} dict the blade can read directly
        // to render FC / Logi / Cmd / etc. badges next to pilot names.
        $roleByCharacter = DB::table('killmail_pilot_role')
            ->where('killmail_id', $km->killmail_id)
            ->pluck('role_key', 'character_id')
            ->map(fn ($v) => (string) $v)
            ->all();

        return [
            'km' => $km,
            'names' => $names,
            'typeNames' => $typeNames,
            'eventTimeCorps' => $eventTimeCorps,
            'eventTimeAlliances' => $eventTimeAlliances,
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
            'roleByCharacter' => $roleByCharacter,
        ];
    }
}
