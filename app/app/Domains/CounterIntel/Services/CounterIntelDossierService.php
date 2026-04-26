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
     * UI-side warning shown above the Phase 1 signal list. The
     * thresholds in this service have not yet been calibrated against
     * the ci_character_ground_truth label set, so directors must read
     * Phase 1 output as a triage hint rather than a settled finding.
     * Removed once the calibration spec lands.
     */
    private const PHASE1_CAVEAT = 'Early signal model — review aid only, thresholds under calibration.';

    /**
     * Reason codes that depend on a viewer-bloc-relative comparison
     * (hostile alliance set, viewer-relative graph community). These
     * count toward the "hostile-relative present" rule used in the
     * critical-band promotion path.
     */
    private const HOSTILE_RELATIVE_REASONS = [
        'community_mismatch',
        'asymmetric_pair',
        'hostile_triangulation',
    ];

    /**
     * Reason codes whose render is suppressed when the pilot's
     * declared alliance is NOT in the viewer's friendly bloc set.
     * These are hostile-relative metrics that fire baseline-true
     * for known-hostile alliance members and would create review
     * noise rather than signal. Still computed + stored, just hidden
     * from the rendered band.
     */
    private const IN_BLOC_ONLY_REASONS = ['community_mismatch'];

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

        $ringMembers = $anomaly && $anomaly->ring_id !== null
            ? $this->ringMembers((int) $anomaly->ring_id, $characterId, $viewerBlocId)
            : [];
        $cohortBaseline = $anomaly ? $this->cohortBaseline($viewerBlocId) : null;
        $similarPilots = $this->similarPilots($characterId);
        $coJoins = $this->coordinatedJoins($characterId, $affiliation['current']['alliance_id'] ?? null, $affiliation['current']['start_date'] ?? null, $viewerBlocId);
        $combat = $this->combatAnomaly($characterId, $viewerBlocId);
        $declaredAllyId = $affiliation['current']['alliance_id'] ?? null;
        $friendlyAllies = $this->friendlyAllianceIds($viewerBlocId);
        $phase1 = $this->phase1Signals($feature, $anomaly, $declaredAllyId, $friendlyAllies);
        $this->recordRenderDiagnostic($characterId, $viewerBlocId, $phase1);

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
            'ring_members' => $ringMembers,
            'cohort_baseline' => $cohortBaseline,
            'similar_pilots' => $similarPilots,
            'coordinated_joins' => $coJoins,
            'combat_anomaly' => $combat,
            'phase1_signals' => $phase1,
        ];
    }

    /**
     * Phase 1 Counter-Intel signal expansion. Renders one evidence
     * sentence per signal that crossed its review threshold and bands
     * the aggregate by co-fire rules. Each signal carries machine-
     * readable metadata (reason_code, confidence, sample_size, raw
     * metric values) so consumers can audit the call without reaching
     * back into the feature row.
     *
     * Phase 1.5 banding rules (conservative co-fire):
     *   - clean      = 0 signals
     *   - note_only  = 1 signal
     *   - elevated   = 2 independent signals
     *   - high       = 3 independent signals
     *   - critical   = 4+ signals OR 3 signals when at least one is
     *                  hostile-relative
     *   - No single signal alone produces critical.
     *
     * Confidence demotion: if the dossier-level confidence is "low",
     * the band drops one level (critical → high → elevated → note_only).
     * Drivers: small sample size, tiny cohort, missing relative data,
     * fresh corp join, ESI history artefact density, low battle count.
     *
     * community_mismatch render is suppressed when the pilot's declared
     * alliance is NOT in the viewer bloc's friendly set. Such fires are
     * baseline-true for known-hostile alliance members and would create
     * review noise. The signal stays in the rendered list as a
     * suppressed/diagnostic entry so the audit table can see it.
     *
     * @param  list<int>  $friendlyAllyIds  alliances labelled to the viewer's bloc
     * @return array<string, mixed>
     */
    private function phase1Signals(
        object $feature,
        ?object $anomaly,
        ?int $declaredAllyId,
        array $friendlyAllyIds,
    ): array {
        $confidencePenalties = [];
        $sampleSizes = [
            'battles' => (int) ($feature->battles ?? 0),
            'killmails_attacker' => (int) ($feature->killmails_attacker ?? 0),
            'killmails_victim' => (int) ($feature->killmails_victim ?? 0),
            'cohort_size' => (int) ($anomaly?->cohort_size ?? 0),
            'days_since_last_activity' => (int) ($feature->days_since_last_activity ?? 9999),
        ];

        if ((int) ($feature->has_sufficient_history ?? 0) !== 1) {
            return [
                'signals' => [],
                'flag_count' => 0,
                'note_count' => 0,
                'band' => 'insufficient_history',
                'confidence' => 'insufficient',
                'confidence_factors' => ['has_sufficient_history' => false],
                'sample_sizes' => $sampleSizes,
                'evidence_summary' => 'Phase 1 signals deferred until pilot has enough activity history.',
                'caveat' => self::PHASE1_CAVEAT,
                'declared_in_bloc' => $declaredAllyId !== null && in_array($declaredAllyId, $friendlyAllyIds, true),
            ];
        }

        $signals = [];

        // §3.2 Dormancy reactivation — long gap then sudden return.
        $gap = $feature->dormancy_max_gap_days !== null ? (int) $feature->dormancy_max_gap_days : null;
        $reactAt = $feature->dormancy_reactivated_at ?? null;
        $daysToCorp = $feature->dormancy_days_to_corp_change !== null ? (int) $feature->dormancy_days_to_corp_change : null;
        if ($gap !== null && $reactAt !== null && $gap >= 180) {
            $months = (int) round($gap / 30);
            $reactDate = substr((string) $reactAt, 0, 10);
            $strategic = $daysToCorp !== null && $daysToCorp <= 30;
            $signals[] = [
                'key' => 'dormancy_reactivation',
                'reason_code' => 'dormancy_reactivation',
                'severity' => $strategic ? 'flag' : 'note',
                'text' => $strategic
                    ? "Reactivated after {$months}mo dormancy on {$reactDate} and changed corp within {$daysToCorp} day(s) — strategic-timing reactivation."
                    : "Reactivated after {$months}mo dormancy on {$reactDate}.",
                'confidence' => $strategic ? 'high' : 'medium',
                'sample_size' => $sampleSizes['killmails_attacker'] + $sampleSizes['killmails_victim'],
                'raw' => [
                    'dormancy_max_gap_days' => $gap,
                    'dormancy_reactivated_at' => (string) $reactAt,
                    'dormancy_days_to_corp_change' => $daysToCorp,
                ],
            ];
        }

        // §3.1 Corp-hopping cadence. Defaulted to note; promotes to flag
        // only via the co-fire pass below (uncalibrated single signal
        // would otherwise dominate review queues — see diagnostic doc).
        $shortCount = $feature->corp_tenure_short_count !== null
            ? (int) $feature->corp_tenure_short_count
            : null;
        $stdev = $feature->corp_tenure_stdev_days !== null ? (float) $feature->corp_tenure_stdev_days : null;
        $distinctCorps = (int) ($feature->distinct_corps_all_time ?? 0);
        $corpHoppingIdx = null;
        $corpHoppingPromotable = false;
        if ($shortCount !== null && $shortCount >= 3 && $distinctCorps >= 6) {
            $stdevText = $stdev !== null ? sprintf(' (stdev %.0fd)', $stdev) : '';
            $signals[] = [
                'key' => 'corp_hopping',
                'reason_code' => 'corp_hopping',
                'severity' => 'note',
                'text' => "Corp-hop cadence: {$distinctCorps} corps lifetime, {$shortCount} short stays (1–30d){$stdevText}.",
                'confidence' => $shortCount >= 5 ? 'medium' : 'low',
                'sample_size' => $distinctCorps,
                'raw' => [
                    'distinct_corps_all_time' => $distinctCorps,
                    'corp_tenure_short_count' => $shortCount,
                    'corp_tenure_stdev_days' => $stdev,
                    'corp_tenure_min_days' => $feature->corp_tenure_min_days,
                ],
            ];
            $corpHoppingIdx = array_key_last($signals);
            $corpHoppingPromotable = true;
        } elseif ($distinctCorps >= 8) {
            $signals[] = [
                'key' => 'corp_hopping',
                'reason_code' => 'corp_hopping',
                'severity' => 'note',
                'text' => "Corp history: {$distinctCorps} corps lifetime — high churn worth a glance.",
                'confidence' => 'low',
                'sample_size' => $distinctCorps,
                'raw' => ['distinct_corps_all_time' => $distinctCorps],
            ];
        }

        // §2 Battle-only character profile.
        $battleOnly = $feature->battle_only_score !== null ? (float) $feature->battle_only_score : null;
        $atkN = $sampleSizes['killmails_attacker'];
        if ($battleOnly !== null && $battleOnly >= 0.75) {
            $sgN = (int) ($feature->small_gang_loss_count ?? 0);
            $shipN = (int) ($feature->ship_loss_count ?? 0);
            $signals[] = [
                'key' => 'battle_only',
                'reason_code' => 'battle_only',
                'severity' => 'flag',
                'text' => sprintf(
                    'Battle-only profile: %d%% abnormal vs typical main pattern (small-gang/solo/cheap losses %d of %d).',
                    (int) round($battleOnly * 100), $sgN, $shipN,
                ),
                'confidence' => $atkN >= 50 ? 'high' : ($atkN >= 20 ? 'medium' : 'low'),
                'sample_size' => $atkN,
                'raw' => [
                    'battle_only_score' => $battleOnly,
                    'small_gang_loss_count' => $sgN,
                    'ship_loss_count' => $shipN,
                ],
            ];
        } elseif ($battleOnly !== null && $battleOnly >= 0.55) {
            $signals[] = [
                'key' => 'battle_only',
                'reason_code' => 'battle_only',
                'severity' => 'note',
                'text' => sprintf('Mostly large-fleet activity (battle-only score %.2f) — limited normal footprint.', $battleOnly),
                'confidence' => $atkN >= 50 ? 'medium' : 'low',
                'sample_size' => $atkN,
                'raw' => ['battle_only_score' => $battleOnly],
            ];
        }

        // §5.3 Pod survival anomaly.
        $podSurv = $feature->pod_survival_rate !== null ? (float) $feature->pod_survival_rate : null;
        $shipN = (int) ($feature->ship_loss_count ?? 0);
        if ($podSurv !== null && $shipN >= 10 && $podSurv >= 0.95) {
            $podN = (int) ($feature->pod_loss_count ?? 0);
            $signals[] = [
                'key' => 'pod_survival',
                'reason_code' => 'pod_survival',
                'severity' => 'note',
                'text' => sprintf(
                    'Pod survival %d%% (%d pods on %d ship losses) — unusually careful or controlled exposure.',
                    (int) round($podSurv * 100), $podN, $shipN,
                ),
                'confidence' => $shipN >= 30 ? 'medium' : 'low',
                'sample_size' => $shipN,
                'raw' => ['pod_survival_rate' => $podSurv, 'pod_loss_count' => $podN, 'ship_loss_count' => $shipN],
            ];
        }

        // §5.2 Controlled loss / cheap-feed pattern.
        $cheap = $feature->cheap_loss_rate !== null ? (float) $feature->cheap_loss_rate : null;
        if ($cheap !== null && $shipN >= 10 && $cheap >= 0.70) {
            $signals[] = [
                'key' => 'cheap_loss',
                'reason_code' => 'cheap_loss',
                'severity' => 'note',
                'text' => sprintf(
                    'Cheap-loss rate %d%% (%d of %d ship losses below 50M ISK) — could be feeding for credibility.',
                    (int) round($cheap * 100), (int) round($cheap * $shipN), $shipN,
                ),
                'confidence' => $shipN >= 30 ? 'medium' : 'low',
                'sample_size' => $shipN,
                'raw' => ['cheap_loss_rate' => $cheap, 'ship_loss_count' => $shipN],
            ];
        }

        // §1.2 Asymmetric mutual presence — directional handler/asset.
        if ($anomaly !== null
            && ($anomaly->asymmetric_top_pair_character_id ?? null) !== null
            && ($anomaly->asymmetric_top_pair_battles ?? 0) >= 5
        ) {
            $oppCid = (int) $anomaly->asymmetric_top_pair_character_id;
            $oppName = DB::table('esi_entity_names')
                ->where('entity_id', $oppCid)
                ->where('category', 'character')
                ->value('name') ?? "Pilot #{$oppCid}";
            $outbound = (float) ($anomaly->asymmetric_top_pair_outbound_pct ?? 0);
            $inbound = (float) ($anomaly->asymmetric_top_pair_inbound_pct ?? 0);
            $battles = (int) $anomaly->asymmetric_top_pair_battles;
            $delta = $outbound - $inbound;
            $strong = $outbound >= 0.40 && $delta >= 0.20;
            if ($strong) {
                $signals[] = [
                    'key' => 'asymmetric_pair',
                    'reason_code' => 'asymmetric_pair',
                    'severity' => 'flag',
                    'text' => sprintf(
                        'Asymmetric counterpart: %s appeared opposite this pilot in %d%% of their active days, but only in %d%% of %s\'s — directional pattern (%d shared days).',
                        $oppName,
                        (int) round($outbound * 100), (int) round($inbound * 100),
                        $oppName, $battles,
                    ),
                    'confidence' => $battles >= 20 ? 'high' : ($battles >= 10 ? 'medium' : 'low'),
                    'sample_size' => $battles,
                    'raw' => [
                        'opp_character_id' => $oppCid,
                        'outbound_pct' => $outbound,
                        'inbound_pct' => $inbound,
                        'shared_days' => $battles,
                    ],
                ];
            } elseif ($outbound >= 0.30) {
                $signals[] = [
                    'key' => 'asymmetric_pair',
                    'reason_code' => 'asymmetric_pair',
                    'severity' => 'note',
                    'text' => sprintf(
                        'Frequent hostile counterpart: %s opposite on %d%% of active days (%d shared days).',
                        $oppName, (int) round($outbound * 100), $battles,
                    ),
                    'confidence' => $battles >= 10 ? 'medium' : 'low',
                    'sample_size' => $battles,
                    'raw' => [
                        'opp_character_id' => $oppCid,
                        'outbound_pct' => $outbound,
                        'inbound_pct' => $inbound,
                        'shared_days' => $battles,
                    ],
                ];
            }
        }

        // §4.3 Community vs declared. Suppressed when the pilot's
        // declared alliance is NOT in the viewer bloc's friendly set —
        // for known-hostile members, "graph community is mostly hostile"
        // is baseline truth not a review signal.
        $declaredInBloc = $declaredAllyId !== null && in_array($declaredAllyId, $friendlyAllyIds, true);
        if ($anomaly !== null
            && $anomaly->community_hostile_pct !== null
            && (int) ($anomaly->community_neighbor_count ?? 0) >= 20
        ) {
            $pct = (float) $anomaly->community_hostile_pct;
            $n = (int) $anomaly->community_neighbor_count;
            $strong = $pct >= 0.60;
            $intermediate = $pct >= 0.40 && $pct < 0.60;
            if ($strong || $intermediate) {
                $entry = [
                    'key' => 'community_mismatch',
                    'reason_code' => 'community_mismatch',
                    'severity' => $strong ? 'flag' : 'note',
                    'text' => $strong
                        ? sprintf(
                            'Co-flier graph community is %d%% hostile-tagged (%d neighbours) — graph affiliation contradicts declared bloc.',
                            (int) round($pct * 100), $n,
                        )
                        : sprintf(
                            'Co-flier mix: %d%% of %d graph neighbours are hostile-tagged.',
                            (int) round($pct * 100), $n,
                        ),
                    'confidence' => $n >= 200 ? 'high' : ($n >= 50 ? 'medium' : 'low'),
                    'sample_size' => $n,
                    'raw' => ['community_hostile_pct' => $pct, 'community_neighbor_count' => $n],
                ];
                if (! $declaredInBloc) {
                    // Suppress for hostile-alliance members — keep the
                    // entry but mark it diagnostic-only so it does not
                    // count toward the band.
                    $entry['severity'] = 'suppressed';
                    $entry['suppression_reason'] = 'declared_alliance_not_in_viewer_bloc';
                    $entry['text'] .= ' [suppressed: pilot is in a hostile-tagged alliance, signal reads as baseline truth here, kept as diagnostic only.]';
                }
                $signals[] = $entry;
            }
        }

        // Co-fire promotion for corp_hopping. Requires another flag
        // signal (excluding suppressed entries).
        $otherFlagFires = 0;
        foreach ($signals as $idx => $s) {
            if ($idx === $corpHoppingIdx) continue;
            if (($s['severity'] ?? 'note') === 'flag') $otherFlagFires++;
        }
        if ($corpHoppingPromotable && $corpHoppingIdx !== null && $otherFlagFires >= 1) {
            $signals[$corpHoppingIdx]['severity'] = 'flag';
            $signals[$corpHoppingIdx]['text'] .= ' Promoted to flag because another Phase 1 signal is also firing.';
        }

        // Banding from countable signals (suppressed entries excluded).
        $countable = array_values(array_filter($signals, fn ($s) => ($s['severity'] ?? '') !== 'suppressed'));
        $flagCount = 0;
        $noteCount = 0;
        $hasHostileRelative = false;
        foreach ($countable as $s) {
            if ($s['severity'] === 'flag') $flagCount++;
            else $noteCount++;
            if (in_array($s['reason_code'] ?? '', self::HOSTILE_RELATIVE_REASONS, true)) {
                $hasHostileRelative = true;
            }
        }
        $totalSignals = $flagCount + $noteCount;
        $rawBand = match (true) {
            $totalSignals === 0 => 'clean',
            $totalSignals === 1 => 'note_only',
            $totalSignals === 2 => 'elevated',
            $totalSignals === 3 => $hasHostileRelative ? 'critical' : 'high',
            default => 'critical',
        };

        // Confidence aggregate.
        [$confidence, $confidenceFactors] = $this->aggregateConfidence(
            $feature, $anomaly, $countable, $sampleSizes,
        );

        // Demotion: low confidence drops one band level.
        $demotionLadder = ['critical' => 'high', 'high' => 'elevated', 'elevated' => 'note_only', 'note_only' => 'note_only', 'clean' => 'clean'];
        $band = $rawBand;
        $demoted = false;
        if ($confidence === 'low' && isset($demotionLadder[$rawBand]) && $demotionLadder[$rawBand] !== $rawBand) {
            $band = $demotionLadder[$rawBand];
            $demoted = true;
        }

        $summary = $this->phase1Summary($band, $rawBand, $flagCount, $noteCount, $hasHostileRelative, $demoted, $confidence);

        return [
            'signals' => $signals,
            'flag_count' => $flagCount,
            'note_count' => $noteCount,
            'total_countable' => $totalSignals,
            'has_hostile_relative' => $hasHostileRelative,
            'raw_band' => $rawBand,
            'band' => $band,
            'confidence' => $confidence,
            'confidence_factors' => $confidenceFactors,
            'sample_sizes' => $sampleSizes,
            'evidence_summary' => $summary,
            'caveat' => self::PHASE1_CAVEAT,
            'declared_in_bloc' => $declaredInBloc,
            'demoted' => $demoted,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $countableSignals
     * @return array{0: 'low'|'medium'|'high', 1: array<string,mixed>}
     */
    private function aggregateConfidence(
        object $feature,
        ?object $anomaly,
        array $countableSignals,
        array $sampleSizes,
    ): array {
        $factors = [];
        $score = 1.0;

        // Sample size on observed activity.
        $totalKm = $sampleSizes['killmails_attacker'] + $sampleSizes['killmails_victim'];
        if ($totalKm < 30) { $score -= 0.30; $factors['low_sample_size'] = $totalKm; }
        elseif ($totalKm < 100) { $score -= 0.10; }

        // Tiny cohort.
        $cohort = $sampleSizes['cohort_size'];
        if ($anomaly === null) {
            $score -= 0.30;
            $factors['no_anomaly_row'] = true;
        } else {
            if ($cohort < 30) { $score -= 0.20; $factors['tiny_cohort'] = $cohort; }
            elseif ($cohort < 80) { $score -= 0.05; }
            // Incomplete relative data — bloc-relative signals have NULL inputs.
            if ($anomaly->community_hostile_pct === null && $anomaly->asymmetric_top_pair_character_id === null) {
                $score -= 0.10;
                $factors['incomplete_relative_data'] = true;
            }
        }

        // Fresh corp join (no settled tenure to baseline against).
        $minTen = $feature->corp_tenure_min_days !== null ? (int) $feature->corp_tenure_min_days : null;
        if ($minTen !== null && $minTen <= 7 && (int) ($feature->days_since_last_activity ?? 9999) < 30) {
            $score -= 0.10;
            $factors['fresh_corp_join'] = $minTen;
        }

        // ESI 0-day artefact density: short_count >> 0 but min_days is 0 means
        // the ESI history has back-to-back rows; reduces trust in corp_hopping
        // weight regardless of its severity.
        $shortCount = (int) ($feature->corp_tenure_short_count ?? 0);
        $rawMin = (int) ($feature->corp_tenure_min_days ?? 99999);
        if ($shortCount > 0 && $rawMin === 0) {
            $score -= 0.05;
            $factors['esi_artefact_present'] = true;
        }

        // Low battle participation.
        $battles = $sampleSizes['battles'];
        if ($battles < 5) { $score -= 0.10; $factors['low_battle_participation'] = $battles; }

        $score = max(0.0, min(1.0, $score));
        $band = match (true) {
            $score < 0.4 => 'low',
            $score < 0.7 => 'medium',
            default => 'high',
        };
        $factors['score'] = round($score, 3);
        return [$band, $factors];
    }

    private function phase1Summary(
        string $band,
        string $rawBand,
        int $flagCount,
        int $noteCount,
        bool $hostileRelative,
        bool $demoted,
        string $confidence,
    ): string {
        $confTag = "(confidence: {$confidence})";
        $base = match ($band) {
            'critical' => "Phase 1 review priority: {$flagCount} flag, {$noteCount} note — recommend human counter-intel review {$confTag}.",
            'high' => "Phase 1 review priority: {$flagCount} flag, {$noteCount} note — actively reviewable {$confTag}.",
            'elevated' => "Phase 1 review priority: {$flagCount} flag, {$noteCount} note — worth a glance {$confTag}.",
            'note_only' => "Phase 1 supporting note(s): {$noteCount}. No flag-grade evidence yet {$confTag}.",
            'clean' => "Phase 1 signals clean — pilot looks normal vs the cohort {$confTag}.",
            default => '',
        };
        if ($demoted) {
            $base .= " Demoted from {$rawBand} due to low confidence (small sample / cohort / incomplete data).";
        }
        if ($hostileRelative && $rawBand === 'critical') {
            $base .= ' Includes a hostile-relative signal (community/asymmetric).';
        }
        return $base;
    }

    /**
     * Persist one diagnostic row per (character, bloc, day). Multiple
     * renders during the same UTC day update the same row so the
     * audit table stays bounded. Failures here do not block the
     * dossier render — they're logged and swallowed.
     *
     * @param  array<string, mixed>  $phase1
     */
    private function recordRenderDiagnostic(int $characterId, int $viewerBlocId, array $phase1): void
    {
        try {
            DB::table('ci_render_diagnostics')->updateOrInsert(
                [
                    'character_id' => $characterId,
                    'viewer_bloc_id' => $viewerBlocId,
                    'rendered_on' => now()->toDateString(),
                ],
                [
                    'rendered_at' => now(),
                    'rendered_band' => (string) ($phase1['band'] ?? 'clean'),
                    'raw_band' => (string) ($phase1['raw_band'] ?? $phase1['band'] ?? 'clean'),
                    'confidence' => (string) ($phase1['confidence'] ?? 'medium'),
                    'flag_count' => (int) ($phase1['flag_count'] ?? 0),
                    'note_count' => (int) ($phase1['note_count'] ?? 0),
                    'total_countable' => (int) ($phase1['total_countable'] ?? 0),
                    'has_hostile_relative' => (int) (bool) ($phase1['has_hostile_relative'] ?? false),
                    'demoted' => (int) (bool) ($phase1['demoted'] ?? false),
                    'declared_in_bloc' => (int) (bool) ($phase1['declared_in_bloc'] ?? false),
                    'rendered_signals_json' => json_encode($phase1['signals'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sample_sizes_json' => json_encode($phase1['sample_sizes'] ?? [], JSON_UNESCAPED_UNICODE),
                    'confidence_factors_json' => json_encode($phase1['confidence_factors'] ?? [], JSON_UNESCAPED_UNICODE),
                    'evidence_summary' => mb_substr((string) ($phase1['evidence_summary'] ?? ''), 0, 500),
                ],
            );
        } catch (\Throwable $e) {
            \Log::warning('ci_render_diagnostics write failed', [
                'character_id' => $characterId,
                'viewer_bloc_id' => $viewerBlocId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function friendlyAllianceIds(int $viewerBlocId): array
    {
        return Cache::remember(
            "ci.friendly_alliances.{$viewerBlocId}",
            self::CACHE_TTL_SECONDS,
            fn (): array => DB::table('coalition_entity_labels')
                ->where('entity_type', 'alliance')
                ->where('is_active', 1)
                ->where('bloc_id', $viewerBlocId)
                ->pluck('entity_id')
                ->map(fn ($v) => (int) $v)
                ->all(),
        );
    }

    /**
     * Latest ci_combat_anomalies row for the (character, bloc) plus
     * a list of human-readable phrases describing each signal that
     * contributed to the reinforces / weakens banding. Null when no
     * row has been computed yet.
     *
     * Lexicon constraint mirrors the rest of this service: phrases
     * must read as review support, never as automated verdict.
     *
     * @return array<string,mixed>|null
     */
    private function combatAnomaly(int $characterId, int $viewerBlocId): ?array
    {
        $row = DB::table('ci_combat_anomalies')
            ->where('character_id', $characterId)
            ->where('viewer_bloc_id', $viewerBlocId)
            ->orderByDesc('window_end_date')
            ->first();
        if ($row === null) return null;

        $signals = [];
        if ($row->damage_z_battle !== null) {
            $z = (float) $row->damage_z_battle;
            if ($z <= -1.0) {
                $signals[] = ['direction' => 'reinforces', 'text' => sprintf('Damage contribution sits %.1fσ below same-hull peers in the same battles (median ratio %.2f).', abs($z), $row->damage_share_median)];
            } elseif ($z >= 1.0) {
                $signals[] = ['direction' => 'weakens', 'text' => sprintf('Damage contribution %.1fσ above same-hull peers in-battle — behaves like an engaged fleet member.', $z)];
            }
        }
        if ($row->survival_battles_qualifying >= 5 && $row->survival_rate_peer_loss !== null) {
            $rate = (float) $row->survival_rate_peer_loss;
            if ($rate >= 0.75) {
                $signals[] = ['direction' => 'reinforces', 'text' => sprintf('Survived %d%% of %d battles where ≥ 50%% of same-category teammates died.', (int) round($rate * 100), $row->survival_battles_qualifying)];
            } elseif ($rate <= 0.25) {
                $signals[] = ['direction' => 'weakens', 'text' => sprintf('Died alongside teammates in %d%% of %d heavy-loss battles — no escape bias.', (int) round((1 - $rate) * 100), $row->survival_battles_qualifying)];
            }
        }
        if ($row->feed_rate !== null) {
            $rate = (float) $row->feed_rate;
            if ($rate >= 0.65) {
                $signals[] = ['direction' => 'reinforces', 'text' => sprintf("Alliance lost ISK exchange in %d%% of %d attended battles.", (int) round($rate * 100), $row->battles_attended)];
            } elseif ($rate <= 0.30) {
                $signals[] = ['direction' => 'weakens', 'text' => sprintf("Alliance won ISK exchange in %d%% of %d attended battles.", (int) round((1 - $rate) * 100), $row->battles_attended)];
            }
        }
        if ($row->fit_deviation_median !== null && $row->fit_losses_counted >= 2) {
            $dev = (int) $row->fit_deviation_median;
            if ($dev >= 5) {
                $signals[] = ['direction' => 'reinforces', 'text' => sprintf('Typical loss has %d fitted slots off the dominant doctrine head (%d losses examined).', $dev, $row->fit_losses_counted)];
            } elseif ($dev === 0) {
                $signals[] = ['direction' => 'weakens', 'text' => sprintf('Every loss examined (%d) matched the dominant doctrine fit.', $row->fit_losses_counted)];
            }
        }

        return [
            'row' => (array) $row,
            'signals' => $signals,
            'headline' => $this->combatHeadline($row),
        ];
    }

    private function combatHeadline(object $row): string
    {
        return match ($row->combat_anomaly_band) {
            'reinforces' => sprintf('Combat behaviour reinforces review (%d signals out of 4).', $row->signals_reinforcing_count),
            'weakens' => sprintf('Combat behaviour weakens review (%d signals read normal / positive).', $row->signals_weakening_count),
            'neutral' => 'Combat behaviour is within normal range — no extra signal either way.',
            'insufficient_data' => 'Not enough in-battle history to evaluate combat signal.',
            default => 'Combat signal unavailable.',
        };
    }

    /**
     * Other characters whose most-recent corp entered the same
     * alliance within ±7 days of the target pilot's entry. Signals
     * coordinated joining (a cell moving together). Falls back to
     * empty when alliance / start_date unknown.
     *
     * @return array{alliance_id:int,alliance_name:?string,window_days:int,total:int,peers:list<array<string,mixed>>}|null
     */
    private function coordinatedJoins(int $characterId, ?int $allianceId, ?string $startDate, int $viewerBlocId): ?array
    {
        if ($allianceId === null || $startDate === null) return null;
        $windowDays = 7;
        $rows = DB::select(<<<'SQL'
            SELECT cch.character_id, cch.start_date,
                   en.name AS character_name,
                   a.review_priority_band, a.review_priority_score
              FROM character_corporation_history cch
              JOIN corporation_alliance_history cah
                ON cah.corporation_id = cch.corporation_id
               AND cah.start_date <= cch.start_date
               AND (cah.end_date IS NULL OR cah.end_date >= cch.start_date)
              LEFT JOIN esi_entity_names en
                ON en.entity_id = cch.character_id AND en.category = 'character'
              LEFT JOIN (
                SELECT a.*
                  FROM ci_character_anomalies_rolling a
                  JOIN (
                    SELECT character_id, MAX(window_end_date) AS mx
                      FROM ci_character_anomalies_rolling
                     WHERE viewer_bloc_id = ?
                     GROUP BY character_id
                  ) m ON m.character_id = a.character_id AND m.mx = a.window_end_date
                 WHERE a.viewer_bloc_id = ?
              ) a ON a.character_id = cch.character_id
             WHERE cch.is_deleted = 0
               AND cch.end_date IS NULL
               AND cch.character_id <> ?
               AND cah.alliance_id = ?
               AND cch.start_date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
             ORDER BY a.review_priority_score DESC, cch.start_date DESC
             LIMIT 20
        SQL, [$viewerBlocId, $viewerBlocId, $characterId, $allianceId, $startDate, $windowDays, $startDate, $windowDays]);

        $name = DB::table('esi_entity_names')
            ->where('entity_id', $allianceId)
            ->where('category', 'alliance')
            ->value('name');

        return [
            'alliance_id' => $allianceId,
            'alliance_name' => $name ? (string) $name : null,
            'window_days' => $windowDays,
            'total' => count($rows),
            'peers' => array_map(fn ($r) => (array) $r, $rows),
        ];
    }

    /**
     * Other pilots in the same Leiden ring (viewer-bloc scoped), with
     * their bands + scores + names. Limits to 20 rows max so the UI
     * can render a compact "recurring ring" panel.
     *
     * @return list<array<string,mixed>>
     */
    private function ringMembers(int $ringId, int $excludeCid, int $viewerBlocId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.character_id, a.review_priority_band, a.review_priority_score,
                   en.name AS character_name
              FROM ci_character_anomalies_rolling a
              JOIN (
                SELECT character_id, MAX(window_end_date) AS mx
                  FROM ci_character_anomalies_rolling
                 WHERE viewer_bloc_id = ?
                   AND ring_id = ?
                 GROUP BY character_id
              ) m ON m.character_id = a.character_id AND m.mx = a.window_end_date
              LEFT JOIN esi_entity_names en ON en.entity_id = a.character_id AND en.category = 'character'
             WHERE a.viewer_bloc_id = ?
               AND a.character_id <> ?
             ORDER BY a.review_priority_score DESC
             LIMIT 20
        SQL, [$viewerBlocId, $ringId, $viewerBlocId, $excludeCid]);
        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * Cohort baseline percentiles for key signals within the viewer
     * bloc — lets the UI draw a ruler (p5/p50/p95) against which the
     * pilot's own values render. Computed over all scored characters
     * in the viewer bloc (latest window), so the same baseline applies
     * to every dossier inside that bloc.
     *
     * @return array<string, array{p5:?float, p50:?float, p95:?float}>
     */
    private function cohortBaseline(int $viewerBlocId): array
    {
        $cols = ['affiliation_anomaly_pct', 'hostile_overlap_pct', 'bridge_anomaly_pct'];
        $out = [];
        foreach ($cols as $col) {
            $rows = DB::select(sprintf(
                'SELECT %s AS v FROM ci_character_anomalies_rolling
                  WHERE viewer_bloc_id = ? AND %s IS NOT NULL',
                $col, $col,
            ), [$viewerBlocId]);
            if (! $rows) {
                $out[$col] = ['p5' => null, 'p50' => null, 'p95' => null];
                continue;
            }
            $vals = array_map(fn ($r) => (float) $r->v, $rows);
            sort($vals);
            $n = count($vals);
            $pick = fn (float $q): float => $vals[(int) floor($q * ($n - 1))];
            $out[$col] = ['p5' => $pick(0.05), 'p50' => $pick(0.5), 'p95' => $pick(0.95)];
        }
        return $out;
    }

    /**
     * Top 5 structural-similarity neighbours from Neo4j's SIMILAR_TO_V2
     * edges. Returns [] if Neo4j is unreachable — this surface is
     * supplementary, not load-bearing.
     *
     * @return list<array{character_id:int,name:?string,band:?string,score:?float,sim:float}>
     */
    private function similarPilots(int $characterId): array
    {
        $pw = (string) env('NEO4J_PASSWORD', '');
        if ($pw === '') return [];
        try {
            $client = \Laudis\Neo4j\ClientBuilder::create()
                ->withDriver('n', (string) env('NEO4J_BOLT_URI', 'bolt://neo4j:7687'),
                    \Laudis\Neo4j\Authentication\Authenticate::basic((string) env('NEO4J_USER', 'neo4j'), $pw))
                ->withDefaultDriver('n')
                ->build();
            $rows = $client->run(
                'MATCH (p:CICharacter {character_id: $cid})-[r:SIMILAR_TO_V2]-(q:CICharacter)
                 RETURN q.character_id AS cid, q.band AS band, q.score AS score, r.score AS sim
                 ORDER BY r.score DESC LIMIT 5',
                ['cid' => $characterId],
            );
            $out = [];
            foreach ($rows as $row) {
                $cid = (int) $row->get('cid');
                $name = DB::table('esi_entity_names')
                    ->where('entity_id', $cid)->where('category', 'character')
                    ->value('name');
                $out[] = [
                    'character_id' => $cid,
                    'name' => $name ? (string) $name : null,
                    'band' => $row->get('band') ? (string) $row->get('band') : null,
                    'score' => $row->get('score') !== null ? (float) $row->get('score') : null,
                    'sim' => (float) $row->get('sim'),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
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
                'a.ring_id',
                'a.ring_size',
                'a.bridge_internal_pct',
                'a.seed_neighbors_count',
                'a.seed_neighbors_max_score',
                'a.is_seed',
                'a.review_priority_score',
                'a.review_priority_band',
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

        // Step-2 graph-feature sentences. Only render when the
        // graph_features pass has populated them (non-null).
        $seedN = (int) ($anomaly->seed_neighbors_count ?? 0);
        if ($seedN > 0) {
            $lines[] = (int) ($anomaly->is_seed ?? 0) === 1
                ? "Seed flag: already in the raw-signal seed set for this bloc (hostile-history or high hostile-overlap). "
                    ."Structurally similar to {$seedN} other seed-set pilots."
                : "Structurally similar to {$seedN} pilots already in this bloc's seed set (" .
                    'CI_SIMILAR_TO → seed overlap).';
        }

        $internalPct = $anomaly->bridge_internal_pct ?? null;
        if ($internalPct !== null && (float) $internalPct >= 0.95) {
            $lines[] = 'Internal bridge: connects otherwise-separate fleet groups inside the bloc (top 5% internal betweenness).';
        }

        $ringSize = (int) ($anomaly->ring_size ?? 0);
        if ($ringSize >= 5 && $ringSize <= 50) {
            $lines[] = "Recurring ring: co-flight community of {$ringSize} pilots (Leiden weighted on internal CI_CO_OCCURS_WITH). "
                .'Tight-group signal — worth checking the other members.';
        } elseif ($ringSize > 50 && $ringSize <= 1000) {
            $lines[] = "Ring size: {$ringSize} (mid-size fleet grouping, informational).";
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
