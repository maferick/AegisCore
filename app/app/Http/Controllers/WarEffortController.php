<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Killmails\Services\WarEffortBadges;
use App\Filament\Portal\Pages\WarReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public per-character war-effort view at /war-report/{conflict}/me.
 *
 * Reads the character_id stashed in the visitor's session by the
 * FLOW_WAR_STATS SSO finisher (separate from any winterco /portal
 * login). No User record, no Filament panel, no token persistence.
 *
 * Stats are computed live against killmails + killmail_attackers
 * with the per-character percentile bucketed into a 1-10 badge tier
 * via WarEffortBadges. Top tier names are EVE-flavored, bottom
 * tiers lean reddit-meme per operator.
 */
final class WarEffortController extends Controller
{
    public function show(Request $request, string $conflict): View|RedirectResponse
    {
        if (! isset(WarReport::CONFLICTS[$conflict])) {
            return redirect('/war-report');
        }

        $charId = (int) $request->session()->get('war_stats.character_id', 0);
        $charName = (string) $request->session()->get('war_stats.character_name', '');

        if ($charId <= 0) {
            // Not signed in — show the sign-in CTA. Same blade, just
            // with $signed_in = false and no stats.
            return view('public.war-effort', [
                'conflict' => $conflict,
                'opposing_label' => WarReport::CONFLICTS[$conflict]['opposing_label'],
                'opposing_tint' => WarReport::CONFLICTS[$conflict]['opposing_tint'],
                'signed_in' => false,
                'page_class' => $conflict,
                'display_label' => WarReport::displayLabel($conflict),
            ]);
        }

        $stats = $this->computeStats($conflict, $charId);
        $badges = $this->resolveBadges($conflict, $stats);

        return view('public.war-effort', [
            'conflict' => $conflict,
            'opposing_label' => WarReport::CONFLICTS[$conflict]['opposing_label'],
            'opposing_tint' => WarReport::CONFLICTS[$conflict]['opposing_tint'],
            'signed_in' => true,
            'character_id' => $charId,
            'character_name' => $charName,
            'scopes_granted' => $request->session()->get('war_stats.scopes_granted', []),
            'stats' => $stats,
            'badges' => $badges,
            'page_class' => $conflict,
            'display_label' => WarReport::displayLabel($conflict),
        ]);
    }

