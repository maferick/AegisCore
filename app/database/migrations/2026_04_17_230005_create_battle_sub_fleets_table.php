<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_sub_fleets — authoritative sub-fleet header
|--------------------------------------------------------------------------
|
| Per Spec 1. sub_fleet_id is stable only within a given
| (battle_id, alliance_id), ordered by member_count DESC with
| deterministic tie-breaking in the partitioner (Spec 3). Features,
| scores and inference rows all reference this table so orphaned rows
| are impossible.
|
| battle_id is intentionally not FK'd to battle_theaters here —
| Spec 1's load-bearing surface is only the sub-fleet header + its
| dependents; the binding to concrete battle storage is a Spec 2/3
| concern.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_sub_fleets (
                battle_id                  BIGINT NOT NULL,
                alliance_id                BIGINT NOT NULL,
                sub_fleet_id               INT NOT NULL,
                member_count               INT NOT NULL,
                partition_algo_version     INT NOT NULL,
                computed_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (battle_id, alliance_id, sub_fleet_id),
                KEY idx_bsf_battle_side (battle_id, alliance_id),
                CONSTRAINT fk_bsf_partition_algo_version
                    FOREIGN KEY (partition_algo_version)
                    REFERENCES battle_sub_fleet_algo_versions(partition_algo_version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_sub_fleets');
    }
};
