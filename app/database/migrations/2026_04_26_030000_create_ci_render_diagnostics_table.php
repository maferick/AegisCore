<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel render diagnostics.
 *
 * Records what the dossier renderer produced for a (character, viewer_bloc)
 * pair on a given date. One row per unique (character, bloc, date).
 * Multiple renders during the same day update the same row so the table
 * stays bounded.
 *
 * Purpose:
 *   - Audit trail: directors can see what they were shown and when.
 *   - Calibration input: the calibration spec compares historical
 *     rendered_band against ci_character_ground_truth labels.
 *   - Drift detection: same character's band changes day-over-day are
 *     observable from this table without re-running the service.
 *
 * Out of scope (write-once-on-render):
 *   - Manual band overrides go to ci_watchlist_entries instead.
 *   - Per-render telemetry / latency stays in app-level logs.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_render_diagnostics (
                character_id BIGINT UNSIGNED NOT NULL,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                rendered_on DATE NOT NULL,
                rendered_at DATETIME NOT NULL,
                rendered_band VARCHAR(20) NOT NULL,
                raw_band VARCHAR(20) NOT NULL,
                confidence VARCHAR(16) NOT NULL,
                flag_count INT UNSIGNED NOT NULL DEFAULT 0,
                note_count INT UNSIGNED NOT NULL DEFAULT 0,
                total_countable INT UNSIGNED NOT NULL DEFAULT 0,
                has_hostile_relative TINYINT(1) NOT NULL DEFAULT 0,
                demoted TINYINT(1) NOT NULL DEFAULT 0,
                declared_in_bloc TINYINT(1) NOT NULL DEFAULT 0,
                rendered_signals_json LONGTEXT NOT NULL,
                sample_sizes_json TEXT NULL,
                confidence_factors_json TEXT NULL,
                evidence_summary VARCHAR(500) NULL,
                PRIMARY KEY (character_id, viewer_bloc_id, rendered_on),
                INDEX idx_ci_diag_band (rendered_band, rendered_on),
                INDEX idx_ci_diag_at (rendered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_render_diagnostics');
    }
};
