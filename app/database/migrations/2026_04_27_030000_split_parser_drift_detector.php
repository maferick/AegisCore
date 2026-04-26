<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9B — split parser_drift detector.
 *
 * Original `parser_drift` enum value conflated current drift
 * (open errors) with historical backlog (already-retried errors).
 * Split into:
 *
 *   current_parser_drift       only eve_log_parse_errors.status='open'
 *   historical_parser_backlog  retried / dismissed / reparsed_ok rows
 *
 * Migration approach: extend the enum with the two new values,
 * leave the legacy `parser_drift` value present so old rows
 * remain readable, then re-classify any open `parser_drift`
 * events as `historical_parser_backlog` (severity=info) since
 * the original threshold logic was wrong.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE system_quality_events
              MODIFY COLUMN detector ENUM(
                'incident_explosion','corridor_explosion',
                'parser_drift','current_parser_drift','historical_parser_backlog',
                'unknown_event_spike',
                'doctrine_mismatch_explosion','impossible_fleet_size',
                'duplicate_narrative_loop','stale_compute_chain'
              ) NOT NULL
        SQL);

        // Reclassify the legacy parser_drift events as historical
        // backlog with info severity. Lets the dashboard collapse
        // them without losing audit trail.
        DB::statement(<<<'SQL'
            UPDATE system_quality_events
               SET detector = 'historical_parser_backlog',
                   severity = 'info',
                   summary = CONCAT('[reclassified from parser_drift] ', COALESCE(summary, ''))
             WHERE detector = 'parser_drift'
               AND resolved_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE system_quality_events
               SET detector = 'parser_drift'
             WHERE detector IN ('current_parser_drift','historical_parser_backlog')
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE system_quality_events
              MODIFY COLUMN detector ENUM(
                'incident_explosion','corridor_explosion',
                'parser_drift','unknown_event_spike',
                'doctrine_mismatch_explosion','impossible_fleet_size',
                'duplicate_narrative_loop','stale_compute_chain'
              ) NOT NULL
        SQL);
    }
};
