<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.3B + 4.3E — operational incident fusion.
 *
 * Fuses:
 *   - operational_hostile_clusters (Phase 4.3A)
 *   - operational_timeline_events  (Phase 4.1 + 4.2B)
 *
 * Into single incident rows that describe a coherent operational
 * event ("fleet form-up + hostile contact + engagement + drop in
 * combat rate" → one incident, not five timeline rows).
 *
 * 4.3E severity tiers (`severity` column):
 *   noise            single-signal, weak quality
 *   tactical         2 signals, normal quality (small skirmish)
 *   strategic        3+ signals, strong quality (fleet vs fleet)
 *   escalation       full sequence: hostile_cluster → combat → disengagement
 *   coalition_level  ≥ 10 distinct reporters AND multi-system spread
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE operational_incidents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                incident_type ENUM(
                    'fleet_op','engagement','hostile_contact','combat',
                    'disengagement','telemetry_gap','mixed'
                ) NOT NULL DEFAULT 'mixed',
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                primary_system_id BIGINT UNSIGNED NULL,
                primary_system_name VARCHAR(120) NULL,
                primary_region_id INT UNSIGNED NULL,
                battle_id BIGINT UNSIGNED NULL,
                theater_id BIGINT UNSIGNED NULL,
                severity ENUM(
                    'noise','tactical','strategic','escalation','coalition_level'
                ) NOT NULL DEFAULT 'noise',
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'low',
                participant_estimate INT UNSIGNED NULL,
                signal_types_json TEXT NOT NULL,
                hostile_cluster_ids_json TEXT NULL,
                timeline_event_ids_json TEXT NULL,
                timeline_summary VARCHAR(500) NULL,
                evidence_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_bloc_system_start (viewer_bloc_id, primary_system_id, start_at),
                INDEX idx_oi_bloc_time (viewer_bloc_id, start_at),
                INDEX idx_oi_battle (battle_id),
                INDEX idx_oi_severity (severity, start_at),
                INDEX idx_oi_type (incident_type, start_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS operational_incidents');
    }
};
