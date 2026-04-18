<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec 7 job B — evaluate Spec 5 inference accuracy under a given
 * weight_version against donor FC attestations (Spec 6) and logi
 * hull-category reality, then record a battle_role_calibration_runs
 * row per role.
 *
 * Evaluation strategies per role:
 *
 *   FC (strict truth): read latest attestation per
 *     (battle, alliance, sub_fleet, user); assignment is "correct"
 *     iff Spec 5 under the target weight_version picked that same
 *     character_id OR any character with a tied top fc_score. Rolls
 *     accuracy = correct / attested sub-fleets.
 *
 *   Logi (derived truth): a pilot whose ship_class_category = 'logi'
 *     is a true logi. Spec 5 assignment "correct" iff labelled logi.
 *     Precision + recall computed; accuracy = F1 for reporting.
 *
 *   Mainline_dps (derived truth): top-damage_share pilot in the
 *     sub-fleet is the "true" mainline anchor. Assignment correct iff
 *     Spec 5 labelled that pilot. (Weak signal — primarily a smoke
 *     test.)
 *
 * Each role gets its own row in battle_role_calibration_runs keyed by
 * weight_version + role + evaluated_at. Operators read the latest
 * per (weight_version, role) to decide promotion.
 */
class EvaluateRoleCalibrationCommand extends Command
{
    protected $signature = 'battle:evaluate-calibration
                            {--weight-version= : Weight version id to evaluate (required)}
                            {--fc-threshold=0.75 : FC accuracy required to pass}
                            {--logi-threshold=0.80 : Logi F1 required to pass}
                            {--mainline-threshold=0.60 : Mainline accuracy required to pass}';

    protected $description = 'Spec 7 job B — score Spec 5 inference under a weight_version against attestations, write calibration_runs row per role.';

