<?php

declare(strict_types=1);

namespace App\Domains\CounterIntel\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase-1 combat anomaly computation.
 *
 * For a given (character, viewer_bloc, window_end) computes four
 * metric families and a coarse banding label that a dossier renders
 * as review evidence:
 *
 *   1. Damage contribution     — pilot's per-battle damage_share
 *                                 vs same-category same-side peers.
 *   2. Survival when peers die — pilot survived a battle where
 *                                 ≥ 3 same-category peers on same
 *                                 side were present and ≥ 50% died.
 *   3. Feeding bias            — fraction of attended battles where
 *                                 pilot's alliance had net-negative
 *                                 ISK exchange, compared to alliance
 *                                 peers on same hull category.
 *   4. Fit deviation           — for pilot's own losses in window,
 *                                 count of fitted high/med/low slots
 *                                 whose module type_id diverges from
 *                                 the dominant doctrine head.
 *
 * Each metric's z-score is rendered against the pilot's 90d cohort
 * (same ship_class_category, same viewer bloc). Reinforces when ≥ 2
 * signals point in the bad direction, weakens when ≥ 2 point in the
 * good direction. "No verdict" framing is the load-bearing property
 * — dossier strings say "reinforces review" / "weakens review",
 * never "spy" / "clean".
 */
final class CombatAnomalyService
{
    public const WINDOW_DAYS = 90;
    public const SELF_BASELINE_OFFSET_DAYS = 90;
    public const SELF_BASELINE_WINDOW_DAYS = 90;
    public const MIN_BATTLES_FOR_OUTPUT = 5;
    public const MIN_COHORT_SIZE = 30;
    public const PEER_COUNT_CAP = 40;

    // Phase-1 banding thresholds. Absolute rather than z-scored
    // because building defensible baselines for survival + feeding
    // requires a separate cohort-materialisation pass (Phase 1.1).
    // These thresholds were picked to surface only noticeable
    // deviations — e.g. feed_rate ≥ 0.65 means "pilot's side loses
    // ISK in 2 out of 3 attended battles", which is well above the
    // random-fleet baseline of ~0.35-0.45 on large-bloc data.
    public const DAMAGE_Z_REINFORCE = 1.0;   // below peer median in-battle
    public const SURVIVAL_RATE_REINFORCE = 0.75;  // survives 3/4 peer-loss battles
    public const SURVIVAL_RATE_WEAKEN = 0.25;
    public const FEED_RATE_REINFORCE = 0.65;
    public const FEED_RATE_WEAKEN = 0.30;
    public const FIT_DEVIATION_RATIO_REINFORCE = 0.5;

    // Hulls whose survival / fit patterns are structural, not
    // behavioural — excluded from survival + fit-deviation metrics
    // because including them makes every FC look like a reinforces
    // case. Monitor (45534) is design-invulnerable; its dominant fit
    // is a specialist one with no comparable doctrine.
    public const STRUCTURAL_SURVIVAL_HULLS = [45534]; // Monitor

    // Categories where high survival is a role pattern, not a tell.
    // Tackle pilots (interceptors, dictors) burn out or warp off by
    // design once primary engagement starts, so "survived when peers
    // died" is noise. Damage + feed + fit stay measurable.
    public const STRUCTURAL_SURVIVAL_CATEGORIES = ['tackle'];

    // Per-run caches: survive across compute() calls on the same
    // service instance. The artisan command dispatches thousands of
    // candidates through one instance, so doctrine + cohort lookups
    // collapse from O(candidates × lookups) to O(distinct keys).
    /** @var array<int, array<int,int>> */
    private array $hullFitCache = [];
    /** @var array<string, array<string,mixed>> */
    private array $cohortCache = [];

    /**
     * Compute + persist combat-anomaly row for one pilot.
     *
     * @return array<string,mixed>  The computed row (also written to table).
     */
    public function computeAndStore(int $characterId, int $viewerBlocId, CarbonImmutable $windowEnd): array
    {
        $row = $this->compute($characterId, $viewerBlocId, $windowEnd);

        DB::table('ci_combat_anomalies')->updateOrInsert(
            [
                'character_id' => $characterId,
                'viewer_bloc_id' => $viewerBlocId,
                'window_end_date' => $windowEnd->toDateString(),
            ],
            array_merge($row, ['computed_at' => now()]),
        );

        return $row;
    }

