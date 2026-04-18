<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_character_sub_fleet_membership — authoritative char → sub_fleet map
|--------------------------------------------------------------------------
|
| Per Spec 3. First-class standalone table: one row per (battle, alliance,
| character, partition_algo_version). `battle_character_role_features`
| carries `sub_fleet_id` as a denormalised read path, integrity guaranteed
| by an FK to this table (Spec 3 migration 4).
|
| Membership rows pin the graph metrics snapshot they were produced
| against via (source_edge_profile_version, source_algo_profile_version).
| Re-running partitioning under different graph profiles produces new
| membership rows alongside the old ones rather than overwriting.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_character_sub_fleet_membership (
                battle_id                    BIGINT NOT NULL,
                alliance_id                  BIGINT NOT NULL,
                character_id                 BIGINT NOT NULL,
                partition_algo_version       INT NOT NULL,

                sub_fleet_id                 INT NOT NULL,
                membership_share             DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
                assignment_method            VARCHAR(32) NOT NULL,
                source_edge_profile_version  INT NOT NULL,
                source_algo_profile_version  INT NOT NULL,
                source_community_id_raw      INT NULL,
                source_community_size        INT NULL,
                was_orphan                   TINYINT(1) NOT NULL DEFAULT 0,

                computed_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (battle_id, alliance_id, character_id, partition_algo_version),

                KEY idx_bcsfm_battle_side_version (battle_id, alliance_id, partition_algo_version),
                KEY idx_bcsfm_sub_fleet (battle_id, alliance_id, sub_fleet_id, partition_algo_version),
                KEY idx_bcsfm_character (character_id),

                CONSTRAINT fk_bcsfm_sub_fleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id),

                CONSTRAINT fk_bcsfm_partition_algo_version
                    FOREIGN KEY (partition_algo_version)
                    REFERENCES battle_sub_fleet_algo_versions(partition_algo_version),

                CONSTRAINT fk_bcsfm_edge_profile
                    FOREIGN KEY (source_edge_profile_version)
                    REFERENCES battle_graph_edge_profile_versions(edge_profile_version),

                CONSTRAINT fk_bcsfm_algo_profile
                    FOREIGN KEY (source_algo_profile_version)
                    REFERENCES battle_graph_algo_profile_versions(algo_profile_version),

                CONSTRAINT chk_bcsfm_membership_share
                    CHECK (membership_share > 0.0000 AND membership_share <= 1.0000),

                CONSTRAINT chk_bcsfm_assignment_method
                    CHECK (assignment_method IN (
                        'louvain_community',
                        'orphan_absorbed',
                        'small_tier_single_fleet'
                    ))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_character_sub_fleet_membership');
    }
};
