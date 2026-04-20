<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-intel watchlist — operator-flagged case file.
 *
 * One row per (user, character) pair. Operators hit "Watch" on a
 * dossier to save the pilot for follow-up; optional free-text note
 * captures the reason. Use cases:
 *   * multi-pilot investigation threads
 *   * cross-session persistence of pilots under review
 *   * CSV / PDF export for hand-off to leadership
 *
 * Separate from coalition_entity_labels (which is authoritative
 * ground-truth) — watchlist is per-operator, informal, editable.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_review_watchlist (
                user_id BIGINT UNSIGNED NOT NULL,
                character_id BIGINT UNSIGNED NOT NULL,
                note VARCHAR(500) NULL,
                added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, character_id),
                KEY ix_ciw_user (user_id, added_at),
                KEY ix_ciw_char (character_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_review_watchlist');
    }
};
