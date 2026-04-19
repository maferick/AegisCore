<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Precomputed titan-bridge-range (≤6 LY) system pairs.
 *
 * EVE uses 1 LY = 9.46e15 meters exactly (CCP in-game value,
 * slightly shorter than the real 9.4607e15). Titan bridge range
 * is 6 LY flat (no skill bonuses).
 *
 * Populate via `php artisan map:rebuild-titan-bridges`. One-off
 * ~25M pair checks on ref_solar_systems coords; output pruned to
 * pairs ≤ 6.0 LY.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE system_titan_bridges (
                from_system_id INT UNSIGNED NOT NULL,
                to_system_id INT UNSIGNED NOT NULL,
                ly_distance DECIMAL(6,4) NOT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (from_system_id, to_system_id),
                KEY ix_stb_to (to_system_id),
                KEY ix_stb_ly (ly_distance),
                CONSTRAINT chk_stb_order CHECK (from_system_id < to_system_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS system_titan_bridges');
    }
};
