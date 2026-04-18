<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Add tackle / bomber / command role weight sets to v1_calibrated_seed
|--------------------------------------------------------------------------
|
| Extends coverage beyond fc/logi/mainline_dps. All three use the
| existing ship_class_category values (no new category needed):
|
|   tackle  — set-membership role (many tackle frigs per sub-fleet).
|             Hull prior tackle dominant, low damage_share, high kpr,
|             non-SF0 bonus.
|   bomber  — set-membership (bomber wings are homogeneous).
|             Hull prior bomber dominant, low damage_share, moderate kpr.
|   command — single-winner (one command anchor per sub-fleet).
|             Hull prior command positive but lower than FC's so the
|             two roles don't fight.
|
| Historical coefficients (0.10 each) are also appended for these
| three roles, so battle:refresh-priors can extend the priors table
| in a follow-up migration. For now, the refresh command only emits
| fc/logi/mainline_dps priors — tackle/bomber/command priors = 0
| until the refresh command is updated.
|
| Ewar deferred: requires expanding ship_class_category_mapping +
| ship_class_category CHECK to include 'ewar'. Flagged as next cycle.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Ensure battle_roles has 'bomber' and 'command' (Spec 1
        // seeded them absent; tackle/fc/logi/mainline_dps already exist).
        $existingRoles = DB::table('battle_roles')->pluck('role_key')->all();
        foreach (['bomber' => 'Bomber', 'command' => 'Command / FC auxiliary'] as $key => $name) {
            if (! in_array($key, $existingRoles, true)) {
                DB::table('battle_roles')->insert([
                    'role_key' => $key,
                    'display_name' => $name,
                    'sort_order' => 99,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $v = DB::table('battle_role_weight_versions')->where('label', 'v1_calibrated_seed')->first();
        if ($v === null) {
            throw new RuntimeException('v1_calibrated_seed missing; Spec 7 seed migration not applied.');
        }
        $versionId = (int) $v->weight_version;

        // Remove any prior rows for these roles (idempotent re-run).
        DB::table('battle_role_scoring_weights')
            ->where('weight_version', $versionId)
            ->whereIn('role_key', ['tackle', 'bomber', 'command'])
            ->delete();

        $rows = [];

        // ============ tackle_weights_standard ============
        $tackle = [
            ['temporal',   'damage_share_inverse',     0.1500],
            ['temporal',   'kill_participation_rate',  0.1500],
            ['temporal',   'presence_span',            0.0500],
            ['structural', 'degree_centrality',        0.0500],
            ['hull',       'hull_prior.tackle',        0.5000],
            ['hull',       'hull_prior.logi',         -0.3000],
            ['hull',       'hull_prior.bomber',       -0.3000],
            ['hull',       'hull_prior.command',      -0.2000],
            ['hull',       'hull_prior.mainline',     -0.2000],
            ['hull',       'hull_prior.other',        -0.0500],
            ['hull',       'context_bonus.non_sf0_with_logi', 0.0500],
            ['historical', 'historical_prior',         0.1000],
        ];
        foreach ($tackle as [$cls, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => 'tackle', 'score_class' => $cls,
                'coefficient_key' => "tackle_weights_standard.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v1 tackle seed', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // ============ bomber_weights_standard ============
        $bomber = [
            ['temporal',   'kill_participation_rate',  0.1500],
            ['temporal',   'damage_share',             0.1000],
            ['temporal',   'presence_span',            0.0500],
            ['structural', 'degree_centrality',        0.0500],
            ['hull',       'hull_prior.bomber',        0.6000],
            ['hull',       'hull_prior.logi',         -0.4000],
            ['hull',       'hull_prior.command',      -0.3000],
            ['hull',       'hull_prior.mainline',     -0.2000],
            ['hull',       'hull_prior.tackle',       -0.2000],
            ['hull',       'hull_prior.other',        -0.1000],
            ['historical', 'historical_prior',         0.1000],
        ];
        foreach ($bomber as [$cls, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => 'bomber', 'score_class' => $cls,
                'coefficient_key' => "bomber_weights_standard.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v1 bomber seed', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // ============ command_weights_standard (non-Monitor command anchors) ============
        $command = [
            ['structural', 'degree_centrality',        0.1500],
            ['structural', 'pagerank',                 0.1500],
            ['temporal',   'presence_span',            0.1000],
            ['temporal',   'death_order_pct',          0.1000],
            ['hull',       'hull_prior.command',       0.4000],
            ['hull',       'hull_prior.mainline',     -0.1000],
            ['hull',       'hull_prior.logi',         -0.2000],
            ['hull',       'hull_prior.bomber',       -0.3000],
            ['hull',       'hull_prior.tackle',       -0.1500],
            ['hull',       'hull_prior.other',        -0.1000],
            ['hull',       'context_bonus.non_sf0_with_logi', 0.1000],
            ['historical', 'historical_prior',         0.1000],
        ];
        foreach ($command as [$cls, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => 'command', 'score_class' => $cls,
                'coefficient_key' => "command_weights_standard.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v1 command seed', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // Thresholds + gaps. tackle + bomber are set-membership (no gap).
        $thresholds = [
            ['tackle',  'tackle_threshold',  0.4500],
            ['bomber',  'bomber_threshold',  0.4500],
            ['command', 'command_threshold', 0.5000],
            ['command', 'command_gap',       0.1000],
        ];
        foreach ($thresholds as [$role, $feat, $val]) {
            $rows[] = [
                'weight_version' => $versionId, 'role_key' => $role, 'score_class' => 'final',
                'coefficient_key' => "thresholds_and_gaps_v0.{$feat}", 'coefficient_value' => $val,
                'is_active' => 1, 'notes' => 'v1 threshold', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('battle_role_scoring_weights')->insert($rows);
    }

    public function down(): void
    {
        $v = DB::table('battle_role_weight_versions')->where('label', 'v1_calibrated_seed')->first();
        if ($v === null) return;
        DB::table('battle_role_scoring_weights')
            ->where('weight_version', $v->weight_version)
            ->whereIn('role_key', ['tackle', 'bomber', 'command'])
            ->delete();
    }
};