    public function logout(Request $request, string $conflict): RedirectResponse
    {
        $request->session()->forget([
            'war_stats.character_id',
            'war_stats.character_name',
            'war_stats.scopes_granted',
            'war_stats.signed_in_at',
        ]);
        if (! isset(WarReport::CONFLICTS[$conflict])) {
            $conflict = 'vs-imperium';
        }
        return redirect('/war-report/' . $conflict);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStats(string $conflict, int $charId): array
    {
        // Materialise the conflict's _war_kms via WarReport so the
        // queries below can JOIN against it. buildViewData primes
        // the temp tables in this connection.
        $page = new WarReport();
        $page->buildViewData($conflict);

        $row = DB::selectOne("
            SELECT
                COUNT(DISTINCT a.killmail_id) AS kills,
                SUM(CASE WHEN a.is_final_blow = 1 THEN 1 ELSE 0 END) AS final_blows,
                COALESCE(SUM(CASE WHEN a.is_final_blow = 1 THEN k.total_value ELSE 0 END), 0) AS isk_destroyed,
                COUNT(DISTINCT btk.theater_id) AS battles_attended,
                COUNT(DISTINCT CASE WHEN k.attacker_count <= 5 THEN a.killmail_id END) AS small_gang_kills
            FROM _war_attackers a
            JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
            JOIN killmails k ON k.killmail_id = a.killmail_id
            LEFT JOIN battle_theater_killmails btk ON btk.killmail_id = a.killmail_id
            WHERE a.character_id = ?
              AND a.attacker_side <> wk.victim_side
        ", [$charId]);

        $loss = DB::selectOne("
            SELECT COUNT(*) AS losses, COALESCE(SUM(k.total_value), 0) AS isk_lost
            FROM _war_kms wk
            JOIN killmails k ON k.killmail_id = wk.killmail_id
            WHERE k.victim_character_id = ?
        ", [$charId]);

        // Total battles in this conflict across all participants —
        // denominator for the "battles attended %" stat.
        $totalBattles = DB::selectOne("
            SELECT COUNT(DISTINCT btk.theater_id) AS n
            FROM _war_kms wk
            JOIN battle_theater_killmails btk ON btk.killmail_id = wk.killmail_id
        ");

        return [
            'kills' => (int) $row->kills,
            'final_blows' => (int) $row->final_blows,
            'isk_destroyed' => (float) $row->isk_destroyed,
            'battles_attended' => (int) $row->battles_attended,
            'small_gang_kills' => (int) $row->small_gang_kills,
            'losses' => (int) $loss->losses,
            'isk_lost' => (float) $loss->isk_lost,
            'total_battles_in_conflict' => (int) $totalBattles->n,
            'battle_attendance_pct' => $totalBattles->n > 0
                ? round(((int) $row->battles_attended / (int) $totalBattles->n) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Per-metric: compute the character's percentile rank vs every
     * other war participant, then map to a tier 1-10 via the badge
     * ladder. Heavy bucket of distribution data is cached 10 min
     * keyed by (conflict, metric) — same conflict is consulted by
     * many visitors so the percentile tables are reusable.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, array{metric: string, value: int|float, percentile: float, tier: int, name: string, sub: string}>
     */
    private function resolveBadges(string $conflict, array $stats): array
    {
        $out = [];
        foreach (WarEffortBadges::METRICS as $metric) {
            $value = (float) ($stats[$metric] ?? 0);
            $distribution = $this->participantDistribution($conflict, $metric);
            $percentile = $this->percentileRankFromBetter($distribution, $value);
            $tier = WarEffortBadges::tierForPercentile($percentile);
            $ladder = WarEffortBadges::ladder($metric);
            $out[$metric] = [
                'metric' => $metric,
                'value' => $value,
                'percentile' => $percentile,
                'tier' => $tier,
                'name' => $ladder[$tier]['name'],
                'sub' => $ladder[$tier]['sub'],
            ];
        }
        return $out;
    }

    /**
     * Sorted list of every participant's value for the given metric.
     * Cached so we only run the heavy aggregate once per (conflict,
     * metric) per 10 minutes.
     *
     * @return list<float>
     */
    private function participantDistribution(string $conflict, string $metric): array
    {
        $key = sprintf('war_effort.dist.%s.%s.v1', $conflict, $metric);
        return Cache::remember($key, 600, function () use ($metric): array {
            $sql = match ($metric) {
                'kills' => "
                    SELECT COUNT(DISTINCT a.killmail_id) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'final_blows' => "
                    SELECT SUM(CASE WHEN a.is_final_blow = 1 THEN 1 ELSE 0 END) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'isk_destroyed' => "
                    SELECT SUM(CASE WHEN a.is_final_blow = 1 THEN k.total_value ELSE 0 END) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    JOIN killmails k ON k.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'battles_attended' => "
                    SELECT COUNT(DISTINCT btk.theater_id) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    LEFT JOIN battle_theater_killmails btk ON btk.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                'small_gang_kills' => "
                    SELECT COUNT(DISTINCT CASE WHEN k.attacker_count <= 5 THEN a.killmail_id END) AS v
                    FROM _war_attackers a
                    JOIN _war_kms wk ON wk.killmail_id = a.killmail_id
                    JOIN killmails k ON k.killmail_id = a.killmail_id
                    WHERE a.character_id IS NOT NULL AND a.character_id > 0
                      AND a.attacker_side <> wk.victim_side
                    GROUP BY a.character_id
                ",
                default => null,
            };
            if ($sql === null) return [];
            $rows = DB::select($sql);
            $values = array_map(fn ($r) => (float) $r->v, $rows);
            sort($values);
            return $values;
        });
    }

    /**
     * Lower percentile = better rank. value 100 with distribution
     * mostly < 100 → percentile near 0 (top). The +1 / N+1 keeps the
     * top sample from collapsing to 0.
     *
     * @param  list<float>  $sorted  ascending distribution
     */
    private function percentileRankFromBetter(array $sorted, float $value): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 100.0;
        }
        // Index of values strictly greater than the input.
        $better = 0;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($sorted[$i] > $value) {
                $better++;
            } else {
                break;
            }
        }
        return round(($better / $n) * 100, 2);
    }
}
