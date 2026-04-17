<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_character_role_scores — canonical long-format role score store
|--------------------------------------------------------------------------
|
| Per Spec 1. One row per (battle, alliance, sub_fleet, character,
| weight_version, role, score_class). Canonical source for calibration
| and diagnostics. `battle_character_role_inference` is a denormalized
| winner/runner-up read optimisation on top of this table.
|
| Decomposed score classes (structural, temporal, hull) may be signed;
| score_value is expected to stay within roughly [-2.0000, 2.0000] as
| a diagnostic range (no DB enforcement). `final` rows must be clamped
| to [0,1] by the writer — see Spec 1 § 10.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_character_role_scores (
                battle_id              BIGINT NOT NULL,
                alliance_id            BIGINT NOT NULL,
                sub_fleet_id           INT NOT NULL,
                character_id           BIGINT NOT NULL,
                weight_version         INT NOT NULL,
                role_key               VARCHAR(32) NOT NULL,
                score_class            VARCHAR(32) NOT NULL,
                score_value            DECIMAL(5,4) NOT NULL,
                computed_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (
                    battle_id,
                    alliance_id,
                    sub_fleet_id,
                    character_id,
                    weight_version,
                    role_key,
                    score_class
                ),

                KEY idx_bcrs_battle_side_subfleet_version (
                    battle_id,
                    alliance_id,
                    sub_fleet_id,
                    weight_version
                ),
                KEY idx_bcrs_role_class (
                    role_key,
                    score_class,
                    weight_version
                ),
                KEY idx_bcrs_character_version (
                    battle_id,
                    alliance_id,
                    character_id,
                    weight_version
                ),

                CONSTRAINT fk_bcrs_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id),

                CONSTRAINT fk_bcrs_weight_version
                    FOREIGN KEY (weight_version)
                    REFERENCES battle_role_weight_versions(weight_version),

                CONSTRAINT fk_bcrs_role
                    FOREIGN KEY (role_key)
                    REFERENCES battle_roles(role_key),

                CONSTRAINT chk_bcrs_score_class
                    CHECK (score_class IN ('structural', 'temporal', 'hull', 'final'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_character_role_scores');
    }
};
