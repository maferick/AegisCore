<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.2A — entity resolution for parsed log events.
 *
 * Resolves tokens from intel / chat / fleet message bodies against
 * the canonical entity tables:
 *   ref_solar_systems   → system_id (high confidence on EVE system
 *                         codes — alphanumeric+hyphen format)
 *   esi_entity_names    → character / corporation / alliance ids
 *
 * Multiple resolutions per event are allowed (an intel line typically
 * names a system + a hostile + sometimes their corp). The unique key
 * (event, token, type) lets the writer upsert idempotently.
 *
 * Resolution feeds the Phase 4.3 intel-reliability scorer (replaces
 * the v1 heuristic capitalised-word extractor) and the Phase 4.2D
 * system-context attachment.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_entity_resolutions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                eve_log_event_id BIGINT UNSIGNED NOT NULL,
                token VARCHAR(120) NOT NULL,
                resolved_entity_type ENUM(
                    'character','corporation','alliance','system','region','unknown'
                ) NOT NULL DEFAULT 'unknown',
                resolved_entity_id BIGINT UNSIGNED NULL,
                resolved_entity_name VARCHAR(150) NULL,
                resolution_confidence ENUM('low','medium','high') NOT NULL DEFAULT 'low',
                resolution_method ENUM(
                    'exact_name','system_code','intel_syntax','partial_name','proximity'
                ) NOT NULL DEFAULT 'exact_name',
                token_offset INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_event_token_type (eve_log_event_id, token, resolved_entity_type),
                INDEX idx_event (eve_log_event_id),
                INDEX idx_resolved (resolved_entity_type, resolved_entity_id),
                INDEX idx_resolved_name (resolved_entity_name),
                CONSTRAINT fk_eve_log_entity_resolutions_event
                    FOREIGN KEY (eve_log_event_id) REFERENCES eve_log_events(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS eve_log_entity_resolutions');
    }
};
