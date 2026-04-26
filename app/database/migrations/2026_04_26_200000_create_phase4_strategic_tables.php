<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.4 C/E/F — strategic operational analytics.
 *
 * operational_corridors      §4.4C — recurring hostile travel lanes
 *                                    inferred from cluster chains
 *                                    (system_a → system_b within
 *                                    N minutes with shared characters)
 * system_response_times      §4.4E — per-system per-day medians for
 *                                    intel→combat / formup→engage /
 *                                    engage→disengage timing
 * system_threat_surface      §4.4F — composite score per system
 *                                    rolled across hostile cluster
 *                                    frequency, escalation density,
 *                                    battle linkage, and corridor
 *                                    centrality
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE operational_corridors (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                from_system_id BIGINT UNSIGNED NOT NULL,
                to_system_id BIGINT UNSIGNED NOT NULL,
                from_system_name VARCHAR(120) NULL,
                to_system_name VARCHAR(120) NULL,
                from_region_id INT UNSIGNED NULL,
                to_region_id INT UNSIGNED NULL,
                transition_count INT UNSIGNED NOT NULL DEFAULT 0,
                distinct_characters INT UNSIGNED NOT NULL DEFAULT 0,
                avg_transition_seconds INT UNSIGNED NULL,
                first_seen_at DATETIME NULL,
                last_seen_at DATETIME NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'low',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_corridor (viewer_bloc_id, from_system_id, to_system_id),
                INDEX idx_corr_from (from_system_id, last_seen_at),
                INDEX idx_corr_to (to_system_id, last_seen_at),
                INDEX idx_corr_count (transition_count DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE system_response_times (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                solar_system_id BIGINT UNSIGNED NOT NULL,
                solar_system_name VARCHAR(120) NULL,
                region_id INT UNSIGNED NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                intel_to_combat_count INT UNSIGNED NOT NULL DEFAULT 0,
                intel_to_combat_median_seconds INT UNSIGNED NULL,
                formup_to_engage_count INT UNSIGNED NOT NULL DEFAULT 0,
                formup_to_engage_median_seconds INT UNSIGNED NULL,
                engage_to_disengage_count INT UNSIGNED NOT NULL DEFAULT 0,
                engage_to_disengage_median_seconds INT UNSIGNED NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_srt (viewer_bloc_id, solar_system_id, window_end_date),
                INDEX idx_srt_region (region_id, window_end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE system_threat_surface (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                solar_system_id BIGINT UNSIGNED NOT NULL,
                solar_system_name VARCHAR(120) NULL,
                region_id INT UNSIGNED NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                threat_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                hostile_cluster_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                escalation_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                battle_linkage_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                density_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                reliability_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                corridor_centrality_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                tier ENUM('safe','watch','contested','hot','strategic') NOT NULL DEFAULT 'safe',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_threat (viewer_bloc_id, solar_system_id, window_end_date),
                INDEX idx_threat_score (threat_score DESC),
                INDEX idx_threat_tier (tier, window_end_date),
                INDEX idx_threat_region (region_id, window_end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS system_threat_surface');
        DB::statement('DROP TABLE IF EXISTS system_response_times');
        DB::statement('DROP TABLE IF EXISTS operational_corridors');
    }
};
