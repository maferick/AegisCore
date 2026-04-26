<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9D — retry / back-off / circuit breaker.
 *
 * Schema additions:
 *
 *   compute_run_log gets:
 *     retry_count          number of retries before final status
 *     retry_reason         classifier of the last retry trigger
 *     circuit_state        snapshot of circuit at run end
 *
 *   compute_circuit_state — per (lane, pipeline) circuit breaker.
 *     state ∈ closed / open / half_open
 *     opened_at + cooldown_until track open state
 *     consecutive_failures + window-counter feed the open transition.
 *
 *   system_quality_events.detector enum gains 'circuit_open' so the
 *   guard can persist findings.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE compute_run_log
              ADD COLUMN retry_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
              ADD COLUMN retry_reason ENUM(
                'transient','contention','rate_limit',
                'permanent','malformed_input','none'
              ) NOT NULL DEFAULT 'none' AFTER retry_count,
              ADD COLUMN circuit_state ENUM(
                'closed','open','half_open'
              ) NOT NULL DEFAULT 'closed' AFTER retry_reason,
              ADD INDEX idx_crl_circuit (circuit_state, lane)
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE compute_circuit_state (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                lane ENUM(
                    'ingest','parser','graph','operational','doctrine',
                    'intelligence_generation','governance','maintenance'
                ) NOT NULL,
                pipeline VARCHAR(120) NOT NULL,
                state ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed',
                consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
                window_failures INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NULL,
                opened_at DATETIME NULL,
                cooldown_until DATETIME NULL,
                last_failure_at DATETIME NULL,
                last_failure_reason ENUM(
                    'transient','contention','rate_limit',
                    'permanent','malformed_input','none'
                ) NOT NULL DEFAULT 'none',
                last_success_at DATETIME NULL,
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_ccs (lane, pipeline),
                INDEX idx_ccs_state (state, cooldown_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE system_quality_events
              MODIFY COLUMN detector ENUM(
                'incident_explosion','corridor_explosion',
                'parser_drift','current_parser_drift','historical_parser_backlog',
                'unknown_event_spike',
                'doctrine_mismatch_explosion','impossible_fleet_size',
                'duplicate_narrative_loop','stale_compute_chain',
                'neo4j_thread_pressure','circuit_open'
              ) NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE system_quality_events
              MODIFY COLUMN detector ENUM(
                'incident_explosion','corridor_explosion',
                'parser_drift','current_parser_drift','historical_parser_backlog',
                'unknown_event_spike',
                'doctrine_mismatch_explosion','impossible_fleet_size',
                'duplicate_narrative_loop','stale_compute_chain',
                'neo4j_thread_pressure'
              ) NOT NULL
        SQL);
        DB::statement('DROP TABLE IF EXISTS compute_circuit_state');
        DB::statement(<<<'SQL'
            ALTER TABLE compute_run_log
              DROP INDEX idx_crl_circuit,
              DROP COLUMN circuit_state,
              DROP COLUMN retry_reason,
              DROP COLUMN retry_count
        SQL);
    }
};
