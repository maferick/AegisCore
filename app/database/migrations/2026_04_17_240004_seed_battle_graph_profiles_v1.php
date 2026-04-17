<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Seed Spec 2 — Battle graph edge + algo profiles
|--------------------------------------------------------------------------
|
| Seeds the v1_seed default rows for edge construction and algorithm
| toggles. All values are uncalibrated v0 defaults; Spec 4+ will land
| a calibrated profile. Every metrics row records the profile versions
| used so v0 data remains comparable after the v1 seeds are retired.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('battle_graph_edge_profile_versions')->insert([
            'label' => 'v1_seed',
            'description' => 'Initial uncalibrated edge construction coefficients',
            'bucket_seconds' => 30,
            'phase_seconds' => 300,
            'same_bucket_coef' => 0.5000,
            'victim_overlap_coef' => 0.3000,
            'phase_cooccur_coef' => 0.2000,
            'min_edge_weight' => 0.0500,
            'is_default' => 1,
        ]);

        DB::table('battle_graph_algo_profile_versions')->insert([
            'label' => 'v1_seed',
            'description' => 'Initial uncalibrated algorithm configuration',
            'run_pagerank' => 1,
            'run_betweenness' => 0,
            'run_clustering_coefficient' => 0,
            'run_louvain' => 1,
            'pagerank_damping' => 0.8500,
            'pagerank_max_iterations' => 20,
            'louvain_max_iterations' => 10,
            'louvain_tolerance' => 0.000100,
            'small_tier_max' => 10,
            'medium_tier_max' => 500,
            'large_tier_max' => 2000,
            'is_default' => 1,
        ]);
    }

    public function down(): void
    {
        DB::table('battle_graph_edge_profile_versions')->where('label', 'v1_seed')->delete();
        DB::table('battle_graph_algo_profile_versions')->where('label', 'v1_seed')->delete();
    }
};
