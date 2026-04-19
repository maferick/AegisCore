<?php

declare(strict_types=1);

namespace App\Domains\CounterIntel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel Dossier read service.
 *
 * Reads from pre-computed tables:
 *   - ci_character_features_rolling (commit 1)
 *   - ci_character_anomalies_rolling (commit 4, keyed by viewer bloc)
 *
 * Also reads identity + affiliation timeline from the same history
 * tables the portal dashboard uses, so dossier + /portal stay
 * consistent.
 *
 * UI lexicon constraint: never produce "spy" / "infiltrator" /
 * "probable" in any text field. Use "review priority", "outlier",
 * "counter-intel anomaly". Triage surface, not automation.
 */
final class CounterIntelDossierService
{
    private const CACHE_TTL_SECONDS = 600;

    /**
     * @return array<string, mixed>
     */
    public function dossier(int $characterId, int $viewerBlocId): array
    {
        return Cache::remember(
            sprintf('ci.dossier.%d.%d', $characterId, $viewerBlocId),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildDossier($characterId, $viewerBlocId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDossier(int $characterId, int $viewerBlocId): array
    {
        $feature = DB::table('ci_character_features_rolling')
            ->where('character_id', $characterId)
            ->orderByDesc('window_end_date')
            ->first();
        if ($feature === null) {
            return ['not_found' => true, 'character_id' => $characterId];
        }

        $anomaly = DB::table('ci_character_anomalies_rolling')
            ->where('character_id', $characterId)
            ->where('viewer_bloc_id', $viewerBlocId)
            ->orderByDesc('window_end_date')
            ->first();

        $name = DB::table('esi_entity_names')
            ->where('entity_id', $characterId)
            ->where('category', 'character')
            ->value('name') ?? "Pilot #{$characterId}";

        $affiliation = $this->loadAffiliation($characterId);
        $hostility = $this->resolveAllianceHostility($affiliation['alliance_ids'], $viewerBlocId);
        foreach ($affiliation['timeline'] as &$row) {
            $row['hostility'] = $row['alliance_id'] ? ($hostility[$row['alliance_id']] ?? 'unknown') : 'unknown';
        }
        unset($row);
        foreach ($affiliation['distinct_alliances'] as &$a) {
            $a['hostility'] = $hostility[$a['alliance_id']] ?? 'unknown';
        }
        unset($a);

        $hostileAlliancesInHistory = array_values(array_filter(
            $affiliation['distinct_alliances'],
            fn ($a) => ($a['hostility'] ?? '') === 'hostile',
        ));

        $explanation = $this->buildExplanation($feature, $anomaly, $hostileAlliancesInHistory);
        $whyNotHigher = $this->buildWhyNotHigher($feature, $anomaly);

        return [
            'not_found' => false,
            'character_id' => $characterId,
            'character_name' => $name,
            'feature' => (array) $feature,
            'anomaly' => $anomaly ? (array) $anomaly : null,
            'affiliation' => $affiliation,
            'hostile_alliances_in_history' => $hostileAlliancesInHistory,
            'explanation' => $explanation,
            'why_not_higher' => $whyNotHigher,
            'viewer_bloc_id' => $viewerBlocId,
        ];
    }

    /**
     * Dashboard rows — top N by review_priority_score within the
     * selected bloc's viewer scope.
     *
     * @return list<array<string, mixed>>
     */
    public function outlierDashboard(int $viewerBlocId, int $limit = 50, ?string $bandFilter = null): array
    {
        $q = DB::table('ci_character_anomalies_rolling AS a')
            ->leftJoin('esi_entity_names AS en', function ($j): void {
                $j->on('en.entity_id', '=', 'a.character_id')->where('en.category', 'character');
            })
            ->leftJoin('ci_character_features_rolling AS f', function ($j): void {
                $j->on('f.character_id', '=', 'a.character_id')
                    ->on('f.window_end_date', '=', 'a.window_end_date');
            })
            ->where('a.viewer_bloc_id', $viewerBlocId)
            ->whereIn('a.review_priority_band', ['critical', 'high', 'elevated']);
        if ($bandFilter !== null && $bandFilter !== '') {
            $q->where('a.review_priority_band', $bandFilter);
        }
        return $q->orderByDesc('a.review_priority_score')
            ->limit($limit)
            ->select(
                'a.character_id',
                'en.name AS character_name',
                'f.dominant_role',
                'a.activity_decile',
                'a.affiliation_anomaly_pct',
                'a.hostile_overlap_pct',
                'a.bridge_anomaly_pct',
                'a.recent_hostile_join',
                'a.cohort_confidence',
                'a.cohort_size',
                'a.hostile_alliance_count_history',
                'a.hostile_cooccurrence_count',
                'a.review_priority_score',
                'a.review_priority_band',
                'f.current_corp_id' /* may be null */
            )
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    // ----- helpers ----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function loadAffiliation(int $characterId): array
    {
        $rows = DB::table('character_corporation_history')
            ->where('character_id', $characterId)
            ->where('is_deleted', 0)
            ->orderByDesc('start_date')
            ->select('corporation_id', 'start_date', 'end_date')
            ->get();
        $corpIds = $rows->pluck('corporation_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $corpNames = $corpIds
            ? DB::table('esi_entity_names')->whereIn('entity_id', $corpIds)->where('category', 'corporation')->pluck('name', 'entity_id')->all()
            : [];
        $timeline = [];
        $allianceIds = [];
        foreach ($rows as $r) {
            $corpId = (int) $r->corporation_id;
            $allyRow = DB::table('corporation_alliance_history')
                ->where('corporation_id', $corpId)
                ->where('start_date', '<=', $r->start_date)
                ->where(function ($q) use ($r): void {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $r->start_date);
                })
                ->orderByDesc('start_date')
                ->first();
            $aid = $allyRow && $allyRow->alliance_id ? (int) $allyRow->alliance_id : null;
            if ($aid) $allianceIds[] = $aid;
            $timeline[] = [
                'corp_id' => $corpId,
                'corp_name' => $corpNames[$corpId] ?? "Corp #{$corpId}",
                'start_date' => $r->start_date,
                'end_date' => $r->end_date,
                'alliance_id' => $aid,
            ];
        }
        $allianceIds = array_values(array_unique($allianceIds));
        $allianceNames = $allianceIds
            ? DB::table('esi_entity_names')->whereIn('entity_id', $allianceIds)->where('category', 'alliance')->pluck('name', 'entity_id')->all()
            : [];
        foreach ($timeline as &$row) {
            $row['alliance_name'] = $row['alliance_id'] ? ($allianceNames[$row['alliance_id']] ?? "#{$row['alliance_id']}") : null;
        }
        unset($row);

        $distinct = [];
        foreach ($timeline as $r) {
            if ($r['alliance_id'] && ! isset($distinct[$r['alliance_id']])) {
                $distinct[$r['alliance_id']] = [
                    'alliance_id' => $r['alliance_id'],
                    'alliance_name' => $r['alliance_name'],
                    'first_seen' => $r['start_date'],
                ];
            }
        }

        return [
            'current' => $timeline[0] ?? null,
            'timeline' => $timeline,
            'distinct_alliances' => array_values($distinct),
            'alliance_ids' => $allianceIds,
        ];
    }

    /**
     * Resolve viewer-relative hostility for an alliance list.
     *
     * @param  list<int>  $allianceIds
     * @return array<int, string>  alliance_id → 'hostile'|'friendly'|'unknown'
     */
    private function resolveAllianceHostility(array $allianceIds, int $viewerBlocId): array
    {
        if ($allianceIds === []) return [];
        $rows = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->whereIn('entity_id', $allianceIds)
            ->where('is_active', 1)
            ->select('entity_id', 'bloc_id')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) $r->entity_id;
            $bloc = (int) ($r->bloc_id ?? 0);
            $out[$aid] = $bloc === $viewerBlocId ? 'friendly' : 'hostile';
        }
        foreach ($allianceIds as $aid) {
            if (! isset($out[$aid])) $out[$aid] = 'unknown';
        }
        return $out;
    }

    /**
     * Build the human-readable explanation sentences using fixed
     * templates. Never freeform — defensibility requires canned phrasing.
     *
     * @return list<string>
     */
    private function buildExplanation(object $feature, ?object $anomaly, array $hostileAlliancesInHistory): array
    {
        if ((int) ($feature->has_sufficient_history ?? 0) !== 1) {
            return [
                "Pilot has fewer than the minimum battles in the last {$feature->window_days} days. "
                . 'Counter-intel scoring requires observable behavior to baseline; review deferred until more activity is recorded.',
            ];
        }
        if ($anomaly === null || $anomaly->review_priority_band === 'cohort_unavailable') {
            return ['Similarity cohort could not be built — not enough comparable pilots in the current window. Scoring deferred.'];
        }

        $lines = [];
        $decile = $anomaly->activity_decile;
        $cohortSize = $anomaly->cohort_size;
        $lines[] = "Activity level: decile {$decile} among {$cohortSize} most similar pilots in the last {$feature->window_days} days.";

        $hc = (int) $anomaly->hostile_alliance_count_history;
        $affPct = $anomaly->affiliation_anomaly_pct ?? 0;
        if ($hc === 0) {
            $lines[] = 'No hostile-linked alliance in history. Affiliation record: clean.';
        } elseif ($affPct >= 0.95) {
            $lines[] = "Hostile-linked affiliation history: {$hc} alliances, top 5% among similar pilots. Notable.";
        } elseif ($affPct >= 0.85) {
            $lines[] = "Hostile-linked affiliation history: {$hc} alliances, top 15% among similar pilots.";
        } else {
            $lines[] = "Hostile-linked affiliation history: {$hc} alliances, within normal range for comparable pilots.";
        }

        $overlap = (int) $anomaly->hostile_cooccurrence_count;
        $overlapPct = $anomaly->hostile_overlap_pct ?? 0;
        if ($overlap === 0) {
            $lines[] = 'No repeated fleet overlap with hostile-tagged characters.';
        } elseif ($overlapPct >= 0.95) {
            $lines[] = "Repeated fleet overlap with hostile-tagged characters: {$overlap} distinct counterparts, top 5% vs cohort.";
        } elseif ($overlapPct >= 0.85) {
            $lines[] = "Repeated fleet overlap with hostile-tagged characters: {$overlap} distinct counterparts, top 15% vs cohort.";
        }

        $bridgePct = $anomaly->bridge_anomaly_pct ?? 0;
        if ($bridgePct >= 0.95) {
            $lines[] = 'Bridge exposure (betweenness) is top 5% — acts as a connector between distinct fleet groups.';
        }

        if ((int) ($anomaly->recent_hostile_join ?? 0) === 1) {
            $lines[] = 'Recent change: joined a hostile-tagged alliance within the last 30 days.';
        }

        $lines[] = "Cohort confidence: {$anomaly->cohort_confidence} ({$anomaly->cohort_size} peers, "
            .number_format(($anomaly->cohort_clean_pct ?? 0) * 100, 0).'% clean baseline).';
        return $lines;
    }

    /**
     * @return list<string>
     */
    private function buildWhyNotHigher(object $feature, ?object $anomaly): array
    {
        if ($anomaly === null) return [];
        $out = [];
        if ($anomaly->review_priority_band === 'below_threshold') {
            $out[] = 'No signal crossed the review threshold. Pilot appears consistent with their similarity cohort.';
        }
        if (in_array($anomaly->cohort_confidence, ['low', 'medium'], true)) {
            $out[] = "Peer cohort confidence is {$anomaly->cohort_confidence}; signal strength capped until more comparable pilots are observed.";
        }
        return $out;
    }
}
