<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.8 — intel governance + trust + analyst controls.
 *
 * Schema additions:
 *
 *  strategic_alerts          analyst lifecycle (status, notes,
 *                             confidence override, suppression,
 *                             reviewer attribution).
 *
 *  daily_operational_digest  per-section confidence + evidence
 *                             support metric snapshots.
 *
 *  incident_narratives       traceability source-pointer columns
 *                             (incident, cluster, dscan, timeline
 *                             event id arrays).
 *
 * New tables:
 *
 *  intel_feedback_events           analyst feedback corpus for
 *                                   future calibration.
 *
 *  verified_intelligence_items     human-verified pins / notes /
 *                                   curated summaries.
 *
 *  system_trust_metrics            per-surface trust ratios
 *                                   (false positive, override
 *                                   rate, suppression rate, etc).
 *
 *  intel_alert_suppression_rules   declarative suppression
 *                                   policies.
 */
return new class extends Migration {
    public function up(): void
    {
        // 4.8A — strategic_alerts lifecycle.
        DB::statement(<<<'SQL'
            ALTER TABLE strategic_alerts
              ADD COLUMN analyst_status ENUM(
                  'new','acknowledged','validated','suppressed','false_positive','archived'
              ) NOT NULL DEFAULT 'new' AFTER severity,
              ADD COLUMN analyst_notes TEXT NULL AFTER analyst_status,
              ADD COLUMN analyst_confidence_override DECIMAL(5,4) NULL AFTER analyst_notes,
              ADD COLUMN false_positive TINYINT(1) NOT NULL DEFAULT 0 AFTER analyst_confidence_override,
              ADD COLUMN suppressed_until DATETIME NULL AFTER false_positive,
              ADD COLUMN suppression_reason VARCHAR(220) NULL AFTER suppressed_until,
              ADD COLUMN suppression_rule_id BIGINT UNSIGNED NULL AFTER suppression_reason,
              ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER suppression_rule_id,
              ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_user_id,
              ADD INDEX idx_sa_status (analyst_status, detected_at),
              ADD INDEX idx_sa_suppressed (suppressed_until)
        SQL);

        // 4.8B — digest trust surface.
        DB::statement(<<<'SQL'
            ALTER TABLE daily_operational_digest
              ADD COLUMN section_confidence_json TEXT NULL AFTER metric_summary_json,
              ADD COLUMN evidence_summary_json TEXT NULL AFTER section_confidence_json,
              ADD COLUMN source_reliability_json TEXT NULL AFTER evidence_summary_json
        SQL);

        // 4.8C — narrative traceability.
        DB::statement(<<<'SQL'
            ALTER TABLE incident_narratives
              ADD COLUMN source_incident_ids_json TEXT NULL AFTER key_facts_json,
              ADD COLUMN source_cluster_ids_json TEXT NULL AFTER source_incident_ids_json,
              ADD COLUMN source_dscan_snapshot_ids_json TEXT NULL AFTER source_cluster_ids_json,
              ADD COLUMN source_timeline_event_ids_json TEXT NULL AFTER source_dscan_snapshot_ids_json,
              ADD COLUMN source_battle_id BIGINT UNSIGNED NULL AFTER source_timeline_event_ids_json,
              ADD COLUMN narrative_confidence DECIMAL(5,4) NULL AFTER source_battle_id
        SQL);

        // 4.8D — feedback events.
        DB::statement(<<<'SQL'
            CREATE TABLE intel_feedback_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                surface ENUM(
                    'alert','digest','narrative','incident',
                    'corridor','alliance_profile','threat_surface'
                ) NOT NULL,
                surface_ref_id BIGINT UNSIGNED NOT NULL,
                surface_ref_kind VARCHAR(40) NULL,
                feedback_kind ENUM(
                    'useful','misleading','noisy','duplicate',
                    'strategic','incorrect_escalation',
                    'incorrect_doctrine','incorrect_linkage'
                ) NOT NULL,
                analyst_user_id BIGINT UNSIGNED NULL,
                analyst_alliance_id BIGINT UNSIGNED NULL,
                comment VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_ife_surface (surface, surface_ref_id, created_at),
                INDEX idx_ife_kind (feedback_kind, created_at),
                INDEX idx_ife_bloc (viewer_bloc_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // 4.8E — declarative suppression rules.
        DB::statement(<<<'SQL'
            CREATE TABLE intel_alert_suppression_rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                rule_kind ENUM(
                    'duplicate_collapse','corridor_spam',
                    'low_confidence_incident','stale_escalation_decay',
                    'manual_block'
                ) NOT NULL,
                target_alert_kind VARCHAR(40) NULL,
                primary_system_id BIGINT UNSIGNED NULL,
                primary_alliance_id BIGINT UNSIGNED NULL,
                related_corridor_id BIGINT UNSIGNED NULL,
                threshold_json TEXT NULL,
                active_until DATETIME NULL,
                created_by_user_id BIGINT UNSIGNED NULL,
                reason VARCHAR(220) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_iasr_active (active_until),
                INDEX idx_iasr_kind (rule_kind, viewer_bloc_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // 4.8F — verified intelligence items.
        DB::statement(<<<'SQL'
            CREATE TABLE verified_intelligence_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                item_kind ENUM(
                    'pinned_incident','curated_summary','strategic_event',
                    'analyst_note','narrative_override'
                ) NOT NULL,
                title VARCHAR(220) NOT NULL,
                body_md MEDIUMTEXT NULL,
                related_incident_id BIGINT UNSIGNED NULL,
                related_alert_id BIGINT UNSIGNED NULL,
                related_corridor_id BIGINT UNSIGNED NULL,
                related_alliance_id BIGINT UNSIGNED NULL,
                pinned TINYINT(1) NOT NULL DEFAULT 0,
                published TINYINT(1) NOT NULL DEFAULT 0,
                strategic_significance ENUM(
                    'low','medium','high','coalition_level'
                ) NOT NULL DEFAULT 'medium',
                tags_json TEXT NULL,
                created_by_user_id BIGINT UNSIGNED NULL,
                verified_by_user_id BIGINT UNSIGNED NULL,
                verified_at DATETIME NULL,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_vii_kind (item_kind, viewer_bloc_id),
                INDEX idx_vii_pinned (pinned, viewer_bloc_id),
                INDEX idx_vii_published (published, viewer_bloc_id),
                INDEX idx_vii_incident (related_incident_id),
                INDEX idx_vii_alert (related_alert_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // 4.8G — trust metrics.
        DB::statement(<<<'SQL'
            CREATE TABLE system_trust_metrics (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                surface ENUM(
                    'alert','digest','narrative','incident',
                    'corridor','alliance_profile','threat_surface'
                ) NOT NULL,
                window_end DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                total_items INT UNSIGNED NOT NULL DEFAULT 0,
                useful_count INT UNSIGNED NOT NULL DEFAULT 0,
                misleading_count INT UNSIGNED NOT NULL DEFAULT 0,
                noisy_count INT UNSIGNED NOT NULL DEFAULT 0,
                duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
                strategic_count INT UNSIGNED NOT NULL DEFAULT 0,
                false_positive_count INT UNSIGNED NOT NULL DEFAULT 0,
                analyst_override_count INT UNSIGNED NOT NULL DEFAULT 0,
                suppression_count INT UNSIGNED NOT NULL DEFAULT 0,
                narrative_correction_count INT UNSIGNED NOT NULL DEFAULT 0,
                useful_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                false_positive_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                override_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                suppression_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                trust_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                trust_tier ENUM(
                    'untrusted','low','adequate','strong','high'
                ) NOT NULL DEFAULT 'adequate',
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_stm (viewer_bloc_id, surface, window_end, window_days),
                INDEX idx_stm_tier (trust_tier),
                INDEX idx_stm_window (window_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS system_trust_metrics');
        DB::statement('DROP TABLE IF EXISTS verified_intelligence_items');
        DB::statement('DROP TABLE IF EXISTS intel_alert_suppression_rules');
        DB::statement('DROP TABLE IF EXISTS intel_feedback_events');

        DB::statement(<<<'SQL'
            ALTER TABLE incident_narratives
              DROP COLUMN narrative_confidence,
              DROP COLUMN source_battle_id,
              DROP COLUMN source_timeline_event_ids_json,
              DROP COLUMN source_dscan_snapshot_ids_json,
              DROP COLUMN source_cluster_ids_json,
              DROP COLUMN source_incident_ids_json
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE daily_operational_digest
              DROP COLUMN source_reliability_json,
              DROP COLUMN evidence_summary_json,
              DROP COLUMN section_confidence_json
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE strategic_alerts
              DROP INDEX idx_sa_suppressed,
              DROP INDEX idx_sa_status,
              DROP COLUMN reviewed_at,
              DROP COLUMN reviewed_by_user_id,
              DROP COLUMN suppression_rule_id,
              DROP COLUMN suppression_reason,
              DROP COLUMN suppressed_until,
              DROP COLUMN false_positive,
              DROP COLUMN analyst_confidence_override,
              DROP COLUMN analyst_notes,
              DROP COLUMN analyst_status
        SQL);
    }
};
