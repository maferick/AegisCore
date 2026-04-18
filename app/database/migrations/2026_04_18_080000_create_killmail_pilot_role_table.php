<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-killmail pilot role tag. One row per (killmail_id, character_id)
 * capturing the ship the pilot was in on that specific killmail and
 * the role derived from the ship's hull category. Victim rows also
 * have an entry (the pilot was flying that ship when they died).
 *
 * Distinct from battle_character_role_inference, which aggregates the
 * pilot's role across the whole battle. Here we keep the killmail as
 * atom so reship chains + multi-role appearances are preserved.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE killmail_pilot_role (
                killmail_id BIGINT UNSIGNED NOT NULL,
                character_id BIGINT UNSIGNED NOT NULL,
                side ENUM('attacker','victim') NOT NULL,
                ship_type_id INT UNSIGNED NOT NULL,
                ship_class_category VARCHAR(16) NOT NULL DEFAULT 'other',
                role_key VARCHAR(32) NOT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (killmail_id, character_id, side),
                INDEX idx_kpr_char (character_id),
                INDEX idx_kpr_ship (ship_type_id),
                INDEX idx_kpr_role (role_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS killmail_pilot_role');
    }
};
