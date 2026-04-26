<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — recurring hostile micro-network detection.
 *
 * Stores the top hostile triangle per (character, viewer_bloc, window).
 * A "triangle" is 3+ hostile characters whose pairwise co-occurrence
 * opposite the target on distinct battle days passes a threshold.
 *
 * Spec §1.3: "Recurring hostile clusters. One repeated opponent can
 * be timezone overlap. A recurring hostile micro-network is a stronger
 * counter-intel signal."
 *
 * Storage shape:
 *   - PK (character_id, viewer_bloc_id, window_end_date) — top triangle per row
 *   - member_ids_json: full list of triangle members
 *   - shared_battle_days: lower bound of pairwise distinct days
 *   - triangle_size: count of members (>= 3)
 *   - weight: sum of pairwise day counts (rough significance)
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_hostile_triangulation (
                character_id BIGINT UNSIGNED NOT NULL,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,
                triangle_size INT UNSIGNED NOT NULL,
                shared_battle_days INT UNSIGNED NOT NULL,
                weight DECIMAL(10,2) NOT NULL,
                member_ids_json TEXT NOT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (character_id, viewer_bloc_id, window_end_date),
                INDEX idx_ci_tri_size (triangle_size),
                INDEX idx_ci_tri_days (shared_battle_days)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_hostile_triangulation');
    }
};
