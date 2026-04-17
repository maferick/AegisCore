<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_graph_projection_runs — audit log for every invocation
|--------------------------------------------------------------------------
|
| Per Spec 2. One row per job invocation. Also serves as the
| concurrency-control surface: the worker checks for an existing
| status='running' row on the same (battle, alliance, edge_profile,
| algo_profile) tuple before starting, and uses run_id to tag nodes /
| edges / GDS projection names so cleanup is unambiguous across
| concurrent runs on different tuples.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_graph_projection_runs (
                run_id                   BIGINT NOT NULL AUTO_INCREMENT,
                battle_id                BIGINT NOT NULL,
                alliance_id              BIGINT NOT NULL,
                edge_profile_version     INT NOT NULL,
                algo_profile_version     INT NOT NULL,

                started_at               DATETIME(3) NOT NULL,
                completed_at             DATETIME(3) NULL,
                duration_ms              INT NULL,
                status                   VARCHAR(16) NOT NULL,
                error_message            TEXT NULL,

                pilot_count              INT NULL,
                edge_count               INT NULL,
                graph_tier               VARCHAR(16) NULL,
                algorithms_run_json      JSON NULL,

                PRIMARY KEY (run_id),
                KEY idx_bgpr_battle_side (battle_id, alliance_id, started_at DESC),
                KEY idx_bgpr_status_time (status, started_at DESC),
                CONSTRAINT fk_bgpr_edge_profile
                    FOREIGN KEY (edge_profile_version)
                    REFERENCES battle_graph_edge_profile_versions(edge_profile_version),
                CONSTRAINT fk_bgpr_algo_profile
                    FOREIGN KEY (algo_profile_version)
                    REFERENCES battle_graph_algo_profile_versions(algo_profile_version),
                CONSTRAINT chk_bgpr_status
                    CHECK (status IN ('running', 'success', 'failed', 'skipped'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_graph_projection_runs');
    }
};
