<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corporation factional-warfare enlistment snapshot.
 *
 * Populated from ESI /corporations/{id}/fw/stats/ (public, unauthed).
 * When a corp enlists in FW its pilots automatically inherit
 * faction_id on killmails — which the bloc-intel FW-taint dampening
 * already keys on — but the corp-level signal lets us tag "this is
 * a militia corp" at render time without inferring from mail
 * attackers one-by-one.
 *
 * Refreshed lazily: any corp that shows up on a killmail in the last
 * 90 days with no row or a stale row (> 7 d) gets re-fetched by the
 * FetchCorporationFwEnlistment job.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE corporation_fw_enlistment (
                corporation_id BIGINT UNSIGNED NOT NULL,
                faction_id BIGINT UNSIGNED NULL,
                enlisted_on DATETIME NULL,
                is_enlisted TINYINT(1) NOT NULL DEFAULT 0,
                kills_yesterday INT UNSIGNED NULL,
                kills_last_week INT UNSIGNED NULL,
                kills_total BIGINT UNSIGNED NULL,
                victory_points_yesterday INT UNSIGNED NULL,
                victory_points_last_week INT UNSIGNED NULL,
                victory_points_total BIGINT UNSIGNED NULL,
                last_fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (corporation_id),
                KEY ix_cfe_faction (faction_id),
                KEY ix_cfe_enlisted (is_enlisted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS corporation_fw_enlistment');
    }
};
