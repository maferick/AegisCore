<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §17.1 — operational_change_summaries.
 *
 * AI-generated "what changed?" findings between two operational
 * windows. First safe-AI surface per ADR 0012/0013.
 *
 * Schema honours ADR 0013 binding UI/UX rule — every row carries
 * the six fields the renderer must surface: confidence band,
 * evidence list, source references, caveats, freshness state,
 * why-strengthened. No black-box scoring.
 *
 * Idempotency: unique key on (viewer_bloc_id, window_type,
 * summary_type). Each (bloc, window, summary type) tuple is a
 * single "latest snapshot" row — re-runs UPSERT into it. History
 * for a finding type lives in `intel_audit_log` (actor_kind='ai')
 * not in this table. Operator sees the current picture by
 * default, which is what §17.1 is for.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE operational_change_summaries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_type ENUM('1h','6h','24h','7d') NOT NULL,
                current_window_start DATETIME NOT NULL,
                current_window_end DATETIME NOT NULL,
                comparison_window_start DATETIME NOT NULL,
                comparison_window_end DATETIME NOT NULL,
                summary_type VARCHAR(64) NOT NULL,
                severity ENUM('info','warning','elevated','critical') NOT NULL DEFAULT 'info',
                confidence ENUM('low','medium','high','confirmed') NOT NULL DEFAULT 'low',
                title VARCHAR(240) NOT NULL,
                summary TEXT NOT NULL,
                evidence_json LONGTEXT NOT NULL,
                source_refs_json LONGTEXT NOT NULL,
                source_refs_hash CHAR(64) NOT NULL,
                caveats_json LONGTEXT NULL,
                why_strengthened_json LONGTEXT NULL,
                freshness_state ENUM('fresh','aging','stale','expired') NOT NULL DEFAULT 'fresh',
                source_window_start DATETIME NULL,
                source_window_end DATETIME NULL,
                ai_model VARCHAR(120) NULL,
                ai_prompt_hash CHAR(64) NULL,
                generated_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_summary (
                    viewer_bloc_id, window_type, summary_type
                ),
                INDEX idx_bloc_window (viewer_bloc_id, window_type, generated_at),
                INDEX idx_severity (severity, generated_at),
                INDEX idx_confidence (confidence),
                INDEX idx_fresh (freshness_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS operational_change_summaries');
    }
};
