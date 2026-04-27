<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §17 — actor_kind on intel_audit_log so AI-generated artifacts
 * are distinguishable from operator actions in the audit trail.
 *
 * Adds:
 *   actor_kind ENUM('user','ai') NOT NULL DEFAULT 'user'
 *
 * Extends `surface` enum with 'ai_change_summary' for §17.1
 * "what changed?" findings. Future safe-AI surfaces add their
 * own value here as they ship.
 *
 * Existing rows default to 'user' which matches their semantics
 * (every existing row was an operator action).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE intel_audit_log
              ADD COLUMN actor_kind ENUM('user','ai') NOT NULL DEFAULT 'user' AFTER actor_bloc_id,
              MODIFY COLUMN surface ENUM(
                'strategic_alert',
                'verified_intelligence_item',
                'suppression_rule',
                'export_artifact',
                'feedback_event',
                'ai_change_summary'
              ) NOT NULL,
              ADD INDEX idx_actor_kind (actor_kind, created_at)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE intel_audit_log
              DROP INDEX idx_actor_kind,
              DROP COLUMN actor_kind,
              MODIFY COLUMN surface ENUM(
                'strategic_alert',
                'verified_intelligence_item',
                'suppression_rule',
                'export_artifact',
                'feedback_event'
              ) NOT NULL
        SQL);
    }
};
