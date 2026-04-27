<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V1 §11 — analyst audit log.
 *
 * Every mutation on an analyst-mutable surface writes one row.
 * Surfaces:
 *   - strategic_alerts          (analyst_status / notes / suppression)
 *   - verified_intelligence_items (pin / publish / delete)
 *   - intel_alert_suppression_rules (manual rules)
 *   - intel_export_artifacts    (generation + share-token use)
 *
 * Schema:
 *   actor_user_id    who did it
 *   actor_alliance_id / actor_bloc_id   resolved at write-time so a
 *                                       later user move doesn't change
 *                                       the historical record
 *   surface          enum naming the table
 *   surface_ref_id   row id in that table
 *   action           verb (set_status / save_notes / pin / publish /
 *                     delete / generate / view / etc.)
 *   prior_state_json snapshot of mutable fields BEFORE
 *   new_state_json   snapshot AFTER
 *   metadata_json    free-form context (request URL, IP, etc.)
 *
 * Append-only by convention; no audit tampering. retention covered by
 * the 4.9C retention sweep (will be added on the next pass).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE intel_audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_user_id BIGINT UNSIGNED NULL,
                actor_alliance_id BIGINT UNSIGNED NULL,
                actor_bloc_id BIGINT UNSIGNED NULL,
                surface ENUM(
                    'strategic_alert','verified_intelligence_item',
                    'suppression_rule','export_artifact','feedback_event'
                ) NOT NULL,
                surface_ref_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(60) NOT NULL,
                prior_state_json TEXT NULL,
                new_state_json TEXT NULL,
                metadata_json TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(220) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_ial_surface (surface, surface_ref_id, created_at),
                INDEX idx_ial_actor (actor_user_id, created_at),
                INDEX idx_ial_action (action, created_at),
                INDEX idx_ial_bloc (actor_bloc_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS intel_audit_log');
    }
};
