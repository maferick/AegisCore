<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9A + 4.9E — compute orchestration + platform observability.
 *
 * Tables:
 *
 *  compute_run_log          per-invocation traceability. Every
 *                            instrumented compute writes a row at
 *                            start (status=running) and updates
 *                            on finish/error.
 *
 *  compute_lane_metrics     rolling aggregates per lane: pending
 *                            jobs, running, throughput, p95, oldest
 *                            backlog age.
 *
 *  system_quality_events    detector alerts (incident explosion,
 *                            corridor explosion, parser drift, etc).
 *                            Severity: info / warning / elevated /
 *                            critical. ack/resolve workflow per row.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE compute_run_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lane ENUM(
                    'ingest','parser','graph','operational','doctrine',
                    'intelligence_generation','governance','maintenance'
                ) NOT NULL,
                pipeline VARCHAR(120) NOT NULL,
                viewer_bloc_id INT UNSIGNED NULL,
                compute_version VARCHAR(32) NOT NULL DEFAULT 'v1',
                status ENUM('running','succeeded','failed','aborted') NOT NULL DEFAULT 'running',
                compute_started_at DATETIME NOT NULL,
                compute_finished_at DATETIME NULL,
                compute_duration_ms INT UNSIGNED NULL,
                source_row_count BIGINT UNSIGNED NULL,
                generated_row_count BIGINT UNSIGNED NULL,
                error_message VARCHAR(500) NULL,
                args_json TEXT NULL,
                stats_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_crl_lane (lane, compute_started_at),
                INDEX idx_crl_pipeline (pipeline, compute_started_at),
                INDEX idx_crl_status (status, compute_started_at),
                INDEX idx_crl_bloc (viewer_bloc_id, compute_started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE compute_lane_metrics (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lane ENUM(
                    'ingest','parser','graph','operational','doctrine',
                    'intelligence_generation','governance','maintenance'
                ) NOT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                pending_jobs INT UNSIGNED NOT NULL DEFAULT 0,
                running_jobs INT UNSIGNED NOT NULL DEFAULT 0,
                succeeded_24h INT UNSIGNED NOT NULL DEFAULT 0,
                failed_24h INT UNSIGNED NOT NULL DEFAULT 0,
                avg_duration_ms INT UNSIGNED NULL,
                p95_duration_ms INT UNSIGNED NULL,
                oldest_pending_seconds INT UNSIGNED NULL,
                throughput_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0,
                lane_state ENUM('healthy','degraded','backlogged','starved','failed') NOT NULL DEFAULT 'healthy',
                evidence_json TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_clm_lane (lane),
                INDEX idx_clm_state (lane_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE system_quality_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NULL,
                detector ENUM(
                    'incident_explosion','corridor_explosion',
                    'parser_drift','unknown_event_spike',
                    'doctrine_mismatch_explosion','impossible_fleet_size',
                    'duplicate_narrative_loop','stale_compute_chain'
                ) NOT NULL,
                severity ENUM('info','warning','elevated','critical') NOT NULL DEFAULT 'warning',
                detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                window_start DATETIME NULL,
                window_end DATETIME NULL,
                title VARCHAR(220) NOT NULL,
                summary VARCHAR(600) NULL,
                metric_value DECIMAL(20,4) NULL,
                threshold_value DECIMAL(20,4) NULL,
                evidence_json TEXT NULL,
                acknowledged_at DATETIME NULL,
                acknowledged_by_user_id BIGINT UNSIGNED NULL,
                resolved_at DATETIME NULL,
                resolved_by_user_id BIGINT UNSIGNED NULL,
                resolution_notes VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_sqe (detector, viewer_bloc_id, window_start, window_end),
                INDEX idx_sqe_open (resolved_at, severity, detected_at),
                INDEX idx_sqe_detector (detector, detected_at),
                INDEX idx_sqe_severity (severity, detected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS system_quality_events');
        DB::statement('DROP TABLE IF EXISTS compute_lane_metrics');
        DB::statement('DROP TABLE IF EXISTS compute_run_log');
    }
};
