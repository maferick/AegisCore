<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bloc Intelligence — core pair-behavior rolling table.
 *
 * Viewer-agnostic pair metrics. One row per (alliance_a, alliance_b,
 * window). alliance_a < alliance_b enforced so every pair is stored
 * exactly once.
 *
 * All metrics are observations of behavior, never declared membership.
 * Ground-truth labels live in coalition_entity_labels and are applied
 * as an overlay at render time.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE alliance_pair_behavior_rolling (
                alliance_a_id BIGINT UNSIGNED NOT NULL,
                alliance_b_id BIGINT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,

                -- Raw observation counts (killmail-level, sqrt(attacker)-dampened).
                n_obs DECIMAL(12,4) NOT NULL DEFAULT 0,
                n_same_side DECIMAL(12,4) NOT NULL DEFAULT 0,
                n_opposed DECIMAL(12,4) NOT NULL DEFAULT 0,

                -- Decay-weighted (exponential, half-life 30d).
                weighted_n_obs DECIMAL(12,4) NOT NULL DEFAULT 0,
                weighted_same_side DECIMAL(12,4) NOT NULL DEFAULT 0,
                weighted_opposed DECIMAL(12,4) NOT NULL DEFAULT 0,

                -- Derived rates on the weighted totals.
                affinity_score DECIMAL(5,4) NULL,
                hostility_score DECIMAL(5,4) NULL,

                -- Avoidance: same region + same hour, no direct engagement.
                avoidance_windows INT UNSIGNED NOT NULL DEFAULT 0,
                avoidance_ratio DECIMAL(5,4) NULL,

                -- Parallel ops: same region + 30-min window, different
                -- systems, same opponent. Filled by step 2 extractor.
                parallel_ops_events INT UNSIGNED NOT NULL DEFAULT 0,
                parallel_ops_strength DECIMAL(5,4) NULL,

                -- Confidence: min(1, log10(n_obs) / 2). 10 obs = 0.5, 100 = 1.0.
                confidence DECIMAL(5,4) NULL,

                first_seen_at DATETIME NULL,
                last_seen_at DATETIME NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (alliance_a_id, alliance_b_id, window_end_date, window_days),
                KEY ix_apb_a (alliance_a_id, window_end_date, affinity_score),
                KEY ix_apb_b (alliance_b_id, window_end_date, affinity_score),
                KEY ix_apb_hostility (window_end_date, hostility_score),
                KEY ix_apb_affinity (window_end_date, affinity_score),
                CONSTRAINT chk_apb_order CHECK (alliance_a_id < alliance_b_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS alliance_pair_behavior_rolling');
    }
};
