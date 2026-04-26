<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.7 — analyst workflow + intelligence production.
 *
 * daily_operational_digest    one row per (viewer_bloc, digest_date,
 *                              window_kind). Aggregated brief sections
 *                              + sample picks for the analyst page.
 *
 * strategic_alerts            rare, operationally-meaningful events
 *                              surfaced from the existing tables. ack
 *                              / dismiss workflow per (bloc, alert).
 *
 * incident_narratives         one human-readable narrative per
 *                              (incident, generator_version).
 *
 * intel_export_artifacts      shareable export records (markdown /
 *                              JSON / report links) with retention
 *                              token.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE daily_operational_digest (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                digest_date DATE NOT NULL,
                window_kind ENUM('today','last_24h','last_7d') NOT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                top_incident_ids_json TEXT NULL,
                escalation_summary_json TEXT NULL,
                doctrine_evolution_json TEXT NULL,
                coalition_movement_json TEXT NULL,
                new_corridors_json TEXT NULL,
                unusual_compositions_json TEXT NULL,
                emerging_operators_json TEXT NULL,
                response_anomalies_json TEXT NULL,

                top_threat_systems_json TEXT NULL,
                metric_summary_json TEXT NULL,
                narrative_md TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_dod (viewer_bloc_id, digest_date, window_kind),
                INDEX idx_dod_date (digest_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE strategic_alerts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                alert_kind ENUM(
                    'sudden_doctrine_shift','capital_escalation',
                    'hostile_deployment_migration','escalation_into_staging',
                    'corridor_pressure_spike','operational_tempo_spike',
                    'large_strategic_cluster','unusual_force_composition'
                ) NOT NULL,
                severity ENUM('info','watch','elevated','urgent') NOT NULL DEFAULT 'watch',
                detected_at DATETIME NOT NULL,
                window_start DATETIME NULL,
                window_end DATETIME NULL,
                title VARCHAR(220) NOT NULL,
                summary VARCHAR(600) NULL,
                primary_system_id BIGINT UNSIGNED NULL,
                primary_system_name VARCHAR(120) NULL,
                primary_alliance_id BIGINT UNSIGNED NULL,
                primary_alliance_name VARCHAR(150) NULL,
                related_incident_id BIGINT UNSIGNED NULL,
                related_corridor_id BIGINT UNSIGNED NULL,
                related_doctrine_event_id BIGINT UNSIGNED NULL,
                acknowledged_at DATETIME NULL,
                acknowledged_by_user_id BIGINT UNSIGNED NULL,
                dismissed_at DATETIME NULL,
                dismissed_by_user_id BIGINT UNSIGNED NULL,
                evidence_json TEXT NULL,
                evaluator_version VARCHAR(16) NOT NULL DEFAULT 'v1',
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_sa (viewer_bloc_id, alert_kind, detected_at,
                    related_incident_id, related_corridor_id, related_doctrine_event_id, primary_alliance_id),
                INDEX idx_sa_open (viewer_bloc_id, dismissed_at, severity, detected_at),
                INDEX idx_sa_kind (alert_kind, detected_at),
                INDEX idx_sa_alliance (primary_alliance_id, detected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE incident_narratives (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                incident_id BIGINT UNSIGNED NOT NULL,
                generator_version VARCHAR(16) NOT NULL DEFAULT 'v1',
                narrative_md TEXT NOT NULL,
                key_facts_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_in (incident_id, generator_version),
                INDEX idx_in_bloc (viewer_bloc_id, incident_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE intel_export_artifacts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                artifact_kind ENUM(
                    'operational_report','strategic_summary',
                    'corridor_map','incident_timeline','doctrine_evolution_report'
                ) NOT NULL,
                format ENUM('markdown','json') NOT NULL,
                share_token CHAR(40) NOT NULL,
                title VARCHAR(220) NOT NULL,
                params_json TEXT NULL,
                body_md MEDIUMTEXT NULL,
                body_json MEDIUMTEXT NULL,
                created_by_user_id BIGINT UNSIGNED NULL,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_iea_token (share_token),
                INDEX idx_iea_kind (artifact_kind, created_at),
                INDEX idx_iea_bloc (viewer_bloc_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS intel_export_artifacts');
        DB::statement('DROP TABLE IF EXISTS incident_narratives');
        DB::statement('DROP TABLE IF EXISTS strategic_alerts');
        DB::statement('DROP TABLE IF EXISTS daily_operational_digest');
    }
};
