<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sovereignty snapshot per solar system — who "owns" it.
 *
 * Populated from ESI /sovereignty/map/ via `sov:sync`. Changes as
 * wars progress, so cache + refresh hourly.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE system_sovereignty (
                solar_system_id INT UNSIGNED NOT NULL,
                alliance_id BIGINT UNSIGNED NULL,
                corporation_id BIGINT UNSIGNED NULL,
                faction_id INT UNSIGNED NULL,
                fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (solar_system_id),
                KEY ix_sov_alliance (alliance_id),
                KEY ix_sov_corp (corporation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS system_sovereignty');
    }
};