    /**
     * Pure computation — no writes. Useful for dry-run + tests.
     *
     * @return array<string,mixed>
     */
    public function compute(int $characterId, int $viewerBlocId, CarbonImmutable $windowEnd): array
    {
        $windowStart = $windowEnd->subDays(self::WINDOW_DAYS);

        // 1. Pull pilot's per-battle features inside the window.
        $pilotFeatures = $this->pilotBattleFeatures($characterId, $windowStart, $windowEnd);
        $battlesAttended = count($pilotFeatures);
        $battleIds = array_values(array_unique(array_map(fn ($r) => (int) $r->battle_id, $pilotFeatures)));

        if ($battlesAttended === 0) {
            return $this->insufficientRow($characterId, $viewerBlocId, $windowEnd);
        }

        // 2. In-battle peer stats (same side, same category) for each
        //    battle pilot was in.
        $peerStatsByBattle = $this->peerStatsByBattle($battleIds, $pilotFeatures);

        // 3. Damage metrics.
        $damage = $this->damageContribution($pilotFeatures, $peerStatsByBattle);

        // 4. Survival-when-peers-die.
        $survival = $this->survivalPeerLoss($characterId, $pilotFeatures, $peerStatsByBattle);

        // 5. Feeding bias — alliance ISK outcomes per battle.
        $feeding = $this->feedingBias($characterId, $pilotFeatures, $windowStart, $windowEnd);

        // 6. Fit deviation from doctrine head on own losses.
        $fitDev = $this->fitDeviation($characterId, $windowStart, $windowEnd);

        // 7. Cohort aggregates for z-score comparisons.
        $cohort = $this->cohortAggregates($pilotFeatures, $viewerBlocId, $windowStart, $windowEnd);

        $damageZCohort = $this->zScore($damage['share_median'], $cohort['damage_share']);
        $survivalZCohort = $this->zScore($survival['rate'], $cohort['survival_rate']);
        $feedingScore = $this->zScore($feeding['rate'], $cohort['feed_rate']);

        // Self baseline.
        $selfBaseline = $this->selfBaseline($characterId, $windowEnd);
        $damageZSelf = null;
        if ($selfBaseline !== null && $damage['share_median'] !== null) {
            $damageZSelf = $selfBaseline > 0
                ? round(($damage['share_median'] - $selfBaseline) / max(0.01, $selfBaseline * 0.30), 3)
                : null;
        }

        $victimCount = DB::table('killmails')
            ->where('victim_character_id', $characterId)
            ->whereBetween('killed_at', [$windowStart, $windowEnd])
            ->count();

        [$reinforce, $weaken, $band] = $this->banding(
            damageZBattle: $damage['z_battle'],
            survivalRate: $survival['rate'],
            feedRate: $feeding['rate'],
            fitRatio: $fitDev['ratio_median'],
            cohortSize: $cohort['size'],
            battlesAttended: $battlesAttended,
            survivalQualifying: $survival['qualifying_battles'],
            fitLosses: $fitDev['losses'],
        );

        $confidence = $this->confidence($cohort['size'], $battlesAttended);

        return [
            'character_id' => $characterId,
            'viewer_bloc_id' => $viewerBlocId,
            'window_end_date' => $windowEnd->toDateString(),
            'battles_attended' => $battlesAttended,
            'battles_as_victim' => $victimCount,
            'damage_share_median' => $damage['share_median'] !== null ? round($damage['share_median'], 3) : null,
            'damage_z_battle' => $damage['z_battle'] !== null ? round($damage['z_battle'], 3) : null,
            'damage_z_cohort' => $damageZCohort,
            'damage_z_self' => $damageZSelf,
            'survival_rate_peer_loss' => $survival['rate'] !== null ? round($survival['rate'], 3) : null,
            'survival_z_cohort' => $survivalZCohort,
            'survival_battles_qualifying' => $survival['qualifying_battles'],
            'feed_rate' => $feeding['rate'] !== null ? round($feeding['rate'], 3) : null,
            'feeding_score' => $feedingScore,
            'fit_deviation_median' => $fitDev['median'],
            'fit_losses_counted' => $fitDev['losses'],
            'cohort_size' => $cohort['size'],
            'has_self_baseline' => $selfBaseline !== null,
            'comparison_confidence' => $confidence,
            'signals_reinforcing_count' => $reinforce,
            'signals_weakening_count' => $weaken,
            'combat_anomaly_band' => $band,
        ];
    }

