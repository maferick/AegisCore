<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 hardening — parser failure queue.
 *
 * One row per line the parser could not classify (event_type='unknown')
 * or where the chunk hit a structural failure (header parse error,
 * sha256 mismatch already rejected at the controller, etc.).
 *
 * Operators review the queue from the portal, optionally retry/reparse
 * a line after fixing parser logic, or dismiss it as expected garbage.
 *
 * The events table itself keeps the row too (event_type='unknown')
 * so the dossier audit isn't lossy — this table is just the review
 * surface.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_parse_errors (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                eve_log_file_id BIGINT UNSIGNED NOT NULL,
                eve_log_event_id BIGINT UNSIGNED NULL,
                raw_line TEXT NOT NULL,
                line_offset BIGINT UNSIGNED NULL,
                reason VARCHAR(80) NOT NULL,
                detail TEXT NULL,
                status ENUM('open','retried','dismissed','reparsed_ok') NOT NULL DEFAULT 'open',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_retried_at DATETIME NULL,
                last_retried_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_file (eve_log_file_id),
                INDEX idx_status (status, created_at),
                INDEX idx_reason (reason),
                CONSTRAINT fk_eve_log_parse_errors_file
                    FOREIGN KEY (eve_log_file_id) REFERENCES eve_log_files(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS eve_log_parse_errors');
    }
};
