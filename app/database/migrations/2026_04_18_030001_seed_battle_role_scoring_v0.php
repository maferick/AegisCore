<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 5 v0 scoring seed (weight_version=2)
|--------------------------------------------------------------------------
|
| Seeds five weight sets used by the battle_role_scoring worker:
|
|   fc_weights_standard        — behavior-first FC scoring
|   fc_weights_command_edge    — hull-dominant FC scoring for the
|                                 command-edge path (Spec 5 §5; condition
|                                 relaxed v0 to:
|                                     ship_class_category = 'command'
|                                     AND subfleet_dominant_hull_class <> 'command'
|                                 so it actually triggers on live fleets
|                                 where the FC's own wing isn't command-dominant)
|   logi_weights_standard      — hull + behavior for logi detection
|   mainline_dps_weights_standard  — hull + behavior for mainline detection
|   thresholds_and_gaps_v0     — per-role threshold + gap values
|
| All values are **uncalibrated seeds**. They are deliberately
| conservative to favour silence on v0 first-run. Calibration is Spec 7.
|
| is_default = 0 on the weight version so downstream consumers opt in
| explicitly by passing weight_version = 2 to the scoring job.
|
| coefficient_key convention:
|     <weight_set_name>.<feature_name>[.<sub_key>]
| e.g. fc_weights_standard.degree_centrality
|      logi_weights_standard.hull_prior.bomber
|      thresholds_and_gaps_v0.fc_threshold
|
| The scorer looks up coefficients by exact string match. One row per
| coefficient; a full weight set is many rows.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $existing = DB::table('battle_role_weight_versions')->where('label', 'v0_scoring_seed')->first();
        if ($existing !== null) {
            $versionId = (int) $existing->weight_version;
            DB::table('battle_role_scoring_weights')->where('weight_version', $versionId)->delete();
        } else {
            $versionId = DB::table('battle_role_weight_versions')->insertGetId([
                'label' => 'v0_scoring_seed',
                'description' => 'Spec 5 v0 uncalibrated scoring coefficients. Not validated against ground truth. Thresholds set conservatively; system may produce zero assignments on initial runs. Calibration is Spec 7.',
                'is_default' => 0,
                'created_by' => 'spec5',
                'created_at' => $now,
            ]);
        }

        $rows = [];

        // ============ fc_weights_standard ============
        $fcStd = [
            ['structural', 'degree_centrality', 0.3500],
            ['structural', 'pagerank',          0.2500],
            ['temporal',   'presence_span',     0.1500],
            ['temporal',   'early_presence',    0.0500],
            ['temporal',   'late_presence',     0.0500],
            ['temporal',   'death_order_pct',   0.1000],
            ['hull',       'hull_prior.command',   0.1500],
            ['hull',       'hull_prior.mainline',  0.0500],
            ['hull',       'hull_prior.logi',     -0.2000],
            ['hull',       'hull_prior.bomber',   -0.3000],
            ['hull',       'hull_prior.tackle',   -0.1000],
            ['hull',       'hull_prior.other',     0.0000],
        ];
        foreach ($fcStd as [$cls, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => 'fc', 'score_class' => $cls,
                'coefficient_key' => "fc_weights_standard.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v0 seed', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // ============ fc_weights_command_edge ============
        $fcEdge = [
            ['structural', 'degree_centrality', 0.0500],
            ['structural', 'pagerank',          0.0500],
            ['temporal',   'presence_span',     0.0500],
            ['hull',       'hull_prior.command',              0.6000],
            ['hull',       'context_bonus.non_sf0_with_logi', 0.2000],
            ['hull',       'context_bonus.mixed_composition', 0.0500],
        ];
        foreach ($fcEdge as [$cls, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => 'fc', 'score_class' => $cls,
                'coefficient_key' => "fc_weights_command_edge.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v0 seed, command-edge path', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // ============ logi_weights_standard ============
        $logi = [
            ['temporal',   'damage_share_inverse',   0.2000],
            ['temporal',   'kill_participation_rate', 0.1500],
            ['temporal',   'presence_span',          0.1000],
            ['structural', 'degree_centrality',      0.1000],
            ['hull',       'hull_prior.logi',     0.4500],
            ['hull',       'hull_prior.command', -0.2000],
            ['hull',       'hull_prior.bomber',  -0.4000],
            ['hull',       'hull_prior.tackle',  -0.3000],
            ['hull',       'hull_prior.mainline',-0.2000],
            ['hull',       'hull_prior.other',   -0.1000],
        ];
        foreach ($logi as [$cls, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => 'logi', 'score_class' => $cls,
                'coefficient_key' => "logi_weights_standard.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v0 seed', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // ============ mainline_dps_weights_standard ============
        $mainline = [
            ['temporal',   'damage_share',            0.3000],
            ['temporal',   'kill_participation_rate', 0.1500],
            ['structural', 'degree_centrality',       0.1000],
            ['hull',       'hull_prior.mainline', 0.3000],
            ['hull',       'hull_prior.command',  0.0500],
            ['hull',       'hull_prior.logi',    -0.3000],
            ['hull',       'hull_prior.bomber',  -0.2000],
            ['hull',       'hull_prior.tackle',  -0.1500],
            ['hull',       'hull_prior.other',   -0.1000],
            ['hull',       'context_bonus.is_in_subfleet_0', 0.1000],
        ];
        foreach ($mainline as [$cls, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => 'mainline_dps', 'score_class' => $cls,
                'coefficient_key' => "mainline_dps_weights_standard.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v0 seed', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // ============ thresholds_and_gaps_v0 (score_class = 'final') ============
        $thresholds = [
            ['fc',           'fc_threshold',       0.5500],
            ['fc',           'fc_gap',             0.1500],
            ['logi',         'logi_threshold',     0.4500],
            ['mainline_dps', 'mainline_threshold', 0.5000],
            ['mainline_dps', 'mainline_gap',       0.1000],
        ];
        foreach ($thresholds as [$role, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => $role, 'score_class' => 'final',
                'coefficient_key' => "thresholds_and_gaps_v0.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v0 seed threshold/gap', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('battle_role_scoring_weights')->insert($chunk);
        }
    }

    public function down(): void
    {
        $version = DB::table('battle_role_weight_versions')->where('label', 'v0_scoring_seed')->first();
        if ($version === null) {
            return;
        }
        DB::table('battle_role_scoring_weights')->where('weight_version', $version->weight_version)->delete();
        DB::table('battle_role_weight_versions')->where('weight_version', $version->weight_version)->delete();
    }
};
