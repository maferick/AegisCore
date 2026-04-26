<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.3A — operational hostile-contact clusters.
 *
 * Replaces one-row-per-intel_report (40K+) with operational
 * clusters: a 5-minute rolling window per primary system collapses
 * 40 micro-events into 1 row that says "between 18:32 and 18:37
 * three reporters named six hostile pilots in WBR5-R, also
 * mentioning JFR-RU and 4-EP12".
 *
 * Bridge between the raw log stream and the incident-fusion layer
 * (Phase 4.3B). Battle/theater linkage (Phase 4.3C) hangs off this.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE operational_hostile_clusters (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                primary_system_id BIGINT UNSIGNED NULL,
                primary_system_name VARCHAR(120) NULL,
                primary_region_id INT UNSIGNED NULL,
                adjacent_system_ids_json TEXT NULL,
                involved_character_ids_json TEXT NULL,
                involved_character_names_json TEXT NULL,
                reporter_count INT UNSIGNED NOT NULL DEFAULT 0,
                report_count INT UNSIGNED NOT NULL DEFAULT 0,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'low',
                quality ENUM('noisy','weak','normal','strong','strategic') NOT NULL DEFAULT 'normal',
                linked_battle_ids_json TEXT NULL,
                evidence_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_bloc_system_start (viewer_bloc_id, primary_system_id, start_at),
                INDEX idx_ohc_bloc_time (viewer_bloc_id, start_at),
                INDEX idx_ohc_primary_system (primary_system_id, start_at),
                INDEX idx_ohc_region (primary_region_id, start_at),
                INDEX idx_ohc_quality (quality, start_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS operational_hostile_clusters');
    }
};
