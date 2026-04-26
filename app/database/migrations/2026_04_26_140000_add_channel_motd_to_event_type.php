<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.1 cleanup — add `channel_motd` to eve_log_events.event_type.
 *
 * The EVE System "Channel MOTD: …" lines arrive frequently in chat
 * logs and were previously bucketed as session_event. The dossier
 * timeline shouldn't surface MOTD spam, and the operational analytics
 * shouldn't count MOTD as combat / fleet activity. New explicit value
 * lets readers filter cleanly.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE eve_log_events
              MODIFY COLUMN event_type ENUM(
                'chat_message','local_message','fleet_message','intel_report',
                'combat_event','notify_event','session_event','channel_motd','unknown'
              ) NOT NULL DEFAULT 'unknown'
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE eve_log_events SET event_type = 'session_event' WHERE event_type = 'channel_motd'
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE eve_log_events
              MODIFY COLUMN event_type ENUM(
                'chat_message','local_message','fleet_message','intel_report',
                'combat_event','notify_event','session_event','unknown'
              ) NOT NULL DEFAULT 'unknown'
        SQL);
    }
};
