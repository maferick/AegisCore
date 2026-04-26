<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the entity-resolver schema for authoritative EVE rich-text
 * link sources:
 *   eve_log_entity_resolutions.source            showinfo_link |
 *                                                 text_match | dscan_url
 *   eve_log_entity_resolutions.showinfo_type_id  raw EVE typeID
 *
 * showinfo links carry the canonical (type_id, entity_id) pair —
 * resolution is exact, no fuzzy matching needed. Confidence on those
 * rows is always 'high'. Text-matched resolutions stay at low/medium.
 *
 * Plus parser-side extension on eve_log_events:
 *   external_dscan_url   first dscan.info URL found in the message
 *   reported_count       integer extracted from "+N" markers in
 *                         intel reports (count of named hostiles)
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE eve_log_entity_resolutions
              ADD COLUMN source ENUM('showinfo_link','text_match','dscan_url')
                NOT NULL DEFAULT 'text_match' AFTER resolution_method,
              ADD COLUMN showinfo_type_id INT UNSIGNED NULL AFTER source,
              ADD INDEX idx_eler_source (source, resolved_entity_type)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE eve_log_events
              ADD COLUMN external_dscan_url VARCHAR(255) NULL AFTER channel_name,
              ADD COLUMN reported_count INT UNSIGNED NULL AFTER external_dscan_url,
              ADD INDEX idx_eve_log_events_dscan (external_dscan_url)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE eve_log_events
              DROP INDEX idx_eve_log_events_dscan,
              DROP COLUMN external_dscan_url,
              DROP COLUMN reported_count
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE eve_log_entity_resolutions
              DROP INDEX idx_eler_source,
              DROP COLUMN source,
              DROP COLUMN showinfo_type_id
        SQL);
    }
};
