<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_graph_algo_profile_versions — header for algorithm toggles
|--------------------------------------------------------------------------
|
| Per Spec 2. Captures the algorithm on/off flags, PageRank / Louvain
| tuning, and the four tier thresholds (small / medium / large / huge)
| that gate which algorithms run for a given projection size.
|
| Betweenness and clustering coefficient default off because both are
| expensive at hundreds-of-nodes scale; the algo profile lets operators
| flip either on for a specific run without redeploying the worker.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_graph_algo_profile_versions (
                algo_profile_version         INT NOT NULL AUTO_INCREMENT,
                label                        VARCHAR(64) NOT NULL,
                description                  TEXT NULL,
                run_pagerank                 TINYINT(1) NOT NULL DEFAULT 1,
                run_betweenness              TINYINT(1) NOT NULL DEFAULT 0,
                run_clustering_coefficient   TINYINT(1) NOT NULL DEFAULT 0,
                run_louvain                  TINYINT(1) NOT NULL DEFAULT 1,
                pagerank_damping             DECIMAL(5,4) NOT NULL DEFAULT 0.8500,
                pagerank_max_iterations      INT NOT NULL DEFAULT 20,
                louvain_max_iterations       INT NOT NULL DEFAULT 10,
                louvain_tolerance            DECIMAL(7,6) NOT NULL DEFAULT 0.000100,
                small_tier_max               INT NOT NULL DEFAULT 10,
                medium_tier_max              INT NOT NULL DEFAULT 500,
                large_tier_max               INT NOT NULL DEFAULT 2000,
                is_default                   TINYINT(1) NOT NULL DEFAULT 0,
                is_default_key               INT GENERATED ALWAYS AS (
                    CASE WHEN is_default = 1 THEN 1 ELSE NULL END
                ) VIRTUAL,
                created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (algo_profile_version),
                UNIQUE KEY uk_bgapv_label (label),
                UNIQUE KEY uk_bgapv_single_default (is_default_key),
                CONSTRAINT chk_bgapv_tier_ordering
                    CHECK (small_tier_max < medium_tier_max AND medium_tier_max < large_tier_max)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_graph_algo_profile_versions');
    }
};
