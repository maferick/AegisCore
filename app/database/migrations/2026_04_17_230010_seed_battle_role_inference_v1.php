<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Seed Spec 1 — Battle role inference foundation
|--------------------------------------------------------------------------
|
| Seeds:
|  - 10 active roles
|  - the v1_seed default weight version
|  - the v1_placeholder partition algorithm version
|  - a single placeholder scoring-weight row per flagship role so
|    downstream code has a valid version anchor
|  - the sparse canonical hull priors (FC / booster / logi / tackle /
|    dictor flagships); T3Cs / command destroyers / fit-dependent
|    cases deferred to calibration
|  - per-role UI trust gates
|
| Rollback safely removes every row introduced here. Ship-type ids are
| resolved from ref_item_types.name at migration time so a reference
| refresh doesn't break the seed.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // 1. Roles
        DB::table('battle_roles')->insert([
            ['role_key' => 'fc', 'display_name' => 'Fleet Commander', 'sort_order' => 10, 'is_active' => 1],
            ['role_key' => 'booster', 'display_name' => 'Booster', 'sort_order' => 20, 'is_active' => 1],
            ['role_key' => 'logi', 'display_name' => 'Logistics', 'sort_order' => 30, 'is_active' => 1],
            ['role_key' => 'anchor', 'display_name' => 'Anchor', 'sort_order' => 40, 'is_active' => 1],
            ['role_key' => 'tackle', 'display_name' => 'Tackle', 'sort_order' => 50, 'is_active' => 1],
            ['role_key' => 'anti_tackle', 'display_name' => 'Anti-Tackle', 'sort_order' => 60, 'is_active' => 1],
            ['role_key' => 'ewar', 'display_name' => 'Electronic Warfare', 'sort_order' => 70, 'is_active' => 1],
            ['role_key' => 'mainline_dps', 'display_name' => 'Mainline DPS', 'sort_order' => 80, 'is_active' => 1],
            ['role_key' => 'skirmish_dps', 'display_name' => 'Skirmish DPS', 'sort_order' => 90, 'is_active' => 1],
            ['role_key' => 'unknown_support', 'display_name' => 'Unknown Support', 'sort_order' => 100, 'is_active' => 1],
        ]);

        // 2. Weight-version header
        $weightVersionId = DB::table('battle_role_weight_versions')->insertGetId([
            'label' => 'v1_seed',
            'description' => 'Initial uncalibrated v0 seed coefficients',
            'is_default' => 1,
            'created_by' => 'spec1',
        ]);

        // 3. Partition algo header
        DB::table('battle_sub_fleet_algo_versions')->insert([
            'label' => 'v1_placeholder',
            'description' => 'Placeholder partition algorithm version reserved by Spec 1',
        ]);

        // 4. Sparse placeholder scoring-weight rows so downstream code
        //    has a valid version anchor. Real coefficients land in a
        //    later spec.
        DB::table('battle_role_scoring_weights')->insert([
            ['weight_version' => $weightVersionId, 'role_key' => 'fc',      'score_class' => 'final', 'coefficient_key' => 'seed_placeholder', 'coefficient_value' => 1.0000, 'is_active' => 1, 'notes' => 'v0 seed coefficients'],
            ['weight_version' => $weightVersionId, 'role_key' => 'booster', 'score_class' => 'final', 'coefficient_key' => 'seed_placeholder', 'coefficient_value' => 1.0000, 'is_active' => 1, 'notes' => 'v0 seed coefficients'],
            ['weight_version' => $weightVersionId, 'role_key' => 'logi',    'score_class' => 'final', 'coefficient_key' => 'seed_placeholder', 'coefficient_value' => 1.0000, 'is_active' => 1, 'notes' => 'v0 seed coefficients'],
        ]);

        // 5. Sparse hull priors — resolve ship_type_id from
        //    ref_item_types.name. Silently skip any hull our SDE
        //    snapshot doesn't carry (prior is simply absent → scorer
        //    uses default fallback).
        $priors = [
            // FC
            ['role' => 'fc',      'ship' => 'Monitor',     'weight' => 1.0000, 'notes' => 'Canonical FC hull'],

            // Booster
            ['role' => 'booster', 'ship' => 'Damnation',   'weight' => 1.0000, 'notes' => 'Canonical booster hull'],
            ['role' => 'booster', 'ship' => 'Claymore',    'weight' => 1.0000, 'notes' => 'Canonical booster hull'],
            ['role' => 'booster', 'ship' => 'Vulture',     'weight' => 1.0000, 'notes' => 'Canonical booster hull'],
            ['role' => 'booster', 'ship' => 'Eos',         'weight' => 1.0000, 'notes' => 'Canonical booster hull'],

            // Logi (cruiser)
            ['role' => 'logi',    'ship' => 'Guardian',    'weight' => 1.0000, 'notes' => 'Canonical logi hull'],
            ['role' => 'logi',    'ship' => 'Basilisk',    'weight' => 1.0000, 'notes' => 'Canonical logi hull'],
            ['role' => 'logi',    'ship' => 'Scimitar',    'weight' => 1.0000, 'notes' => 'Canonical logi hull'],
            ['role' => 'logi',    'ship' => 'Oneiros',     'weight' => 1.0000, 'notes' => 'Canonical logi hull'],
            ['role' => 'logi',    'ship' => 'Zarmazd',     'weight' => 1.0000, 'notes' => 'Canonical logi hull'],

            // Logi (frigate)
            ['role' => 'logi',    'ship' => 'Deacon',      'weight' => 0.9000, 'notes' => 'Frigate logi'],
            ['role' => 'logi',    'ship' => 'Kirin',       'weight' => 0.9000, 'notes' => 'Frigate logi'],
            ['role' => 'logi',    'ship' => 'Scalpel',     'weight' => 0.9000, 'notes' => 'Frigate logi'],
            ['role' => 'logi',    'ship' => 'Thalia',      'weight' => 0.9000, 'notes' => 'Frigate logi'],

            // Tackle interceptors
            ['role' => 'tackle',  'ship' => 'Stiletto',    'weight' => 1.0000, 'notes' => 'Canonical tackle interceptor'],
            ['role' => 'tackle',  'ship' => 'Malediction', 'weight' => 1.0000, 'notes' => 'Canonical tackle interceptor'],
            ['role' => 'tackle',  'ship' => 'Crow',        'weight' => 1.0000, 'notes' => 'Canonical tackle interceptor'],
            ['role' => 'tackle',  'ship' => 'Ares',        'weight' => 1.0000, 'notes' => 'Canonical tackle interceptor'],

            // Tackle support — dictors
            ['role' => 'tackle',  'ship' => 'Sabre',       'weight' => 0.9500, 'notes' => 'Dictor (tackle support)'],
            ['role' => 'tackle',  'ship' => 'Flycatcher',  'weight' => 0.9500, 'notes' => 'Dictor (tackle support)'],
            ['role' => 'tackle',  'ship' => 'Heretic',     'weight' => 0.9500, 'notes' => 'Dictor (tackle support)'],
            ['role' => 'tackle',  'ship' => 'Eris',        'weight' => 0.9500, 'notes' => 'Dictor (tackle support)'],
        ];

        $shipIds = DB::table('ref_item_types')
            ->whereIn('name', array_column($priors, 'ship'))
            ->pluck('id', 'name');

        $rows = [];
        $skipped = [];
        foreach ($priors as $p) {
            if (! isset($shipIds[$p['ship']])) {
                $skipped[] = $p['ship'];
                continue;
            }
            $rows[] = [
                'role_key' => $p['role'],
                'ship_type_id' => (int) $shipIds[$p['ship']],
                'prior_weight' => $p['weight'],
                'notes' => $p['notes'],
            ];
        }
        if ($rows !== []) {
            DB::table('battle_role_hull_priors')->insert($rows);
        }
        if ($skipped !== []) {
            // Not an error — the SDE may predate some hulls. Surface
            // in migration output so an operator can backfill later.
            echo '  [seed] skipped unresolved hulls: '.implode(', ', $skipped).PHP_EOL;
        }

        // 6. Per-role UI trust gates
        DB::table('battle_role_ui_trust_gates')->insert([
            ['role_key' => 'logi',    'metric_key' => 'top1_accuracy', 'threshold_value' => 0.9000, 'ui_state_on_pass' => 'production', 'is_active' => 1, 'notes' => 'Logi is expected to mature first'],
            ['role_key' => 'fc',      'metric_key' => 'top1_accuracy', 'threshold_value' => 0.7500, 'ui_state_on_pass' => 'production', 'is_active' => 1, 'notes' => 'FC remains beta until validated'],
            ['role_key' => 'booster', 'metric_key' => 'top1_accuracy', 'threshold_value' => 0.7000, 'ui_state_on_pass' => 'production', 'is_active' => 1, 'notes' => 'Off-grid/unobserved cases depress ceiling'],
            ['role_key' => 'anchor',  'metric_key' => 'top1_accuracy', 'threshold_value' => 0.7500, 'ui_state_on_pass' => 'production', 'is_active' => 1, 'notes' => 'Anchor depends on stable sub-fleet partitioning'],
        ]);
    }

    public function down(): void
    {
        // Rollback everything seeded by up(). Order matters: dependent
        // rows first, headers last.
        DB::table('battle_role_ui_trust_gates')->truncate();
        DB::table('battle_role_hull_priors')->truncate();
        DB::table('battle_role_scoring_weights')->truncate();
        DB::table('battle_sub_fleet_algo_versions')->where('label', 'v1_placeholder')->delete();
        DB::table('battle_role_weight_versions')->where('label', 'v1_seed')->delete();
        DB::table('battle_roles')->truncate();
    }
};
