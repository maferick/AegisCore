<?php

declare(strict_types=1);

namespace App\Domains\BlocIntel\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only facade over alliance_pair_behavior_rolling.
 *
 * Viewer-agnostic inferred relationships between alliance pairs from
 * 90d of killmail co-fighting data (see python/bloc_intel/extractor.py).
 *
 * Consumers: BattleTheaterSideResolver (side classification tiebreaker),
 * BlocIntelligence admin page, future dossier integrations.
 *
 * Caches per-alliance pair-rows with a short TTL so one battle-report
 * render doesn't re-query MariaDB for every alliance on the field.
 */
final class BlocRelationshipService
{
    private const CACHE_TTL_SECONDS = 120;

    /**
     * Return pair metrics for an ordered pair (a, b) — NULL if no row
     * exists. Metrics are symmetric, so caller can pass in any order.
     *
     * @return array{affinity: float, hostility: float, confidence: float, n_obs: int, label: string}|null
     */
    public function relate(int $a, int $b): ?array
    {
        if ($a === $b || $a <= 0 || $b <= 0) {
            return null;
        }
        [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];
        // Cache the derived array, not the raw DB row — serialized
        // stdClass round-trips were surfacing as __PHP_Incomplete_Class
        // on some cache drivers (observed in prod via igbinary).
        return Cache::remember(
            sprintf('bloc_intel.pair.%d.%d', $lo, $hi),
            self::CACHE_TTL_SECONDS,
            function () use ($lo, $hi): ?array {
                $row = DB::table('alliance_pair_behavior_rolling')
                    ->where('alliance_a_id', $lo)
                    ->where('alliance_b_id', $hi)
                    ->orderByDesc('window_end_date')
                    ->first();
                if ($row === null) {
                    return null;
                }
                $affinity = (float) $row->affinity_score;
                $hostility = (float) $row->hostility_score;
                $conf = (float) $row->confidence;
                $nObs = (int) $row->n_obs;
                return [
                    'affinity' => $affinity,
                    'hostility' => $hostility,
                    'confidence' => $conf,
                    'n_obs' => $nObs,
                    'label' => $this->deriveLabel($affinity, $hostility, $conf, $nObs),
                ];
            },
        );
    }

    /**
     * Bulk fetch — given a list of counterpart alliances, return
     * [counterpart_id => metrics] for the one anchor. Used by
     * BattleTheaterSideResolver to score every on-field alliance vs
     * (anchor_a, anchor_b) in two bulk queries instead of N+1.
     *
     * @param  list<int>  $counterparts
     * @return array<int, array{affinity: float, hostility: float, confidence: float, n_obs: int, label: string}>
     */
    public function relateMany(int $anchor, array $counterparts): array
    {
        if ($anchor <= 0 || $counterparts === []) {
            return [];
        }
        $counterparts = array_values(array_unique(array_map('intval', $counterparts)));
        $rows = DB::table('alliance_pair_behavior_rolling')
            ->where(function ($q) use ($anchor, $counterparts): void {
                $q->where(function ($q2) use ($anchor, $counterparts): void {
                    $q2->where('alliance_a_id', $anchor)->whereIn('alliance_b_id', $counterparts);
                })->orWhere(function ($q2) use ($anchor, $counterparts): void {
                    $q2->where('alliance_b_id', $anchor)->whereIn('alliance_a_id', $counterparts);
                });
            })
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $counterpartId = (int) ($row->alliance_a_id === $anchor ? $row->alliance_b_id : $row->alliance_a_id);
            $affinity = (float) $row->affinity_score;
            $hostility = (float) $row->hostility_score;
            $conf = (float) $row->confidence;
            $nObs = (int) $row->n_obs;
            $out[$counterpartId] = [
                'affinity' => $affinity,
                'hostility' => $hostility,
                'confidence' => $conf,
                'n_obs' => $nObs,
                'label' => $this->deriveLabel($affinity, $hostility, $conf, $nObs),
            ];
        }
        return $out;
    }

    /**
     * Discrete label for a pair. Mirrors the BlocIntelligence page
     * logic so the admin UI and battle report speak the same dialect.
     */
    public function deriveLabel(float $affinity, float $hostility, float $confidence, int $nObs): string
    {
        if ($nObs < 10) return 'insufficient observations';
        if ($confidence < 0.4) return 'insufficient observations';
        if ($affinity >= 0.85 && $hostility < 0.10) return 'aligned';
        if ($hostility >= 0.70) return 'hostile';
        if ($affinity >= 0.50 && $hostility < 0.20) return 'loosely coordinated';
        if ($affinity < 0.20 && $hostility < 0.20) return 'neutral';
        return 'conditionally aligned';
    }
}
