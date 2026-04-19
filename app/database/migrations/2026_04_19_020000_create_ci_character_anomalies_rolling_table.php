<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel Dossier — Commit 4 schema.
 *
 * Per-character anomaly scores, viewer-relative (keyed by viewer_bloc_id).
 * Summed into a single review_priority_score per (character, viewer_bloc,
 * window_end_date); Laravel reads this for the dossier + outlier
 * dashboard.
 *
 * Every score is a percentile in [0,1] vs the character's similarity
 * cohort (from Neo4j CI_SIMILAR_TO edges). A row of nulls means
 * "insufficient data"; the dashboard badges the character accordingly
 * rather than treating null as innocent.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_character_anomalies_rolling (
                character_id BIGINT UNSIGNED NOT NULL,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,

                cohort_size INT UNSIGNED NOT NULL DEFAULT 0,
                cohort_clean_pct DECIMAL(5,4) NULL,
                cohort_confidence ENUM('low','medium','high','insufficient') NOT NULL DEFAULT 'insufficient',

                -- Activity decile 1..10 within the cohort's battles distribution.
                activity_decile TINYINT UNSIGNED NULL,

                -- Percentiles (0..1) of the character's metric vs cohort.
                -- NULL when the cohort is too small to resolve meaningfully.
                affiliation_anomaly_pct DECIMAL(5,4) NULL,
                affiliation_churn_pct DECIMAL(5,4) NULL,
                hostile_overlap_pct DECIMAL(5,4) NULL,
                bridge_anomaly_pct DECIMAL(5,4) NULL,

                -- Raw counters for UI tooltips / explanations.
                hostile_alliance_count_history INT UNSIGNED NOT NULL DEFAULT 0,
                hostile_cooccurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
                recent_hostile_join TINYINT(1) NOT NULL DEFAULT 0,
                pagerank DECIMAL(12,6) NULL,
                betweenness DECIMAL(14,4) NULL,

                -- Final compact verdict.
                review_priority_score DECIMAL(6,4) NULL,
                review_priority_band ENUM(
                    'insufficient_history',
                    'cohort_unavailable',
                    'below_threshold',
                    'elevated',
                    'high',
                    'critical'
                ) NOT NULL DEFAULT 'below_threshold',

                -- Deltas for UI "30d change" column.
                review_priority_score_30d_ago DECIMAL(6,4) NULL,

                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (character_id, viewer_bloc_id, window_end_date, window_days),
                INDEX idx_cian_band (viewer_bloc_id, review_priority_band, window_end_date),
                INDEX idx_cian_score (viewer_bloc_id, window_end_date, review_priority_score),
                INDEX idx_cian_window (window_end_date, window_days)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_character_anomalies_rolling');
    }
};
