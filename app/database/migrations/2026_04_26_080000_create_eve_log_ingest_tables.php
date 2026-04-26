<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — EVE log ingest schema.
 *
 * Receives append-safe chunked log uploads from the Windows uploader
 * (.NET Worker Service, see windows-uploader/). The Laravel API
 * accepts chunks at /api/eve-log-ingest/chunk, validates SHA256 +
 * offset continuity, persists chunk receipts, and parses complete
 * lines into structured events.
 *
 * Tables:
 *   eve_log_upload_clients  per-installation auth identity
 *   eve_log_files           per (client, source_path_hash) state
 *   eve_log_chunks          append log of accepted chunks
 *   eve_log_events          parsed lines (chat / combat / notify / etc.)
 *
 * Token auth: api_token_hash is sha256(raw_token). Tokens are issued
 * out-of-band (artisan command) and stored only as hashes server-side.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_upload_clients (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                client_id VARCHAR(64) NOT NULL,
                display_name VARCHAR(120) NULL,
                api_token_hash CHAR(64) NOT NULL,
                last_seen_at DATETIME NULL,
                last_remote_ip VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_client_id (client_id),
                UNIQUE KEY uniq_token_hash (api_token_hash),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                client_id VARCHAR(64) NOT NULL,
                source_path_hash CHAR(64) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                log_type ENUM('gamelog','chatlog','fleet','local','intel','unknown') NOT NULL DEFAULT 'unknown',
                listener VARCHAR(120) NULL,
                channel_name VARCHAR(120) NULL,
                channel_id VARCHAR(64) NULL,
                session_started_at DATETIME NULL,
                size_received BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_client_path (client_id, source_path_hash),
                INDEX idx_user (user_id),
                INDEX idx_filename (filename),
                INDEX idx_channel (channel_name),
                INDEX idx_log_type (log_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_chunks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                eve_log_file_id BIGINT UNSIGNED NOT NULL,
                offset_start BIGINT UNSIGNED NOT NULL,
                offset_end BIGINT UNSIGNED NOT NULL,
                byte_length INT UNSIGNED NOT NULL,
                chunk_sha256 CHAR(64) NOT NULL,
                received_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_file_offset (eve_log_file_id, offset_start),
                INDEX idx_file_received (eve_log_file_id, received_at),
                CONSTRAINT fk_eve_log_chunks_file
                    FOREIGN KEY (eve_log_file_id) REFERENCES eve_log_files(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                eve_log_file_id BIGINT UNSIGNED NOT NULL,
                event_timestamp DATETIME NULL,
                event_type ENUM(
                    'chat_message','local_message','fleet_message','intel_report',
                    'combat_event','notify_event','session_event','unknown'
                ) NOT NULL DEFAULT 'unknown',
                actor_name VARCHAR(120) NULL,
                system_name VARCHAR(120) NULL,
                channel_name VARCHAR(120) NULL,
                raw_line TEXT NOT NULL,
                parsed_json TEXT NULL,
                line_offset BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_file (eve_log_file_id),
                INDEX idx_event_type (event_type, event_timestamp),
                INDEX idx_event_ts (event_timestamp),
                INDEX idx_actor (actor_name),
                CONSTRAINT fk_eve_log_events_file
                    FOREIGN KEY (eve_log_file_id) REFERENCES eve_log_files(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS eve_log_events');
        DB::statement('DROP TABLE IF EXISTS eve_log_chunks');
        DB::statement('DROP TABLE IF EXISTS eve_log_files');
        DB::statement('DROP TABLE IF EXISTS eve_log_upload_clients');
    }
};
