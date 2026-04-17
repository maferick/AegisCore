<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_sub_fleet_algo_versions — header for partition-algorithm versions
|--------------------------------------------------------------------------
|
| Per Spec 1. Every row in battle_sub_fleets must reference a version
| here so sub-fleet partition provenance is auditable from day one.
| Spec 3 will land the actual partitioning logic; Spec 1 reserves the
| header table with a v1_placeholder row so foreign keys have a target.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_sub_fleet_algo_versions (
                partition_algo_version    INT NOT NULL AUTO_INCREMENT,
                label                     VARCHAR(64) NOT NULL,
                description               TEXT NULL,
                created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (partition_algo_version),
                UNIQUE KEY uk_bsfav_label (label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_sub_fleet_algo_versions');
    }
};
