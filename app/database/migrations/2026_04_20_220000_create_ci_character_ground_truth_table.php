<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-intel ground-truth label store.
 *
 * Operator-confirmed outcomes for individual pilots — "confirmed
 * spy", "confirmed clean", "undecided". Feeds the GDS node-
 * classification pipeline (Phase C) and serves as a calibration
 * ledger so review_priority thresholds can be tuned against real
 * history rather than guesses.
 *
 * One row per (viewer_bloc_id, character_id). Last write wins;
 * previous labels aren't retained here because a tiny audit trail
 * isn't worth a second table — an operator flipping their own call
 * is the expected workflow.
 *
 * UI path: /admin/counter-intel/{id} has three buttons below the
 * watchlist toggle.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_character_ground_truth (
                viewer_bloc_id INT UNSIGNED NOT NULL,
                character_id BIGINT UNSIGNED NOT NULL,
                label ENUM('confirmed_spy','confirmed_clean','undecided') NOT NULL,
                reason VARCHAR(500) NULL,
                labelled_by_user_id BIGINT UNSIGNED NULL,
                labelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (viewer_bloc_id, character_id),
                KEY ix_cigt_label (viewer_bloc_id, label, labelled_at),
                KEY ix_cigt_char (character_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_character_ground_truth');
    }
};
