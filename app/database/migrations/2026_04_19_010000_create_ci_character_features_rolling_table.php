<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel Dossier — MVP Commit 1.
 *
 * Per-character feature vector, windowed (default 90d), refreshed daily.
 * Downstream layers consume this as the baseline for similarity (Neo4j
 * gds.knn), anomaly scoring, and the operator-facing dossier/dashboard.
 *
 * Design principles (locked from the planning discussion):
 *   - Character-first. No bloc-level comparator — bloc is context only.
 *   - Viewer-relative hostility is resolved at render time, NOT baked
 *     into these features. Features are tenant-agnostic.
 *   - Cold-start pilots (< CI_MIN_BATTLES_90D) keep a row but get
 *     has_sufficient_history = 0 so the dashboard can badge them
 *     "insufficient history" rather than silently drop them.
 *   - Every scalar here is human-explainable. No PCA projections or
 *     opaque embeddings. Reviewer should be able to read the row and
 *     understand what each number means.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_character_features_rolling (
                character_id BIGINT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,

                -- Gate flag for "has enough data to score". Triage UI
                -- groups these separately from real outlier rows.
                has_sufficient_history TINYINT(1) NOT NULL DEFAULT 0,

                -- Activity footprint.
                battles INT UNSIGNED NOT NULL DEFAULT 0,
                active_days INT UNSIGNED NOT NULL DEFAULT 0,
                killmails_attacker INT UNSIGNED NOT NULL DEFAULT 0,
                killmails_victim INT UNSIGNED NOT NULL DEFAULT 0,
                avg_gang_size DECIMAL(10,2) NOT NULL DEFAULT 0,
                solo_ratio DECIMAL(5,4) NOT NULL DEFAULT 0,

                -- Role distribution: fractions of role-tagged
                -- killmails in window, summing to ≤1. 0.0 when pilot
                -- had no role-tagged kills.
                role_fc_pct DECIMAL(5,4) NOT NULL DEFAULT 0,
                role_logi_pct DECIMAL(5,4) NOT NULL DEFAULT 0,
                role_bomber_pct DECIMAL(5,4) NOT NULL DEFAULT 0,
                role_command_pct DECIMAL(5,4) NOT NULL DEFAULT 0,
                role_tackle_pct DECIMAL(5,4) NOT NULL DEFAULT 0,
                role_dps_pct DECIMAL(5,4) NOT NULL DEFAULT 0,
                dominant_role VARCHAR(16) NULL,

                -- Hour-of-day activity histogram (24 fractions, JSON
                -- array). Gives the similarity cohort a timezone-aware
                -- dimension without schema explosion.
                hour_histogram JSON NOT NULL,

                -- Social / co-occurrence graph inputs.
                distinct_cofliers INT UNSIGNED NOT NULL DEFAULT 0,
                cooccurrence_density DECIMAL(10,4) NOT NULL DEFAULT 0,
                same_side_ratio DECIMAL(5,4) NOT NULL DEFAULT 0,

                -- Corp / alliance churn over the window.
                distinct_corps_in_window INT UNSIGNED NOT NULL DEFAULT 0,
                distinct_alliances_in_window INT UNSIGNED NOT NULL DEFAULT 0,
                affiliation_churn_rate DECIMAL(5,4) NOT NULL DEFAULT 0,

                -- Full historical affiliation span (not window-limited).
                -- Used by hostility-anomaly scoring, which needs the
                -- full history to catch long-past red flags.
                distinct_corps_all_time INT UNSIGNED NOT NULL DEFAULT 0,
                distinct_alliances_all_time INT UNSIGNED NOT NULL DEFAULT 0,

                -- Behaviour scalars from battle_character_role_features
                -- when available; 0 when pilot never had features
                -- computed.
                avg_damage_share DECIMAL(5,4) NOT NULL DEFAULT 0,

                -- Recency.
                days_since_last_activity INT UNSIGNED NOT NULL DEFAULT 9999,

                -- Pedigree / pipeline identity.
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (character_id, window_end_date, window_days),
                INDEX idx_cfcr_window (window_end_date, window_days),
                INDEX idx_cfcr_sufficient (has_sufficient_history, window_end_date),
                INDEX idx_cfcr_dominant (dominant_role, window_end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_character_features_rolling');
    }
};
