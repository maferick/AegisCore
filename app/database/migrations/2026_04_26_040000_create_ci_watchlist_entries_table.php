<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel bloc-scoped watchlist.
 *
 * Distinct from `ci_review_watchlist` (per-user personal pin):
 *   - per (viewer_bloc_id, character_id) — alliance-leadership shared queue
 *   - status workflow (watching/escalated/cleared/archived)
 *   - reason + notes for context handoff between operators
 *   - allows manual override / escalation regardless of Phase 1 band
 *
 * Used by alliance leadership to maintain a director-facing review
 * queue. Lifecycle examples:
 *   - watching:  new entry, awaiting review
 *   - escalated: someone confirmed enough signal to formally review
 *   - cleared:   reviewed and considered not a concern (kept for audit)
 *   - archived:  no longer actionable, kept for history only
 *
 * Replaces a formal validation team for now (none exists yet). The
 * calibration spec will read cleared/escalated transitions as weak
 * ground-truth labels.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_watchlist_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                character_id BIGINT UNSIGNED NOT NULL,
                added_by_user_id BIGINT UNSIGNED NULL,
                status ENUM('watching','escalated','cleared','archived') NOT NULL DEFAULT 'watching',
                reason VARCHAR(255) NULL,
                notes TEXT NULL,
                priority_override ENUM('critical','high','elevated','note_only','none') NOT NULL DEFAULT 'none',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                last_status_change_at DATETIME NULL,
                last_status_change_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_bloc_char (viewer_bloc_id, character_id),
                INDEX idx_ci_wl_status (viewer_bloc_id, status),
                INDEX idx_ci_wl_added_by (added_by_user_id),
                INDEX idx_ci_wl_character (character_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_watchlist_entries');
    }
};
