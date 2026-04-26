<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — log-derived operational analytics schema.
 *
 * Drops the unused ci_*_rolling phase 4 placeholder tables (created
 * empty by the earlier scaffold migration; no readers, no data) and
 * creates the four log-derived tables the new spec calls for. Names
 * match the spec wording for symmetry with the compute modules:
 *
 *   operational_timeline_events     §4.1 — fleet formup, hostile
 *                                          report, escalation, combat
 *                                          spike, etc.
 *   fleet_presence_windows          §4.2 — per-character per-fleet
 *                                          presence vs combat
 *   intel_reliability_profiles      §4.3 — report rate / confirmation
 *                                          / contradictions / latency
 *   session_correlation_edges       §4.4 — pairwise temporal overlap
 *
 * Every row carries confidence + evidence_json so consumers can audit
 * the call without re-running compute.
 *
 * Privacy: derived counts + structured event summaries only. Raw chat
 * lines are NOT denormalised into these tables — readers join back to
 * eve_log_events when they have ABAC access.
 */
return new class extends Migration {
    public function up(): void
    {
        // Drop unused scaffold tables. Verified empty before drop.
        DB::statement('DROP TABLE IF EXISTS ci_operational_timelines');
        DB::statement('DROP TABLE IF EXISTS ci_session_correlation_edges');
        DB::statement('DROP TABLE IF EXISTS ci_intel_reliability_rolling');
        DB::statement('DROP TABLE IF EXISTS ci_fleet_participation_rolling');

        // §4.1 — operational timeline events.
        DB::statement(<<<'SQL'
            CREATE TABLE operational_timeline_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                timeline_type ENUM(
                    'fleet_formup','hostile_report','escalation','combat_spike',
                    'self_destruct_wave','extraction','disengagement',
                    'crash_symptom','intel_gap','unknown'
                ) NOT NULL,
                event_timestamp DATETIME NOT NULL,
                event_window_start DATETIME NULL,
                event_window_end DATETIME NULL,
                source_character_id BIGINT UNSIGNED NULL,
                source_listener VARCHAR(120) NULL,
                solar_system_name VARCHAR(120) NULL,
                solar_system_id BIGINT UNSIGNED NULL,
                related_battle_id BIGINT UNSIGNED NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'low',
                event_summary VARCHAR(500) NOT NULL,
                evidence_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_dedup (viewer_bloc_id, timeline_type, event_timestamp, source_listener),
                INDEX idx_optl_bloc_time (viewer_bloc_id, event_timestamp),
                INDEX idx_optl_type_time (timeline_type, event_timestamp),
                INDEX idx_optl_system (solar_system_name, event_timestamp),
                INDEX idx_optl_listener (source_listener, event_timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // §4.2 — fleet presence windows.
        DB::statement(<<<'SQL'
            CREATE TABLE fleet_presence_windows (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                character_name VARCHAR(120) NOT NULL,
                listener_name VARCHAR(120) NULL,
                fleet_channel VARCHAR(120) NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                duration_minutes INT UNSIGNED NOT NULL,
                participation_score DECIMAL(5,4) NULL,
                combat_events INT UNSIGNED NOT NULL DEFAULT 0,
                killmail_count INT UNSIGNED NOT NULL DEFAULT 0,
                spoke_in_fleet TINYINT(1) NOT NULL DEFAULT 0,
                spoken_messages INT UNSIGNED NOT NULL DEFAULT 0,
                derived_role ENUM(
                    'fleet_lurker','passive_observer','active_combatant',
                    'logistics_presence','scout_presence','unknown'
                ) NOT NULL DEFAULT 'unknown',
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'low',
                evidence_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_window (character_name, fleet_channel, start_at),
                INDEX idx_fpw_char_time (character_name, start_at),
                INDEX idx_fpw_role (derived_role, start_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // §4.3 — intel reliability profiles.
        DB::statement(<<<'SQL'
            CREATE TABLE intel_reliability_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                character_name VARCHAR(120) NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                reports_submitted INT UNSIGNED NOT NULL DEFAULT 0,
                confirmations INT UNSIGNED NOT NULL DEFAULT 0,
                contradictions INT UNSIGNED NOT NULL DEFAULT 0,
                false_alarm_rate DECIMAL(5,4) NULL,
                avg_report_latency_seconds INT UNSIGNED NULL,
                silence_before_hostiles INT UNSIGNED NOT NULL DEFAULT 0,
                repeated_hostile_overlap INT UNSIGNED NOT NULL DEFAULT 0,
                reliability_score DECIMAL(5,4) NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'insufficient',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_char_window (character_name, viewer_bloc_id, window_end_date),
                INDEX idx_intel_score (reliability_score),
                INDEX idx_intel_char (character_name, window_end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // §4.4 — session correlation edges. Keyed by character names
        // (the log gives us names, not character_ids — resolution to
        // character_id happens later at render time when stable).
        DB::statement(<<<'SQL'
            CREATE TABLE session_correlation_edges (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                character_a VARCHAR(120) NOT NULL,
                character_b VARCHAR(120) NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                shared_overlap_minutes INT UNSIGNED NOT NULL,
                repeated_overlap_count INT UNSIGNED NOT NULL,
                avg_offset_seconds INT NULL,
                correlation_score DECIMAL(5,4) NULL,
                sample_size_a INT UNSIGNED NOT NULL,
                sample_size_b INT UNSIGNED NOT NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'insufficient',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_pair_window (character_a, character_b, window_end_date),
                INDEX idx_sce_score (correlation_score),
                INDEX idx_sce_a (character_a, window_end_date),
                INDEX idx_sce_b (character_b, window_end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS session_correlation_edges');
        DB::statement('DROP TABLE IF EXISTS intel_reliability_profiles');
        DB::statement('DROP TABLE IF EXISTS fleet_presence_windows');
        DB::statement('DROP TABLE IF EXISTS operational_timeline_events');

        // Recreate the original phase 4 scaffold tables for full reversibility.
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
                PRIMARY KEY (character_id, viewer_bloc_id, window_end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }
};
