<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly historical-prior refresh (Spec 7 job A).
 *
 * Aggregates the last N days of battle_character_role_features rows
 * per (character_id, role_key) and computes a prior ∈ [0, 1] from
 * hull usage + placement + inferred-role frequency + attestation
 * signal. Characters with < MIN_BATTLES observations are pruned from
 * the priors table so cold-start pilots never receive a prior.
 *
 * Formulas (v1 heuristics — tuneable in future Spec 7.x):
 *
 *   FC prior:
 *     0.60 * fraction(ship_class_category = 'command')
 *     + 0.25 * fraction(spec5_inferred_role = 'fc')
 *     + 0.15 * (attested_as_fc ? 1 : 0)
 *
 *   Logi prior:
 *     0.70 * fraction(ship_class_category = 'logi')
 *     + 0.20 * fraction(spec5_inferred_role = 'logi')
 *     + 0.10 * (1 - avg(damage_share))
 *
 *   Mainline prior:
 *     0.50 * fraction(ship_class_category = 'mainline')
 *     + 0.20 * fraction(is_in_subfleet_0 = 1)
 *     + 0.15 * avg(damage_share)
 *     + 0.15 * fraction(spec5_inferred_role = 'mainline_dps')
 *
 * All priors clamped to [0, 1] at write time. source_breakdown column
 * stores the contributing signals as JSON for audit + future tuning.
 */
class RefreshCharacterRolePriorsCommand extends Command
{
    protected $signature = 'battle:refresh-priors
                            {--window-days=90 : Rolling window in days (default 90)}
                            {--min-battles=5 : Minimum battles observed before a prior is written}
                            {--dry-run : Compute + report without writing}';

    protected $description = 'Recompute character_role_historical_priors from the last N days of feature rows (Spec 7 job A).';