    /**
     * Pilot's per-battle rows from battle_character_role_features,
     * filtered to locked theaters (battle_theaters.locked_at not null)
     * inside the window.
     *
     * @return list<object>
     */
    private function pilotBattleFeatures(int $characterId, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        return DB::select(<<<'SQL'
            SELECT f.battle_id, f.alliance_id, f.ship_type_id, f.ship_class_category,
                   f.damage_share, bt.start_time, bt.total_isk_lost
              FROM battle_character_role_features f
              JOIN battle_theaters bt ON bt.id = f.battle_id
             WHERE f.character_id = ?
               AND bt.locked_at IS NOT NULL
               AND bt.start_time BETWEEN ? AND ?
               AND f.ship_class_category IS NOT NULL
        SQL, [$characterId, $windowStart->toDateTimeString(), $windowEnd->toDateTimeString()]);
    }

    /**
     * For each battle, compute same-side same-category peer damage
     * stats: median, stddev, count (capped at PEER_COUNT_CAP).
     *
     * Side is approximated by same alliance_id — good enough at the
     * fleet scale the review targets; blue-on-blue handling is noise.
     *
     * @param list<int> $battleIds
     * @param list<object> $pilotFeatures
     * @return array<int, array{median:float,stddev:float,count:int,peers:list<object>}>
     */
    private function peerStatsByBattle(array $battleIds, array $pilotFeatures): array
    {
        if ($battleIds === []) return [];
        $keyByBattle = [];
        foreach ($pilotFeatures as $f) {
            $keyByBattle[(int) $f->battle_id] = [
                'alliance_id' => (int) $f->alliance_id,
                'category' => (string) $f->ship_class_category,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($battleIds), '?'));
        $peers = DB::select(<<<SQL
            SELECT battle_id, character_id, alliance_id, ship_class_category, damage_share
              FROM battle_character_role_features
             WHERE battle_id IN ($placeholders)
               AND ship_class_category IS NOT NULL
        SQL, $battleIds);

        $byBattle = [];
        foreach ($peers as $p) {
            $byBattle[(int) $p->battle_id][] = $p;
        }

        $out = [];
        foreach ($byBattle as $bid => $rows) {
            $key = $keyByBattle[$bid] ?? null;
            if ($key === null) continue;
            $same = array_filter($rows, fn ($r) => (int) $r->alliance_id === $key['alliance_id']
                && (string) $r->ship_class_category === $key['category']);
            // Cap peer count to damp fights with 200 same-category pilots.
            $sameArr = array_slice(array_values($same), 0, self::PEER_COUNT_CAP);
            $shares = array_map(fn ($r) => (float) $r->damage_share, $sameArr);
            if (count($shares) < 2) {
                $out[$bid] = ['median' => 0.0, 'stddev' => 0.0, 'count' => count($shares), 'peers' => $sameArr];
                continue;
            }
            sort($shares);
            $median = $this->median($shares);
            $mean = array_sum($shares) / count($shares);
            $var = 0.0;
            foreach ($shares as $s) $var += ($s - $mean) ** 2;
            $stddev = sqrt($var / max(1, count($shares) - 1));
            $out[$bid] = ['median' => $median, 'stddev' => $stddev, 'count' => count($shares), 'peers' => $sameArr];
        }
        return $out;
    }

