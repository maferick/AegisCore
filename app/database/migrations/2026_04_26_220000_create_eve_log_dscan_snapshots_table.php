<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.4 — dscan.info snapshot registry.
 *
 * One row per unique dscan snapshot referenced from eve_log_events.
 * The actual fetch is deferred to a rate-limited artisan job (see
 * eve-log:fetch-dscan) so the ingest path never blocks on external
 * I/O. Treat dscan content as supporting evidence, not absolute
 * truth — that's why we keep raw_json access-gated and the table
 * tracks fetch_status separately.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_dscan_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                snapshot_id VARCHAR(64) NOT NULL,
                url VARCHAR(255) NOT NULL,
                fetch_status ENUM('pending','success','failed','blocked','expired') NOT NULL DEFAULT 'pending',
                ship_count INT UNSIGNED NULL,
                ship_types_json TEXT NULL,
                top_ship_summary VARCHAR(500) NULL,
                raw_json LONGTEXT NULL,
                http_status INT UNSIGNED NULL,
                error VARCHAR(500) NULL,
                fetch_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                mention_count INT UNSIGNED NOT NULL DEFAULT 1,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                last_fetched_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_snapshot (snapshot_id),
                INDEX idx_dscan_status (fetch_status, last_seen_at),
                INDEX idx_dscan_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS eve_log_dscan_snapshots');
    }
};
