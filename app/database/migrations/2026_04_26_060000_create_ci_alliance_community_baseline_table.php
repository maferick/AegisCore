<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — community-mismatch normalization.
 *
 * Per (alliance, viewer_bloc, window): the distribution of
 * community_hostile_pct across the alliance's active members from
 * the viewer bloc's perspective. Lets us render community_mismatch
 * relative to the pilot's own alliance — "you're top 10% within your
 * own alliance" beats "you're at 60% absolute".
 *
 * Rationale: every member of a hostile-tagged alliance has a high
 * community_hostile_pct because the metric is viewer-relative. The
 * absolute threshold ends up firing baseline-true on adversaries.
 * Normalizing against the alliance's own baseline isolates *outliers*
 * within each alliance, which is the actual review signal.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_alliance_community_baseline (
                alliance_id BIGINT UNSIGNED NOT NULL,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,
                sample_size INT UNSIGNED NOT NULL,
                median_pct DECIMAL(5,4) NULL,
                p90_pct DECIMAL(5,4) NULL,
                mean_pct DECIMAL(5,4) NULL,
                stdev_pct DECIMAL(5,4) NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (alliance_id, viewer_bloc_id, window_end_date),
                INDEX idx_ci_alli_bl_size (sample_size)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_alliance_community_baseline');
    }
};