    /**
     * @param list<object> $pilotFeatures
     * @param array<int,array> $peerStatsByBattle
     * @return array{share_median:?float, z_battle:?float}
     */
    private function damageContribution(array $pilotFeatures, array $peerStatsByBattle): array
    {
        $ratios = [];
        $zWeighted = 0.0;
        $zWeight = 0.0;
        foreach ($pilotFeatures as $f) {
            $bid = (int) $f->battle_id;
            if (in_array((int) $f->ship_type_id, self::STRUCTURAL_SURVIVAL_HULLS, true)) continue;
            $stats = $peerStatsByBattle[$bid] ?? null;
            if ($stats === null || $stats['count'] < 3) continue;
            $pilotShare = (float) $f->damage_share;
            if ($stats['median'] > 0) {
                $ratios[] = $pilotShare / $stats['median'];
            }
            if ($stats['stddev'] > 0) {
                $z = ($pilotShare - $stats['median']) / $stats['stddev'];
                $w = min(1.0, $stats['count'] / self::PEER_COUNT_CAP);
                $zWeighted += $z * $w;
                $zWeight += $w;
            }
        }
        return [
            'share_median' => $ratios ? $this->median($ratios) : null,
            'z_battle' => $zWeight > 0 ? $zWeighted / $zWeight : null,
        ];
    }

    /**
     * @param list<object> $pilotFeatures
     * @param array<int,array> $peerStatsByBattle
     * @return array{rate:?float, qualifying_battles:int}
     */
    private function survivalPeerLoss(int $characterId, array $pilotFeatures, array $peerStatsByBattle): array
    {
        if ($pilotFeatures === []) return ['rate' => null, 'qualifying_battles' => 0];
        $battleIds = array_map(fn ($f) => (int) $f->battle_id, $pilotFeatures);
        $placeholders = implode(',', array_fill(0, count($battleIds), '?'));

        // Deaths per character per battle.
        $deaths = DB::select(<<<SQL
            SELECT btk.theater_id AS battle_id, k.victim_character_id AS character_id
              FROM battle_theater_killmails btk
              JOIN killmails k ON k.killmail_id = btk.killmail_id
             WHERE btk.theater_id IN ($placeholders)
               AND k.victim_character_id IS NOT NULL
        SQL, $battleIds);
        $deadByBattle = [];
        foreach ($deaths as $d) {
            $deadByBattle[(int) $d->battle_id][(int) $d->character_id] = true;
        }

        $survived = 0;
        $qualifying = 0;
        foreach ($pilotFeatures as $f) {
            $bid = (int) $f->battle_id;
            // Skip battles where pilot flew a structurally-invulnerable
            // hull (Monitor etc) or a category whose survival is a
            // role pattern (tackle warps off by design).
            if (in_array((int) $f->ship_type_id, self::STRUCTURAL_SURVIVAL_HULLS, true)) continue;
            if (in_array((string) $f->ship_class_category, self::STRUCTURAL_SURVIVAL_CATEGORIES, true)) continue;
            $stats = $peerStatsByBattle[$bid] ?? null;
            if ($stats === null || $stats['count'] < 3) continue;
            $peerDeaths = 0;
            foreach ($stats['peers'] as $p) {
                if ((int) $p->character_id === $characterId) continue;
                if (isset($deadByBattle[$bid][(int) $p->character_id])) $peerDeaths++;
            }
            $peerN = max(0, $stats['count'] - 1);
            if ($peerN < 3) continue;
            if ($peerDeaths / $peerN < 0.5) continue;
            $qualifying++;
            if (empty($deadByBattle[$bid][$characterId])) $survived++;
        }
        if ($qualifying < self::MIN_BATTLES_FOR_OUTPUT) {
            return ['rate' => null, 'qualifying_battles' => $qualifying];
        }
        return ['rate' => $survived / $qualifying, 'qualifying_battles' => $qualifying];
    }