    public function handle(): int
    {
        $wv = (int) $this->option('weight-version');
        if ($wv <= 0) {
            $this->error('Pass --weight-version=<id>.');
            return self::FAILURE;
        }
        $version = DB::table('battle_role_weight_versions')->where('weight_version', $wv)->first();
        if ($version === null) {
            $this->error("weight_version {$wv} not found.");
            return self::FAILURE;
        }

        $thresholds = [
            'fc' => (float) $this->option('fc-threshold'),
            'logi' => (float) $this->option('logi-threshold'),
            'mainline_dps' => (float) $this->option('mainline-threshold'),
        ];

        $now = now();
        $inserts = [];

        // ------------------------------------------------------------------
        // FC: strict truth from attestations
        // ------------------------------------------------------------------
        $fcTruth = DB::select("
            SELECT t.battle_id, t.alliance_id, t.sub_fleet_id, t.partition_algo_version, t.user_id, t.attested_character_id
              FROM (
                  SELECT a.*,
                         ROW_NUMBER() OVER (
                             PARTITION BY a.battle_id, a.alliance_id, a.sub_fleet_id, a.partition_algo_version, a.user_id
                             ORDER BY a.attested_at DESC, a.attestation_id DESC
                         ) rn
                    FROM battle_fc_user_attestations a
              ) t
             WHERE t.rn = 1
        ");

        $fcCount = 0;
        $fcCorrect = 0;
        foreach ($fcTruth as $t) {
            $fcCount++;
            $inf = DB::table('battle_character_role_inference')
                ->where('battle_id', $t->battle_id)
                ->where('alliance_id', $t->alliance_id)
                ->where('sub_fleet_id', $t->sub_fleet_id)
                ->where('partition_algo_version', $t->partition_algo_version)
                ->where('weight_version', $wv)
                ->where('primary_role_key', 'fc')
                ->first();
            if ($inf === null) {
                continue; // no FC assignment under this weight version
            }
            if ((int) $inf->character_id === (int) $t->attested_character_id) {
                $fcCorrect++;
                continue;
            }
            // Tie-tolerance: a top fc_score tied with the attested
            // character counts as correct (co-FC tolerance from the
            // truth-set doc).
            $attestedScore = DB::table('battle_character_role_scores')
                ->where('battle_id', $t->battle_id)
                ->where('alliance_id', $t->alliance_id)
                ->where('sub_fleet_id', $t->sub_fleet_id)
                ->where('partition_algo_version', $t->partition_algo_version)
                ->where('weight_version', $wv)
                ->where('character_id', $t->attested_character_id)
                ->where('role_key', 'fc')
                ->where('score_class', 'final')
                ->value('score_value');
            if ($attestedScore !== null && abs((float) $attestedScore - (float) $inf->primary_score) < 0.0001) {
                $fcCorrect++;
            }
        }
        $fcAccuracy = $fcCount > 0 ? $fcCorrect / $fcCount : 0.0;
        $inserts[] = [
            'weight_version' => $wv,
            'role_key' => 'fc',
            'evaluated_at' => $now,
            'attestation_count' => $fcCount,
            'correct_count' => $fcCorrect,
            'accuracy' => round($fcAccuracy, 4),
            'threshold' => round($thresholds['fc'], 4),
            'passed' => $fcAccuracy >= $thresholds['fc'] ? 1 : 0,
            'notes' => 'FC strict attestation truth + tie tolerance',
        ];

        // ------------------------------------------------------------------
        // Logi: hull-category derived truth (precision + recall → F1)
        // ------------------------------------------------------------------
        $logiRows = DB::table('battle_character_role_features AS f')
            ->leftJoin('battle_character_role_inference AS i', function ($j) use ($wv) {
                $j->on('i.battle_id', '=', 'f.battle_id')
                  ->on('i.alliance_id', '=', 'f.alliance_id')
                  ->on('i.sub_fleet_id', '=', 'f.sub_fleet_id')
                  ->on('i.character_id', '=', 'f.character_id')
                  ->on('i.partition_algo_version', '=', 'f.partition_algo_version')
                  ->where('i.weight_version', '=', $wv);
            })
            ->selectRaw('
                f.ship_class_category = "logi" AS is_true_logi,
                i.primary_role_key = "logi" AS is_pred_logi
            ')
            ->get();
        $tp = 0; $fp = 0; $fn = 0;
        foreach ($logiRows as $r) {
            $t = (int) $r->is_true_logi; $p = (int) $r->is_pred_logi;
            if ($t === 1 && $p === 1) $tp++;
            elseif ($t === 0 && $p === 1) $fp++;
            elseif ($t === 1 && $p === 0) $fn++;
        }
        $logiPrec = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $logiRec  = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $logiF1   = ($logiPrec + $logiRec) > 0 ? 2 * $logiPrec * $logiRec / ($logiPrec + $logiRec) : 0.0;
        $inserts[] = [
            'weight_version' => $wv,
            'role_key' => 'logi',
            'evaluated_at' => $now,
            'attestation_count' => $tp + $fn,
            'correct_count' => $tp,
            'accuracy' => round($logiF1, 4),
            'threshold' => round($thresholds['logi'], 4),
            'passed' => $logiF1 >= $thresholds['logi'] ? 1 : 0,
            'notes' => sprintf('logi derived truth: tp=%d fp=%d fn=%d precision=%.4f recall=%.4f f1=%.4f',
                $tp, $fp, $fn, $logiPrec, $logiRec, $logiF1),
        ];

        // ------------------------------------------------------------------
        // Mainline: top-damage_share pilot per sub-fleet is the anchor
        // ------------------------------------------------------------------
        $topDamagePerSf = DB::select("
            SELECT t.battle_id, t.alliance_id, t.sub_fleet_id, t.partition_algo_version, t.character_id
              FROM (
                  SELECT f.*,
                         ROW_NUMBER() OVER (
                             PARTITION BY f.battle_id, f.alliance_id, f.sub_fleet_id, f.partition_algo_version
                             ORDER BY f.damage_share DESC, f.character_id ASC
                         ) rn
                    FROM battle_character_role_features f
                   WHERE f.damage_share > 0
              ) t
             WHERE t.rn = 1
        ");
        $mlCount = 0; $mlCorrect = 0;
        foreach ($topDamagePerSf as $t) {
            $mlCount++;
            $inf = DB::table('battle_character_role_inference')
                ->where('battle_id', $t->battle_id)
                ->where('alliance_id', $t->alliance_id)
                ->where('sub_fleet_id', $t->sub_fleet_id)
                ->where('partition_algo_version', $t->partition_algo_version)
                ->where('weight_version', $wv)
                ->where('primary_role_key', 'mainline_dps')
                ->first();
            if ($inf === null) continue;
            if ((int) $inf->character_id === (int) $t->character_id) $mlCorrect++;
        }
        $mlAccuracy = $mlCount > 0 ? $mlCorrect / $mlCount : 0.0;
        $inserts[] = [
            'weight_version' => $wv,
            'role_key' => 'mainline_dps',
            'evaluated_at' => $now,
            'attestation_count' => $mlCount,
            'correct_count' => $mlCorrect,
            'accuracy' => round($mlAccuracy, 4),
            'threshold' => round($thresholds['mainline_dps'], 4),
            'passed' => $mlAccuracy >= $thresholds['mainline_dps'] ? 1 : 0,
            'notes' => 'mainline_dps vs top-damage_share per sub-fleet',
        ];

        DB::table('battle_role_calibration_runs')->insert($inserts);

        $this->table(
            ['role', 'truth_n', 'correct', 'accuracy/F1', 'threshold', 'passed'],
            collect($inserts)->map(fn ($r) => [
                $r['role_key'], $r['attestation_count'], $r['correct_count'],
                $r['accuracy'], $r['threshold'], $r['passed'] ? 'YES' : 'no',
            ])->all(),
        );

        $this->info("Calibration evaluated for weight_version={$wv} ({$version->label}). "
            . count($inserts) . ' rows written.');

        return self::SUCCESS;
    }
}
