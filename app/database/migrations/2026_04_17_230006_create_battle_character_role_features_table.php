<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_character_role_features — materialized features per char/sub-fleet
|--------------------------------------------------------------------------
|
| Per Spec 1. One row per (battle_id, alliance_id, sub_fleet_id,
| character_id). Primary sub-fleet assignment is "majority time spent",
| so a character has exactly one row per battle-side in v1; transition
| tracking is deferred.
|
| Feature extraction logic + feature manifest are a Spec 2 concern;
| this table reserves the columns with conservative DECIMAL(5,4)
| storage and range-check constraints on the completeness + bucket
| fields.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_character_role_features (
                battle_id                    BIGINT NOT NULL,
                alliance_id                  BIGINT NOT NULL,
                sub_fleet_id                 INT NOT NULL,
                character_id                 BIGINT NOT NULL,
                ship_type_id                 INT NULL,

                primary_sub_fleet_share      DECIMAL(5,4) NOT NULL DEFAULT 1.0000,

                presence_span                DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                early_presence               DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                late_presence                DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                death_order_pct              DECIMAL(5,4) NOT NULL DEFAULT 0.0000,

                kill_participation_rate      DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                victim_overlap_density       DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                same_bucket_cooccurrence     DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                engagement_phase_count_norm  DECIMAL(5,4) NOT NULL DEFAULT 0.0000,

                degree_centrality            DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                betweenness_centrality       DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                pagerank                     DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                clustering_coefficient       DECIMAL(5,4) NOT NULL DEFAULT 0.0000,

                local_blob_score             DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                support_ring_score           DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                edge_cluster_score           DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                logi_ring_affinity           DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                fc_core_affinity             DECIMAL(5,4) NOT NULL DEFAULT 0.0000,

                damage_share                 DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                final_blow_rate              DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                contributed_kill_rate        DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                isk_killed_share             DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                isk_lost_norm                DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                target_spread                DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                focus_fire_alignment         DECIMAL(5,4) NOT NULL DEFAULT 0.0000,

                feature_completeness         DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
                bucket_seconds               SMALLINT NOT NULL DEFAULT 30,

                computed_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (battle_id, alliance_id, sub_fleet_id, character_id),

                KEY idx_bcrf_battle_side_subfleet (battle_id, alliance_id, sub_fleet_id),
                KEY idx_bcrf_battle_character (battle_id, character_id),
                KEY idx_bcrf_ship_type (ship_type_id),

                CONSTRAINT fk_bcrf_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id),

                CONSTRAINT chk_bcrf_primary_subfleet_share
                    CHECK (primary_sub_fleet_share >= 0.0000 AND primary_sub_fleet_share <= 1.0000),
                CONSTRAINT chk_bcrf_feature_completeness
                    CHECK (feature_completeness >= 0.0000 AND feature_completeness <= 1.0000),
                CONSTRAINT chk_bcrf_bucket_seconds
                    CHECK (bucket_seconds > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_character_role_features');
    }
};
