<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — operational event intelligence scaffold.
 *
 * Tables for analytics derived from eve_log_events once Phase 3 ingest
 * is producing data. Compute jobs are stubbed (see ADR-0009) — only
 * schema lands here, so the dossier service can render placeholder
 * fields and the calibration spec has stable column names to target.
 *
 * Tables:
 *   ci_fleet_participation_rolling   per-character fleet vs combat presence
 *   ci_intel_reliability_rolling     intel report quality + cadence
 *   ci_session_correlation_edges     pairwise temporal correlation
 *   ci_operational_timelines         reconstructed event sequences
 *
 * All keyed on (character_id, viewer_bloc_id, window_end_date) for
 * symmetry with the existing ci_character_*_rolling tables.
 */
return new class extends Migration {
    public function up(): void
    {
        // Fleet attendance vs killmail combat participation.
        DB::statement(<<<'SQL'
            CREATE TABLE ci_fleet_participation_rolling (
                character_id BIGINT UNSIGNED NOT NULL,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,
                fleet_sessions INT UNSIGNED NOT NULL DEFAULT 0,
                fleet_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                combat_killmails INT UNSIGNED NOT NULL DEFAULT 0,
                fleet_only_sessions INT UNSIGNED NOT NULL DEFAULT 0,
                fleet_lurker_score DECIMAL(5,4) NULL,
                no_engagement_streak_days INT UNSIGNED NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (character_id, viewer_bloc_id, window_end_date),
                INDEX idx_lurker (fleet_lurker_score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Intel report cadence + quality.
        DB::statement(<<<'SQL'
            CREATE TABLE ci_intel_reliability_rolling (
                character_id BIGINT UNSIGNED NOT NULL,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,
                report_count INT UNSIGNED NOT NULL DEFAULT 0,
                confirmed_count INT UNSIGNED NOT NULL DEFAULT 0,
                false_positive_count INT UNSIGNED NOT NULL DEFAULT 0,
                avg_delay_seconds INT UNSIGNED NULL,
                silence_before_loss_count INT UNSIGNED NOT NULL DEFAULT 0,
                reliability_score DECIMAL(5,4) NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (character_id, viewer_bloc_id, window_end_date),
                INDEX idx_intel_score (reliability_score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Pairwise activity correlation. Edges between (a, b) where
        // their activity windows overlap unusually often vs cohort.
        DB::statement(<<<'SQL'
            CREATE TABLE ci_session_correlation_edges (
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                character_a BIGINT UNSIGNED NOT NULL,
                character_b BIGINT UNSIGNED NOT NULL,
                shared_sessions INT UNSIGNED NOT NULL DEFAULT 0,
                avg_offset_seconds INT NULL,
                correlation_score DECIMAL(5,4) NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (viewer_bloc_id, window_end_date, character_a, character_b),
                INDEX idx_corr_score (correlation_score),
                INDEX idx_corr_a (character_a, viewer_bloc_id, window_end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Reconstructed operational sequences (form-up → engagement →
        // response). One row per detected event chain.
        DB::statement(<<<'SQL'
            CREATE TABLE ci_operational_timelines (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                event_kind ENUM(
                    'fleet_formup','engagement','response',
                    'self_destruct_wave','hostile_drop','escalation','unknown'
                ) NOT NULL DEFAULT 'unknown',
                anchor_system_id BIGINT UNSIGNED NULL,
                anchor_killmail_id BIGINT UNSIGNED NULL,
                participant_character_ids JSON NULL,
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_optl_window (viewer_bloc_id, started_at),
                INDEX idx_optl_kind (event_kind, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_operational_timelines');
        DB::statement('DROP TABLE IF EXISTS ci_session_correlation_edges');
        DB::statement('DROP TABLE IF EXISTS ci_intel_reliability_rolling');
        DB::statement('DROP TABLE IF EXISTS ci_fleet_participation_rolling');
    }
};
