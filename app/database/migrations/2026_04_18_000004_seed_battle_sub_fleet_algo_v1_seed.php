<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Promote the Spec 1 v1_placeholder row to v1_seed with real rule config
|--------------------------------------------------------------------------
|
| Per Spec 3. If the Spec 1 placeholder row is still present it is
| updated in-place so any row already holding its partition_algo_version
| keeps its FK target. If it's been deleted (e.g. in a test tear-down)
| a fresh v1_seed row is inserted.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        $placeholder = DB::table('battle_sub_fleet_algo_versions')
            ->where('label', 'v1_placeholder')
            ->first();

        if ($placeholder !== null) {
            DB::table('battle_sub_fleet_algo_versions')
                ->where('partition_algo_version', $placeholder->partition_algo_version)
                ->update([
                    'label' => 'v1_seed',
                    'description' => 'Initial partitioning rule: Louvain communities, min_community_size=10, orphans absorbed into sub_fleet_id=0',
                    'min_community_size' => 10,
                    'orphan_reassignment_rule' => 'absorb_into_sub_fleet_zero',
                    'is_default' => 1,
                ]);
            return;
        }

        DB::table('battle_sub_fleet_algo_versions')->insert([
            'label' => 'v1_seed',
            'description' => 'Initial partitioning rule: Louvain communities, min_community_size=10, orphans absorbed into sub_fleet_id=0',
            'min_community_size' => 10,
            'orphan_reassignment_rule' => 'absorb_into_sub_fleet_zero',
            'is_default' => 1,
        ]);
    }

    public function down(): void
    {
        DB::table('battle_sub_fleet_algo_versions')
            ->where('label', 'v1_seed')
            ->update([
                'label' => 'v1_placeholder',
                'description' => 'Placeholder partition algorithm version reserved by Spec 1',
                'is_default' => 0,
            ]);
    }
};