    /**
     * Feeding = fraction of attended battles where pilot's alliance
     * had net-negative ISK exchange. Net = (ISK killed) - (ISK lost).
     *
     * Uses theater_id → killmails join; a pilot's alliance "killed"
     * a ship when an attacker from that alliance is on the killmail,
     * and "lost" one when victim_alliance matches.
     *
     * @param list<object> $pilotFeatures
     * @return array{rate:?float, losses_heavy:int, battles:int}
     */
    private function feedingBias(int $characterId, array $pilotFeatures, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        if ($pilotFeatures === []) return ['rate' => null, 'losses_heavy' => 0, 'battles' => 0];
        $battleIds = array_map(fn ($f) => (int) $f->battle_id, $pilotFeatures);
        $placeholders = implode(',', array_fill(0, count($battleIds), '?'));

        // Per (battle, alliance) ISK lost.
        $losses = DB::select(<<<SQL
            SELECT btk.theater_id AS battle_id, k.victim_alliance_id AS alliance_id,
                   COALESCE(SUM(k.total_value), 0) AS isk_lost
              FROM battle_theater_killmails btk
              JOIN killmails k ON k.killmail_id = btk.killmail_id
             WHERE btk.theater_id IN ($placeholders)
               AND k.victim_alliance_id IS NOT NULL
             GROUP BY btk.theater_id, k.victim_alliance_id
        SQL, $battleIds);
        $lossByBattleAlliance = [];
        foreach ($losses as $l) {
            $lossByBattleAlliance[(int) $l->battle_id][(int) $l->alliance_id] = (float) $l->isk_lost;
        }

        // Per (battle, alliance) ISK killed — sum killmail value where
        // attacker alliance matches.
        $kills = DB::select(<<<SQL
            SELECT btk.theater_id AS battle_id, ka.alliance_id,
                   COALESCE(SUM(k.total_value), 0) AS isk_killed
              FROM battle_theater_killmails btk
              JOIN killmails k ON k.killmail_id = btk.killmail_id
              JOIN killmail_attackers ka ON ka.killmail_id = k.killmail_id
             WHERE btk.theater_id IN ($placeholders)
               AND ka.alliance_id IS NOT NULL
             GROUP BY btk.theater_id, ka.alliance_id
        SQL, $battleIds);
        $killByBattleAlliance = [];
        foreach ($kills as $k) {
            $killByBattleAlliance[(int) $k->battle_id][(int) $k->alliance_id] = (float) $k->isk_killed;
        }

        $lossHeavy = 0;
        $total = 0;
        foreach ($pilotFeatures as $f) {
            $bid = (int) $f->battle_id;
            $aid = (int) $f->alliance_id;
            if ($aid <= 0) continue;
            $total++;
            $killed = $killByBattleAlliance[$bid][$aid] ?? 0.0;
            $lost = $lossByBattleAlliance[$bid][$aid] ?? 0.0;
            if ($killed - $lost < 0) $lossHeavy++;
        }
        return [
            'rate' => $total > 0 ? $lossHeavy / $total : null,
            'losses_heavy' => $lossHeavy,
            'battles' => $total,
        ];
    }