    public function handle(): int
    {
        $windowDays = (int) $this->option('window-days');
        $minBattles = (int) $this->option('min-battles');
        $dryRun = (bool) $this->option('dry-run');

        $windowStart = now()->subDays($windowDays)->startOfDay();
        $windowEnd = now()->startOfDay();

        $this->info("Window: {$windowStart->toDateString()} → {$windowEnd->toDateString()} ({$windowDays} days).");
        $this->info("Min battles to qualify: {$minBattles}.");

        // Per-character per-battle feature rows, joined to the battle
        // end time so we can window by the battle date rather than
        // the row compute time.
        $agg = DB::table('battle_character_role_features AS f')
            ->join('battle_theaters AS bt', 'bt.id', '=', 'f.battle_id')
            ->where('bt.end_time', '>=', $windowStart)
            ->where('bt.end_time', '<',  $windowEnd)
            ->selectRaw('
                f.character_id,
                COUNT(*) AS battles,
                SUM(CASE WHEN f.ship_class_category = "command"  THEN 1 ELSE 0 END) AS n_command,
                SUM(CASE WHEN f.ship_class_category = "logi"     THEN 1 ELSE 0 END) AS n_logi,
                SUM(CASE WHEN f.ship_class_category = "mainline" THEN 1 ELSE 0 END) AS n_mainline,
                SUM(CASE WHEN f.ship_class_category = "bomber"   THEN 1 ELSE 0 END) AS n_bomber,
                SUM(CASE WHEN f.ship_class_category = "tackle"   THEN 1 ELSE 0 END) AS n_tackle,
                SUM(f.is_in_subfleet_0) AS n_sf0,
                AVG(f.damage_share) AS avg_damage_share
            ')
            ->groupBy('f.character_id')
            ->get();

        if ($agg->isEmpty()) {
            $this->warn('No feature rows in window; no priors to write.');
            return self::SUCCESS;
        }

        $charIds = $agg->pluck('character_id')->all();

        // Spec 5 inferred-role frequencies (under the currently-default
        // weight version at the time of the battle). Use
        // v0_scoring_seed as the "what did the system historically
        // say" signal — a weak input compared to hull usage.
        $v0 = DB::table('battle_role_weight_versions')->where('label', 'v0_scoring_seed')->first();
        $inferredByCharRole = [];
        if ($v0 !== null) {
            $inf = DB::table('battle_character_role_inference AS i')
                ->join('battle_theaters AS bt', 'bt.id', '=', 'i.battle_id')
                ->where('bt.end_time', '>=', $windowStart)
                ->where('bt.end_time', '<',  $windowEnd)
                ->where('i.weight_version', $v0->weight_version)
                ->whereIn('i.character_id', $charIds)
                ->selectRaw('i.character_id, i.primary_role_key, COUNT(*) AS n')
                ->groupBy('i.character_id', 'i.primary_role_key')
                ->get();
            foreach ($inf as $r) {
                $inferredByCharRole[(int) $r->character_id][(string) $r->primary_role_key] = (int) $r->n;
            }
        }

        // Attestations: for FC prior, flag whether a char has any
        // (latest-per-sub-fleet-user) attestation in the window.
        $attestedFcChars = DB::table('battle_fc_user_attestations AS a')
            ->join('battle_theaters AS bt', 'bt.id', '=', 'a.battle_id')
            ->where('bt.end_time', '>=', $windowStart)
            ->where('bt.end_time', '<',  $windowEnd)
            ->whereIn('a.attested_character_id', $charIds)
            ->pluck('a.attested_character_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->flip()
            ->all();

        $now = now();
        $written = 0;
        $pruned = 0;
        $rows = [];

        foreach ($agg as $a) {
            $charId = (int) $a->character_id;
            $battles = (int) $a->battles;
            if ($battles < $minBattles) {
                $pruned++;
                continue;
            }

            $fracCommand  = $battles > 0 ? ((int) $a->n_command) / $battles : 0.0;
            $fracLogi     = $battles > 0 ? ((int) $a->n_logi) / $battles : 0.0;
            $fracMainline = $battles > 0 ? ((int) $a->n_mainline) / $battles : 0.0;
            $fracSf0      = $battles > 0 ? ((int) $a->n_sf0) / $battles : 0.0;
            $avgDmg       = (float) ($a->avg_damage_share ?? 0.0);

            $infFc  = ($inferredByCharRole[$charId]['fc']           ?? 0) / $battles;
            $infLog = ($inferredByCharRole[$charId]['logi']         ?? 0) / $battles;
            $infMl  = ($inferredByCharRole[$charId]['mainline_dps'] ?? 0) / $battles;
            $attestedFc = isset($attestedFcChars[$charId]) ? 1.0 : 0.0;

            $fcPrior = self::clamp01(
                0.60 * $fracCommand
                + 0.25 * $infFc
                + 0.15 * $attestedFc
            );
            $logiPrior = self::clamp01(
                0.70 * $fracLogi
                + 0.20 * $infLog
                + 0.10 * (1.0 - $avgDmg)
            );
            $mainlinePrior = self::clamp01(
                0.50 * $fracMainline
                + 0.20 * $fracSf0
                + 0.15 * $avgDmg
                + 0.15 * $infMl
            );

            $breakdown = [
                'battles' => $battles,
                'frac_command' => round($fracCommand, 4),
                'frac_logi' => round($fracLogi, 4),
                'frac_mainline' => round($fracMainline, 4),
                'frac_sf0' => round($fracSf0, 4),
                'avg_damage_share' => round($avgDmg, 4),
                'inf_fc' => round($infFc, 4),
                'inf_logi' => round($infLog, 4),
                'inf_mainline' => round($infMl, 4),
                'attested_fc' => (int) $attestedFc,
            ];

            foreach ([
                'fc'           => $fcPrior,
                'logi'         => $logiPrior,
                'mainline_dps' => $mainlinePrior,
            ] as $role => $val) {
                $rows[] = [
                    'character_id' => $charId,
                    'role_key' => $role,
                    'prior_value' => round($val, 4),
                    'battles_observed' => $battles,
                    'window_start' => $windowStart->toDateString(),
                    'window_end' => $windowEnd->toDateString(),
                    'source_breakdown' => json_encode($breakdown),
                    'computed_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $written++;
        }

        $this->info("Qualifying characters: {$written}. Pruned (<{$minBattles} battles): {$pruned}.");

        if ($dryRun) {
            $this->info('Dry run — no writes.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $minBattles): void {
            // Prune any characters that no longer qualify (dropped
            // below min_battles since the last refresh).
            DB::table('character_role_historical_priors')
                ->whereNotIn('character_id', collect($rows)->pluck('character_id')->unique()->all() ?: [0])
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_role_historical_priors')->upsert(
                    $chunk,
                    ['character_id', 'role_key'],
                    ['prior_value', 'battles_observed', 'window_start', 'window_end', 'source_breakdown', 'computed_at'],
                );
            }
        });

        $this->info('Priors upserted.');

        return self::SUCCESS;
    }

    private static function clamp01(float $x): float
    {
        if ($x < 0.0) return 0.0;
        if ($x > 1.0) return 1.0;
        return $x;
    }
}
