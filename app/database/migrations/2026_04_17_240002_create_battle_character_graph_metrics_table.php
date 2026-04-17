<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_character_graph_metrics — staging table for raw graph outputs
|--------------------------------------------------------------------------
|
| Per Spec 2. Consumed by Spec 3 (sub-fleet partitioning) and Spec 4
| (feature extraction). Not read by UI. Values are profile-dependent
| compute artifacts; the SAME battle under a different
| (edge_profile_version, algo_profile_version) combo produces different
| rows that coexist.
|
| community_id_raw is the within-run Louvain label and is NOT stable
| across runs. Consumers compare on community_rank_by_size (0 = largest
| community, size-descending with tie-break by lowest member char id).
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_character_graph_metrics (
                battle_id                    BIGINT NOT NULL,
                alliance_id                  BIGINT NOT NULL,
                character_id                 BIGINT NOT NULL,
                edge_profile_version         INT NOT NULL,
                algo_profile_version         INT NOT NULL,

                weighted_degree_raw          DECIMAL(10,4) NULL,
                pagerank_raw                 DECIMAL(10,6) NULL,
                betweenness_raw              DECIMAL(12,4) NULL,
                clustering_coefficient       DECIMAL(5,4) NULL,

                community_id_raw             INT NULL,
                community_size               INT NULL,
                community_rank_by_size       INT NULL,

                pilot_count_in_projection    INT NOT NULL,
                graph_tier                   VARCHAR(16) NOT NULL,
                skip_reason                  VARCHAR(64) NULL,

                computed_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (battle_id, alliance_id, character_id, edge_profile_version, algo_profile_version),

                KEY idx_bcgm_battle_side (battle_id, alliance_id, edge_profile_version, algo_profile_version),
                KEY idx_bcgm_community (battle_id, alliance_id, community_id_raw),

                CONSTRAINT fk_bcgm_edge_profile
                    FOREIGN KEY (edge_profile_version)
                    REFERENCES battle_graph_edge_profile_versions(edge_profile_version),
                CONSTRAINT fk_bcgm_algo_profile
                    FOREIGN KEY (algo_profile_version)
                    REFERENCES battle_graph_algo_profile_versions(algo_profile_version),
                CONSTRAINT chk_bcgm_graph_tier
                    CHECK (graph_tier IN ('small', 'medium', 'large', 'huge'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_character_graph_metrics');
    }
};
