<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 follow-up — Neo4j thread-pressure detector.
 *
 * Adds `neo4j_thread_pressure` to system_quality_events.detector
 * enum so the new guard can persist findings.
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
                'duplicate_narrative_loop','stale_compute_chain',
                'neo4j_thread_pressure'
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
                'duplicate_narrative_loop','stale_compute_chain'
              ) NOT NULL
        SQL);
    }
};
