<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 7 v1 calibrated seed (weight_version with historical coefficients)
|--------------------------------------------------------------------------
|
| Clones the v0 seed weight set and appends historical coefficients
| so the Python scorer picks up the new 'historical' component
| without any code change (ACTIVE_CLASSES is extended in the scorer
| separately).
|
| Historical weights per Spec 7 §3:
|   FC: 0.15
|   Logi: 0.10
|   Mainline_DPS: 0.15
|
| is_default stays 0 here — promotion happens via
| `php artisan battle:promote-weight-version` after evaluation
| against attestations clears the configured accuracy threshold.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $existing = DB::table('battle_role_weight_versions')->where('label', 'v1_calibrated_seed')->first();
        if ($existing !== null) {
            $versionId = (int) $existing->weight_version;
            DB::table('battle_role_scoring_weights')->where('weight_version', $versionId)->delete();
        } else {
            $versionId = DB::table('battle_role_weight_versions')->insertGetId([
                'label' => 'v1_calibrated_seed',
                'description' => 'Spec 7 v1: v0 coefficients + historical priors (FC 0.15, logi 0.10, mainline_dps 0.15). Not yet default; promote via battle:promote-weight-version after calibration evaluation passes accuracy threshold.',
                'is_default' => 0,
                'created_by' => 'spec7',
                'created_at' => $now,
            ]);
        }

        // Copy every v0 coefficient row into the new weight_version unchanged.
        $v0 = DB::table('battle_role_weight_versions')->where('label', 'v0_scoring_seed')->first();
        if ($v0 === null) {
            throw new RuntimeException('v0_scoring_seed missing; Spec 5 migration not applied.');
        }
        $v0Rows = DB::table('battle_role_scoring_weights')
            ->where('weight_version', $v0->weight_version)
            ->get();

        $inserts = [];
        foreach ($v0Rows as $r) {
            $inserts[] = [
                'weight_version' => $versionId,
                'role_key' => $r->role_key,
                'score_class' => $r->score_class,
                'coefficient_key' => $r->coefficient_key,
                'coefficient_value' => $r->coefficient_value,
                'is_active' => 1,
                'notes' => 'v1 seed (cloned from v0)',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Append historical coefficients — one per role, keyed as
        // "<weight_set>.historical_prior". The scorer's compute_historical_score()
        // function reads this coefficient and multiplies by the pilot's
        // prior_value from character_role_historical_priors.
        $historicalCoefs = [
            ['fc',           'fc_weights_standard.historical_prior',           0.1500],
            ['fc',           'fc_weights_command_edge.historical_prior',       0.1500],
            ['logi',         'logi_weights_standard.historical_prior',         0.1000],
            ['mainline_dps', 'mainline_dps_weights_standard.historical_prior', 0.1500],
        ];
        foreach ($historicalCoefs as [$role, $key, $val]) {
            $inserts[] = [
                'weight_version' => $versionId,
                'role_key' => $role,
                'score_class' => 'historical',
                'coefficient_key' => $key,
                'coefficient_value' => $val,
                'is_active' => 1,
                'notes' => 'v1 historical coefficient',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($inserts, 100) as $chunk) {
            DB::table('battle_role_scoring_weights')->insert($chunk);
        }
    }

    public function down(): void
    {
        $v = DB::table('battle_role_weight_versions')->where('label', 'v1_calibrated_seed')->first();
        if ($v === null) return;
        DB::table('battle_role_scoring_weights')->where('weight_version', $v->weight_version)->delete();
        DB::table('battle_role_weight_versions')->where('weight_version', $v->weight_version)->delete();
    }
};
