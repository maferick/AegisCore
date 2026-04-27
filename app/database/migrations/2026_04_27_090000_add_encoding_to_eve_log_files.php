<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add per-file text encoding so chunk 1..N can be decoded correctly.
 *
 * Original ingest path detected UTF-16 only via leading BOM bytes —
 * BOM appears once per file, so chunks beyond the first dropped
 * through as raw UTF-16LE/BE bytes. Result: ~24k chat lines from
 * legacy Windows EVE clients piled up in eve_log_parse_errors with
 * reason='no_timestamp_prefix' even after retry.
 *
 * We persist the detected encoding on the file row at chunk 0 and
 * force-convert subsequent chunks to UTF-8 before parsing.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE eve_log_files
              ADD COLUMN encoding ENUM('utf-8','utf-16le','utf-16be')
                  NOT NULL DEFAULT 'utf-8' AFTER session_started_at
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE eve_log_files DROP COLUMN encoding');
    }
};