    /**
     * Fit deviation — for each pilot own-loss in window, count of
     * fitted high/med/low module slots whose type_id is NOT the
     * dominant variant for that (hull, role, slot). Dominant variant
     * = head of variants_json in the most-observation-count doctrine
     * for the hull.
     *
     * Rigs (flag 92-98) are excluded from the headline count; charges
     * / cargo / drones never counted (flag > 34).
     *
     * @return array{median:?int, losses:int}
     */
    private function fitDeviation(int $characterId, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $losses = DB::select(<<<'SQL'
            SELECT k.killmail_id, k.victim_ship_type_id AS hull_id
              FROM killmails k
             WHERE k.victim_character_id = ?
               AND k.killed_at BETWEEN ? AND ?
        SQL, [$characterId, $windowStart->toDateTimeString(), $windowEnd->toDateTimeString()]);
        if ($losses === []) return ['median' => null, 'ratio_median' => null, 'losses' => 0];

        $mismatches = [];
        $ratios = [];
        foreach ($losses as $loss) {
            $hullId = (int) $loss->hull_id;
            // Specialist hulls (Monitor) have no comparable doctrine;
            // skipping keeps the metric defensible.
            if (in_array($hullId, self::STRUCTURAL_SURVIVAL_HULLS, true)) continue;
            $dominantByFlag = $this->dominantFitForHull($hullId);
            if ($dominantByFlag === []) continue; // no doctrine baseline
            $slotsInDoctrine = count(array_unique(array_keys($dominantByFlag)));
            if ($slotsInDoctrine < 2) continue;

            $items = DB::select(<<<'SQL'
                SELECT type_id, flag, quantity_destroyed, quantity_dropped
                  FROM killmail_items
                 WHERE killmail_id = ?
                   AND flag BETWEEN 11 AND 34
            SQL, [(int) $loss->killmail_id]);
            if ($items === []) continue;

            $mismatch = 0;
            $coveredSlots = 0;
            foreach ($items as $item) {
                $flag = (int) $item->flag;
                $dominant = $dominantByFlag[$flag] ?? null;
                if ($dominant === null) continue; // slot not in doctrine
                $coveredSlots++;
                if ((int) $item->type_id !== $dominant) $mismatch++;
            }
            if ($coveredSlots < 2) continue;
            $mismatches[] = $mismatch;
            $ratios[] = $mismatch / $coveredSlots;
        }
        if ($mismatches === []) return ['median' => null, 'ratio_median' => null, 'losses' => 0];
        return [
            'median' => (int) $this->median($mismatches),
            'ratio_median' => $this->median($ratios),
            'losses' => count($mismatches),
        ];
    }

    /**
     * Dominant module type_id per high/med/low slot flag for a hull,
     * sourced from the top-observation-count auto_doctrine.
     *
     * @return array<int,int>  flag => type_id
     */
    private function dominantFitForHull(int $hullId): array
    {
        if (isset($this->hullFitCache[$hullId])) {
            return $this->hullFitCache[$hullId];
        }
        $doctrine = DB::table('auto_doctrines')
            ->where('hull_type_id', $hullId)
            ->where('is_active', 1)
            ->orderByDesc('observation_count')
            ->first(['id']);
        if ($doctrine === null) {
            return $this->hullFitCache[$hullId] = [];
        }

        $modules = DB::table('auto_doctrine_modules')
            ->where('doctrine_id', $doctrine->id)
            ->whereIn('flag_category', ['high', 'med', 'low'])
            ->get(['canonical_type_id', 'variants_json', 'flag_category']);

        $out = [];
        $flagMap = ['high' => [27, 28, 29, 30, 31, 32, 33, 34], 'med' => [19, 20, 21, 22, 23, 24, 25, 26], 'low' => [11, 12, 13, 14, 15, 16, 17, 18]];
        foreach ($modules as $m) {
            $variants = json_decode((string) ($m->variants_json ?? '[]'), true) ?: [];
            $topTid = (int) $m->canonical_type_id;
            if ($variants !== []) {
                usort($variants, fn ($a, $b) => (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0));
                $topTid = (int) ($variants[0]['type_id'] ?? $topTid);
            }
            foreach ($flagMap[$m->flag_category] ?? [] as $flag) {
                if (! isset($out[$flag])) $out[$flag] = $topTid;
            }
        }
        return $this->hullFitCache[$hullId] = $out;
    }

