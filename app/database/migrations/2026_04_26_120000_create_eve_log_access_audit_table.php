<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 hardening — raw-log access audit.
 *
 * Records every cross-user access to eve_log_events.raw_line or
 * eve_log_events.parsed_json. Owner-self access is NOT audited (no
 * privacy concern viewing your own data); directors/admins viewing
 * someone else's logs IS audited every time.
 *
 * Used by the RawLogAccessPolicy service. Records:
 *   user_id            who viewed
 *   target_user_id     whose file they viewed
 *   access_kind        list / single / export
 *   eve_log_file_id    optional file scope
 *   eve_log_event_id   optional event scope
 *   query_terms        search filters that bounded the view
 *   accessed_at        when
 *   ip                 source ip
 *   row_count          how many rows were exposed in this view
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_access_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                target_user_id BIGINT UNSIGNED NULL,
                access_kind VARCHAR(40) NOT NULL,
                eve_log_file_id BIGINT UNSIGNED NULL,
                eve_log_event_id BIGINT UNSIGNED NULL,
                query_terms_json TEXT NULL,
                row_count INT UNSIGNED NULL,
                accessed_at DATETIME NOT NULL,
                ip VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                PRIMARY KEY (id),
                INDEX idx_audit_user (user_id, accessed_at),
                INDEX idx_audit_target (target_user_id, accessed_at),
                INDEX idx_audit_kind (access_kind, accessed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS eve_log_access_audit');
    }
};
