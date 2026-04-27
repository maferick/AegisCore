<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §18 — counter_intel_hypotheses.
 *
 * Fusion table for the Counter-Intel Command Surface. One row per
 * (viewer_bloc_id, hypothesis_type, primary_character_id) — the
 * fusion compute UPSERTs into it on every run, tracking
 * first_seen_at + last_strengthened_at across runs so longitudinal
 * persistence multipliers work without an audit detour.
 *
 * Schema honours ADR 0013 binding fields — every row carries
 * confidence band, evidence summary, source signal refs, caveats,
 * freshness state, and a why_strengthened payload. No black-box
 * scoring.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE counter_intel_hypotheses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                hypothesis_type ENUM(
                    'single_pilot_high_priority',
                    'correlated_cluster',
                    'recurring_overlap',
                    'temporal_correlation'
                ) NOT NULL DEFAULT 'single_pilot_high_priority',
                primary_character_id BIGINT UNSIGNED NOT NULL,
                related_character_ids_json TEXT NULL,
                confidence ENUM('low','medium','high','confirmed') NOT NULL DEFAULT 'low',
                severity ENUM('info','watch','elevated','critical') NOT NULL DEFAULT 'info',
                suspicion_score DECIMAL(8,4) NOT NULL DEFAULT 0,
                evidence_count INT UNSIGNED NOT NULL DEFAULT 0,
                corroboration_count INT UNSIGNED NOT NULL DEFAULT 0,
                first_seen_at DATETIME NOT NULL,
                last_strengthened_at DATETIME NOT NULL,
                last_recomputed_at DATETIME NOT NULL,
                freshness_state ENUM('fresh','aging','stale','expired') NOT NULL DEFAULT 'fresh',
                status ENUM('new','watch','escalated','archived') NOT NULL DEFAULT 'new',
                hypothesis_summary VARCHAR(500) NOT NULL,
                evidence_summary_json LONGTEXT NOT NULL,
                source_signal_refs_json LONGTEXT NOT NULL,
                caveats_json LONGTEXT NULL,
                why_strengthened_json LONGTEXT NULL,
                ai_model VARCHAR(120) NULL,
                ai_prompt_hash CHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_hypothesis (
                    viewer_bloc_id, hypothesis_type, primary_character_id
                ),
                INDEX idx_bloc_score (viewer_bloc_id, suspicion_score),
                INDEX idx_bloc_band (viewer_bloc_id, confidence, severity),
                INDEX idx_bloc_status (viewer_bloc_id, status, last_strengthened_at),
                INDEX idx_freshness (freshness_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Extend intel_audit_log surface enum so AI-generated
        // hypothesis writes are auditable alongside ai_change_summary.
        DB::statement(<<<'SQL'
            ALTER TABLE intel_audit_log
              MODIFY COLUMN surface ENUM(
                'strategic_alert',
                'verified_intelligence_item',
                'suppression_rule',
                'export_artifact',
                'feedback_event',
                'ai_change_summary',
                'ai_hypothesis'
              ) NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS counter_intel_hypotheses');
        DB::statement(<<<'SQL'
            ALTER TABLE intel_audit_log
              MODIFY COLUMN surface ENUM(
                'strategic_alert',
                'verified_intelligence_item',
                'suppression_rule',
                'export_artifact',
                'feedback_event',
                'ai_change_summary'
              ) NOT NULL
        SQL);
    }
};
