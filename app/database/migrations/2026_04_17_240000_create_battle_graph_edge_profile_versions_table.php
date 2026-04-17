<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_graph_edge_profile_versions — header for edge-construction knobs
|--------------------------------------------------------------------------
|
| Per Spec 2. Same header pattern as battle_role_weight_versions from
| Spec 1: auto-incrementing version id, unique label, virtual
| is_default_key for at-most-one-default enforcement. FK'd from
| battle_character_graph_metrics and battle_graph_projection_runs.
|
| The three edge-weight coefficients must sum to 1.0 (±1e-4). The
| CHECK constraint guards that invariant so a bad seed INSERT fails
| loud at migration time.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_graph_edge_profile_versions (
                edge_profile_version     INT NOT NULL AUTO_INCREMENT,
                label                    VARCHAR(64) NOT NULL,
                description              TEXT NULL,
                bucket_seconds           SMALLINT NOT NULL DEFAULT 30,
                phase_seconds            SMALLINT NOT NULL DEFAULT 300,
                same_bucket_coef         DECIMAL(5,4) NOT NULL,
                victim_overlap_coef      DECIMAL(5,4) NOT NULL,
                phase_cooccur_coef       DECIMAL(5,4) NOT NULL,
                min_edge_weight          DECIMAL(5,4) NOT NULL DEFAULT 0.0500,
                is_default               TINYINT(1) NOT NULL DEFAULT 0,
                is_default_key           INT GENERATED ALWAYS AS (
                    CASE WHEN is_default = 1 THEN 1 ELSE NULL END
                ) VIRTUAL,
                created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (edge_profile_version),
                UNIQUE KEY uk_bgepv_label (label),
                UNIQUE KEY uk_bgepv_single_default (is_default_key),
                CONSTRAINT chk_bgepv_coef_sum
                    CHECK (ABS(same_bucket_coef + victim_overlap_coef + phase_cooccur_coef - 1.0000) < 0.0001),
                CONSTRAINT chk_bgepv_bucket_seconds
                    CHECK (bucket_seconds > 0),
                CONSTRAINT chk_bgepv_phase_seconds
                    CHECK (phase_seconds > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_graph_edge_profile_versions');
    }
};
