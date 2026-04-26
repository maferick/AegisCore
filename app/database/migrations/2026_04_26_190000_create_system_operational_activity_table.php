<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.3D — system operational activity (heatmap surface).
 *
 * Per (viewer_bloc, solar_system, day): rolled-up counts of every
 * operational signal type that fired in that system that day. Feeds
 * map overlays, threat-corridor analysis, route-danger scoring, and
 * response-time heatmaps.
 *
 * One row per system per UTC day. Computed daily by phase4-system-
 * activity. Idempotent UPSERT.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE system_operational_activity (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                solar_system_id BIGINT UNSIGNED NOT NULL,
                solar_system_name VARCHAR(120) NULL,
                region_id INT UNSIGNED NULL,
                activity_date DATE NOT NULL,
                hostile_report_count INT UNSIGNED NOT NULL DEFAULT 0,
                hostile_cluster_count INT UNSIGNED NOT NULL DEFAULT 0,
                escalation_count INT UNSIGNED NOT NULL DEFAULT 0,
                combat_spike_count INT UNSIGNED NOT NULL DEFAULT 0,
                fleet_formup_count INT UNSIGNED NOT NULL DEFAULT 0,
                disengagement_count INT UNSIGNED NOT NULL DEFAULT 0,
                self_destruct_wave_count INT UNSIGNED NOT NULL DEFAULT 0,
                incident_count INT UNSIGNED NOT NULL DEFAULT 0,
                incident_max_severity ENUM(
                    'noise','tactical','strategic','escalation','coalition_level'
                ) NULL,
                distinct_reporters INT UNSIGNED NOT NULL DEFAULT 0,
                reliability_weighted_reports DECIMAL(10,3) NOT NULL DEFAULT 0,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_bloc_system_date (viewer_bloc_id, solar_system_id, activity_date),
                INDEX idx_soa_region_date (region_id, activity_date),
                INDEX idx_soa_severity_date (incident_max_severity, activity_date),
                INDEX idx_soa_reports (reliability_weighted_reports DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS system_operational_activity');
    }
};
