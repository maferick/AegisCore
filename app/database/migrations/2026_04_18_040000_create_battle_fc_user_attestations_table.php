<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 6 — battle_fc_user_attestations
|--------------------------------------------------------------------------
|
| Append-only table of donor-tier user attestations of "this pilot
| was the FC of this sub-fleet." Silent truth-set data for Spec 7
| calibration; never surfaced to other users (Mode A discipline).
|
| Design:
|   - attestation_id autoincrement; a user may attest multiple times
|     on the same sub-fleet and all rows are retained for audit.
|     Latest-wins semantics applied at read time.
|   - attested_character_id NOT FK-constrained — membership check is
|     enforced at the app layer so a partition rewrite doesn't break
|     historical attestations.
|   - user_id FKs to the users table.
|   - sub-fleet FK uses the full composite PK (battle, alliance,
|     sub_fleet_id, partition_algo_version) rebuilt by Spec 3
|     migration 000005. partition_algo_version is pinned on the
|     attestation so a future partition rerun can be tied back.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_fc_user_attestations (
                attestation_id         BIGINT NOT NULL AUTO_INCREMENT,
                battle_id              BIGINT NOT NULL,
                alliance_id            BIGINT NOT NULL,
                sub_fleet_id           INT NOT NULL,
                partition_algo_version INT NOT NULL,
                attested_character_id  BIGINT NOT NULL,
                user_id                BIGINT UNSIGNED NOT NULL,
                attested_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                user_note              VARCHAR(255) NULL,

                PRIMARY KEY (attestation_id),

                KEY idx_bfua_battle_side_subfleet
                    (battle_id, alliance_id, sub_fleet_id, partition_algo_version),
                KEY idx_bfua_user (user_id, attested_at DESC),
                KEY idx_bfua_latest_per_user_subfleet
                    (battle_id, alliance_id, sub_fleet_id, partition_algo_version, user_id, attested_at DESC),
                KEY fk_bfua_subfleet
                    (battle_id, alliance_id, sub_fleet_id, partition_algo_version),
                KEY fk_bfua_user (user_id),

                CONSTRAINT fk_bfua_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id, partition_algo_version)
                    REFERENCES battle_sub_fleets (battle_id, alliance_id, sub_fleet_id, partition_algo_version),
                CONSTRAINT fk_bfua_partition_version
                    FOREIGN KEY (partition_algo_version)
                    REFERENCES battle_sub_fleet_algo_versions (partition_algo_version),
                CONSTRAINT fk_bfua_user
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_fc_user_attestations');
    }
};
