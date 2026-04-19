<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ansiblex jump-bridge corridors (player-owned jump gates).
 *
 * Unlike titan bridges (deterministic from system LY distance),
 * ansiblex destinations are per-structure data — stored in each
 * gate's link module. Public ESI doesn't expose the destination
 * without the owning corp's token. Populated via:
 *   - `map:import-ansiblex` CSV import (manual dump from in-game
 *     or a community source)
 *   - later: ESI scrape via characters with
 *     esi-corporations.read_structures.v1 scope for their own corp
 *
 * from < to enforced so each bridge renders once.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ansiblex_jump_bridges (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                from_system_id INT UNSIGNED NOT NULL,
                to_system_id INT UNSIGNED NOT NULL,
                alliance_id BIGINT UNSIGNED NULL,
                structure_id BIGINT UNSIGNED NULL,
                name VARCHAR(255) NULL,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_ajb_pair (from_system_id, to_system_id),
                KEY ix_ajb_from (from_system_id),
                KEY ix_ajb_to (to_system_id),
                CONSTRAINT chk_ajb_order CHECK (from_system_id < to_system_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ansiblex_jump_bridges');
    }
};
