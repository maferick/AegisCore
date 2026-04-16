<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Data;

use Illuminate\Support\Collection;

/**
 * Preloaded lookup maps shared across a batch of killmails so each
 * individual enrichment call can skip the per-killmail SELECTs against
 * ref_item_types / ref_solar_systems / market_history.
 *
 * Populated once per EnrichPendingKillmails chunk; read-only after
 * construction. If a required key is missing from a map (e.g. a type
 * id absent from ref_item_types), the per-killmail enrichment falls
 * back to its non-batched path — conservative behaviour so a partial
 * preload never silently drops classification or valuation data.
 *
 * Shapes:
 *
 *   - typeMetadata: type_id (int) => object {type_id, type_name,
 *       group_id, group_name, category_id, category_name,
 *       meta_group_id, meta_level}. Built from the ref_item_types /
 *       ref_item_groups / ref_item_categories join.
 *   - solarSystems: solar_system_id (int) => object {constellation_id,
 *       region_id}. Built from ref_solar_systems.
 *   - valuationsByDate: kill-date 'Y-m-d' (string) => array<int,
 *       ValuationResult> keyed by type_id. Built via one
 *       JitaValuationService::resolve() per unique kill date in the
 *       chunk, rather than per killmail.
 */
final class EnrichmentBatchContext
{
    /**
     * @param  array<int, object>  $typeMetadata
     * @param  array<int, object>  $solarSystems
     * @param  array<string, array<int, ValuationResult>>  $valuationsByDate
     */
    public function __construct(
        public readonly array $typeMetadata,
        public readonly array $solarSystems,
        public readonly array $valuationsByDate,
    ) {}

    /**
     * @param  list<int>  $typeIds
     * @return array<int, object>
     */
    public function typeMetadataFor(array $typeIds): array
    {
        $out = [];
        foreach ($typeIds as $id) {
            if (isset($this->typeMetadata[$id])) {
                $out[$id] = $this->typeMetadata[$id];
            }
        }

        return $out;
    }

    /**
     * @return array<int, ValuationResult>
     */
    public function valuationsFor(string $killDate, array $typeIds): array
    {
        $dateMap = $this->valuationsByDate[$killDate] ?? [];
        if ($dateMap === []) {
            return [];
        }

        $out = [];
        foreach ($typeIds as $id) {
            if (isset($dateMap[$id])) {
                $out[$id] = $dateMap[$id];
            }
        }

        return $out;
    }

    public function solarSystem(int $id): ?object
    {
        return $this->solarSystems[$id] ?? null;
    }
}
