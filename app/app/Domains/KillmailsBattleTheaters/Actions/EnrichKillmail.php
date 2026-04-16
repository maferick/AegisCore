<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Actions;

use App\Domains\KillmailsBattleTheaters\Data\KillmailEnrichmentResult;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use App\Domains\KillmailsBattleTheaters\Models\KillmailItem;
use App\Domains\KillmailsBattleTheaters\Services\JitaValuationService;
use App\Reference\Models\SolarSystem;
use App\Services\Eve\Esi\EsiNameResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enriches a single already-persisted killmail with location hierarchy,
 * item valuations, aggregated totals, and entity name caching.
 *
 * Idempotent: safe to re-run on the same killmail — previous enrichment
 * data is overwritten. Bump {@see ENRICHMENT_VERSION} when valuation
 * logic changes to trigger re-enrichment sweeps.
 *
 * Steps 1–4 (location, valuation, aggregates, mark enriched) run inside
 * a single DB transaction. Step 5 (entity name resolution) runs outside
 * the transaction because it may hit ESI over HTTP and we don't want a
 * slow or failing call to hold locks or roll back valuation work.
 */
final class EnrichKillmail
{
    /** Bump when enrichment logic changes to trigger re-enrichment. */
    public const ENRICHMENT_VERSION = 1;

    public function __construct(
        private readonly JitaValuationService $valuationService,
        private readonly EsiNameResolver $nameResolver,
    ) {}

    public function handle(Killmail $killmail): KillmailEnrichmentResult
    {
        $wasAlreadyEnriched = $killmail->isEnriched();

        $killmail->loadMissing(['items', 'attackers']);

        $itemsValued = 0;

        DB::transaction(function () use ($killmail, &$itemsValued): void {
            $this->resolveLocation($killmail);
            $itemsValued = $this->valueItems($killmail);
            $this->computeAggregates($killmail);

            $killmail->enriched_at = now();
            $killmail->enrichment_version = self::ENRICHMENT_VERSION;
            $killmail->save();
        });

        // Name resolution runs outside the transaction — best-effort
        // caching into esi_entity_names. If it fails, the killmail is
        // still correctly enriched.
        $entityNamesResolved = $this->resolveEntityNames($killmail);

        return new KillmailEnrichmentResult(
            killmailId: $killmail->killmail_id,
            itemsValued: $itemsValued,
            totalValue: (string) $killmail->total_value,
            entityNamesResolved: $entityNamesResolved,
            enrichmentVersion: self::ENRICHMENT_VERSION,
            wasAlreadyEnriched: $wasAlreadyEnriched,
        );
    }

    /**
     * Step 1: Resolve constellation + region from the solar system.
     */
    private function resolveLocation(Killmail $killmail): void
    {
        if ($killmail->constellation_id && $killmail->region_id) {
            return;
        }

        $solarSystem = SolarSystem::find($killmail->solar_system_id);

        if ($solarSystem === null) {
            Log::warning('enrich-killmail: solar system not in ref_solar_systems', [
                'killmail_id' => $killmail->killmail_id,
                'solar_system_id' => $killmail->solar_system_id,
            ]);

            return;
        }

        $killmail->constellation_id = $solarSystem->constellation_id;
        $killmail->region_id = $solarSystem->region_id;
    }

    /**
     * Step 2: Value all items + hull via Jita historical pricing.
     *
     * @return int Number of items that received a valuation.
     */
    private function valueItems(Killmail $killmail): int
    {
        $items = $killmail->items;

        $typeIds = $items->pluck('type_id')->all();
        $typeIds[] = $killmail->victim_ship_type_id;
        $typeIds = array_values(array_unique(array_filter($typeIds, fn (int $id) => $id > 0)));

        if ($typeIds === []) {
            return 0;
        }

        $valuations = $this->valuationService->resolve($typeIds, $killmail->killed_at);

        $valued = 0;

        foreach ($items as $item) {
            $v = $valuations[$item->type_id] ?? null;
            if ($v === null) {
                continue;
            }

            $totalQty = $item->quantity_destroyed + $item->quantity_dropped;

            $item->unit_value = $v->unitPrice;
            $item->total_value = bcmul($v->unitPrice, (string) $totalQty, 2);
            $item->valuation_date = $v->dateUsed;
            $item->valuation_source = $v->source;
            $item->save();

            $valued++;
        }

        // Hull valuation — write to the killmail directly.
        $hullVal = $valuations[$killmail->victim_ship_type_id] ?? null;
        $killmail->hull_value = $hullVal?->unitPrice ?? '0.00';

        return $valued;
    }

    /**
     * Step 3: Compute valuation aggregates from per-item totals.
     */
    private function computeAggregates(Killmail $killmail): void
    {
        $bySlot = [];

        foreach ($killmail->items as $item) {
            $cat = $item->slot_category;
            $bySlot[$cat] = bcadd(
                $bySlot[$cat] ?? '0.00',
                (string) ($item->total_value ?? '0.00'),
                2,
            );
        }

        $fittedSlots = [
            KillmailItem::SLOT_HIGH,
            KillmailItem::SLOT_MID,
            KillmailItem::SLOT_LOW,
            KillmailItem::SLOT_RIG,
            KillmailItem::SLOT_SUBSYSTEM,
            KillmailItem::SLOT_SERVICE,
        ];

        $fitted = '0.00';
        foreach ($fittedSlots as $slot) {
            $fitted = bcadd($fitted, $bySlot[$slot] ?? '0.00', 2);
        }

        $cargo = $bySlot[KillmailItem::SLOT_CARGO] ?? '0.00';
        $drone = bcadd(
            $bySlot[KillmailItem::SLOT_DRONE_BAY] ?? '0.00',
            $bySlot[KillmailItem::SLOT_FIGHTER_BAY] ?? '0.00',
            2,
        );
        $implant = $bySlot[KillmailItem::SLOT_IMPLANT] ?? '0.00';
        $other = $bySlot[KillmailItem::SLOT_OTHER] ?? '0.00';

        $killmail->fitted_value = $fitted;
        $killmail->cargo_value = $cargo;
        $killmail->drone_value = $drone;

        $total = (string) ($killmail->hull_value ?? '0.00');
        foreach ([$fitted, $cargo, $drone, $implant, $other] as $component) {
            $total = bcadd($total, $component, 2);
        }

        $killmail->total_value = $total;
    }

    /**
     * Step 5: Warm the shared entity name cache for all participants.
     *
     * Best-effort — failures are logged by EsiNameResolver internally.
     *
     * @return int Number of entity names resolved.
     */
    private function resolveEntityNames(Killmail $killmail): int
    {
        $ids = [];

        if ($killmail->victim_character_id) {
            $ids[] = $killmail->victim_character_id;
        }
        if ($killmail->victim_corporation_id) {
            $ids[] = $killmail->victim_corporation_id;
        }
        if ($killmail->victim_alliance_id) {
            $ids[] = $killmail->victim_alliance_id;
        }

        foreach ($killmail->attackers as $attacker) {
            if ($attacker->character_id) {
                $ids[] = $attacker->character_id;
            }
            if ($attacker->corporation_id) {
                $ids[] = $attacker->corporation_id;
            }
            if ($attacker->alliance_id) {
                $ids[] = $attacker->alliance_id;
            }
            if ($attacker->faction_id) {
                $ids[] = $attacker->faction_id;
            }
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        $resolved = $this->nameResolver->resolve($ids);

        return count($resolved);
    }
}