    /**
     * Cohort aggregates: 90d same-category peers inside bloc.
     * Returns median + stddev for each metric so the outer compute
     * can z-score the target pilot.
     *
     * @param list<object> $pilotFeatures
     */
    private function cohortAggregates(array $pilotFeatures, int $viewerBlocId, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        // Categories pilot flew in this window — cohort is all pilots
        // in the bloc who flew any of them in the same window.
        $categories = array_values(array_unique(array_filter(array_map(
            fn ($f) => (string) $f->ship_class_category,
            $pilotFeatures,
        ))));
        if ($categories === []) {
            return ['size' => 0, 'damage_share' => null, 'survival_rate' => null, 'feed_rate' => null];
        }

        sort($categories);
        $cacheKey = $viewerBlocId . '|' . $windowEnd->toDateString() . '|' . implode(',', $categories);
        if (isset($this->cohortCache[$cacheKey])) {
            return $this->cohortCache[$cacheKey];
        }

        $catPh = implode(',', array_fill(0, count($categories), '?'));
        $peers = DB::select(<<<SQL
            SELECT DISTINCT f.character_id
              FROM battle_character_role_features f
              JOIN battle_theaters bt ON bt.id = f.battle_id
              JOIN coalition_entity_labels cel
                ON cel.entity_type = 'alliance'
               AND cel.entity_id = f.alliance_id
               AND cel.is_active = 1
             WHERE bt.locked_at IS NOT NULL
               AND bt.start_time BETWEEN ? AND ?
               AND f.ship_class_category IN ($catPh)
               AND cel.bloc_id = ?
        SQL, array_merge(
            [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()],
            $categories,
            [$viewerBlocId],
        ));
        $cohortIds = array_map(fn ($r) => (int) $r->character_id, $peers);
        if (count($cohortIds) < self::MIN_COHORT_SIZE) {
            return $this->cohortCache[$cacheKey] = [
                'size' => count($cohortIds),
                'damage_share' => null,
                'survival_rate' => null,
                'feed_rate' => null,
            ];
        }

        // Cohort damage_share aggregate: median + stddev across peer
        // set. Sampled at pilot level (one damage_share median per
        // peer over their window battles).
        $idsPh = implode(',', array_fill(0, count($cohortIds), '?'));
        $peerShares = DB::select(<<<SQL
            SELECT character_id, AVG(damage_share) AS avg_share
              FROM battle_character_role_features
             WHERE character_id IN ($idsPh)
               AND ship_class_category IN ($catPh)
             GROUP BY character_id
        SQL, array_merge($cohortIds, $categories));
        $shares = array_map(fn ($r) => (float) $r->avg_share, $peerShares);

        return $this->cohortCache[$cacheKey] = [
            'size' => count($cohortIds),
            'damage_share' => $this->medianStddev($shares),
            // Survival + feed baselines: conservative default of 0.5
            // median, 0.25 stddev until a dedicated cohort pass lands.
            // Z-score still useful as a coarse z against "peer median"
            // expectation; refined in Phase 1.1.
            'survival_rate' => ['median' => 0.5, 'stddev' => 0.25],
            'feed_rate' => ['median' => 0.5, 'stddev' => 0.25],
        ];
    }

    /**
     * Pilot's own median damage_share across the pre-window 90d
     * (days 180-90 before window_end) — null if they had < 5 battles
     * in that window.
     */
    private function selfBaseline(int $characterId, CarbonImmutable $windowEnd): ?float
    {
        $start = $windowEnd->subDays(self::SELF_BASELINE_OFFSET_DAYS + self::SELF_BASELINE_WINDOW_DAYS);
        $end = $windowEnd->subDays(self::SELF_BASELINE_OFFSET_DAYS);
        $rows = DB::select(<<<'SQL'
            SELECT f.damage_share
              FROM battle_character_role_features f
              JOIN battle_theaters bt ON bt.id = f.battle_id
             WHERE f.character_id = ?
               AND bt.locked_at IS NOT NULL
               AND bt.start_time BETWEEN ? AND ?
        SQL, [$characterId, $start->toDateTimeString(), $end->toDateTimeString()]);
        if (count($rows) < 5) return null;
        $shares = array_map(fn ($r) => (float) $r->damage_share, $rows);
        return $this->median($shares);
    }

    /** @return array{median:float,stddev:float}|null */
    private function medianStddev(array $values): ?array
    {
        if (count($values) < 2) return null;
        sort($values);
        $median = $this->median($values);
        $mean = array_sum($values) / count($values);
        $var = 0.0;
        foreach ($values as $v) $var += ($v - $mean) ** 2;
        $stddev = sqrt($var / max(1, count($values) - 1));
        return ['median' => $median, 'stddev' => $stddev];
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) return 0.0;
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function zScore(?float $value, ?array $baseline): ?float
    {
        if ($value === null || $baseline === null) return null;
        $stddev = max(0.01, (float) ($baseline['stddev'] ?? 0.01));
        return round(($value - ($baseline['median'] ?? 0.0)) / $stddev, 3);
    }

