<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alliance leadership snapshot — creator + executor corp.
 *
 * Populated from ESI /alliances/{id}/ (public, no auth, ETag cached
 * for 1h). Used by the counter-intel read path to suppress
 * anomaly flags on pilots who are the founder / executor-corp
 * leadership of their own alliance — their "external heavy ties
 * + recent join" pattern is structurally expected for an alliance
 * founder and shouldn't read as spy behaviour.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE alliance_leadership (
                alliance_id BIGINT UNSIGNED NOT NULL,
                creator_character_id BIGINT UNSIGNED NULL,
                creator_corporation_id BIGINT UNSIGNED NULL,
                executor_corporation_id BIGINT UNSIGNED NULL,
                name VARCHAR(200) NULL,
                ticker VARCHAR(20) NULL,
                date_founded DATETIME NULL,
                last_fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (alliance_id),
                KEY ix_al_creator (creator_character_id),
                KEY ix_al_executor (executor_corporation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS alliance_leadership');
    }
};