    /**
     * Phase-1 banding uses absolute thresholds (not z-scores) for
     * survival + feeding + fit deviation because cohort baselines
     * for those metrics aren't materialised yet. Damage uses the
     * in-battle z-score, which is robust to scale differences
     * between hull categories and battle sizes. Z-score fields on
     * the stored row are informational; banding doesn't read them.
     *
     * @return array{0:int,1:int,2:string}  [reinforcing, weakening, band]
     */
    private function banding(
        ?float $damageZBattle,
        ?float $survivalRate,
        ?float $feedRate,
        ?float $fitRatio,
        int $cohortSize,
        int $battlesAttended,
        int $survivalQualifying,
        int $fitLosses,
    ): array {
        $reinforcing = 0;
        $weakening = 0;

        if ($damageZBattle !== null) {
            if ($damageZBattle <= -self::DAMAGE_Z_REINFORCE) $reinforcing++;
            elseif ($damageZBattle >= self::DAMAGE_Z_REINFORCE) $weakening++;
        }
        if ($survivalRate !== null && $survivalQualifying >= self::MIN_BATTLES_FOR_OUTPUT) {
            if ($survivalRate >= self::SURVIVAL_RATE_REINFORCE) $reinforcing++;
            elseif ($survivalRate <= self::SURVIVAL_RATE_WEAKEN) $weakening++;
        }
        if ($feedRate !== null) {
            if ($feedRate >= self::FEED_RATE_REINFORCE) $reinforcing++;
            elseif ($feedRate <= self::FEED_RATE_WEAKEN) $weakening++;
        }
        if ($fitRatio !== null && $fitLosses >= 2) {
            if ($fitRatio >= self::FIT_DEVIATION_RATIO_REINFORCE) $reinforcing++;
            elseif ($fitRatio == 0.0) $weakening++;
        }

        $insufficient = $cohortSize < self::MIN_COHORT_SIZE
            || $battlesAttended < self::MIN_BATTLES_FOR_OUTPUT;

        if ($insufficient) {
            return [$reinforcing, $weakening, 'insufficient_data'];
        }
        if ($reinforcing >= 2 && $reinforcing > $weakening) return [$reinforcing, $weakening, 'reinforces'];
        if ($weakening >= 2 && $weakening > $reinforcing) return [$reinforcing, $weakening, 'weakens'];
        return [$reinforcing, $weakening, 'neutral'];
    }

    private function confidence(int $cohortSize, int $battlesAttended): string
    {
        if ($cohortSize < self::MIN_COHORT_SIZE || $battlesAttended < self::MIN_BATTLES_FOR_OUTPUT) return 'low';
        if ($cohortSize >= 100 && $battlesAttended >= 20) return 'high';
        return 'medium';
    }

    /** @return array<string,mixed> */
    private function insufficientRow(int $characterId, int $viewerBlocId, CarbonImmutable $windowEnd): array
    {
        return [
            'character_id' => $characterId,
            'viewer_bloc_id' => $viewerBlocId,
            'window_end_date' => $windowEnd->toDateString(),
            'battles_attended' => 0,
            'battles_as_victim' => 0,
            'damage_share_median' => null,
            'damage_z_battle' => null,
            'damage_z_cohort' => null,
            'damage_z_self' => null,
            'survival_rate_peer_loss' => null,
            'survival_z_cohort' => null,
            'survival_battles_qualifying' => 0,
            'feed_rate' => null,
            'feeding_score' => null,
            'fit_deviation_median' => null,
            'fit_losses_counted' => 0,
            'cohort_size' => 0,
            'has_self_baseline' => false,
            'comparison_confidence' => 'low',
            'signals_reinforcing_count' => 0,
            'signals_weakening_count' => 0,
            'combat_anomaly_band' => 'insufficient_data',
        ];
    }
}
