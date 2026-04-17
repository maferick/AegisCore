<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_character_role_inference — denormalized winner/runner-up store
|--------------------------------------------------------------------------
|
| Per Spec 1. UI + report read path. One row per (battle, alliance,
| sub_fleet, character, weight_version) carrying only the top two
| roles and their scores. primary_score / second_best_score /
| confidence must be clamped to [0,1] by the writer.
|
| `role_reason_json` is reserved for a later explanation format;
| Spec 1 only guarantees it is a nullable JSON column.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_character_role_inference (
                battle_id                    BIGINT NOT NULL,
                alliance_id                  BIGINT NOT NULL,
                sub_fleet_id                 INT NOT NULL,
                character_id                 BIGINT NOT NULL,
                weight_version               INT NOT NULL,
                ship_type_id                 INT NULL,

                primary_role_key             VARCHAR(32) NOT NULL,
                secondary_role_key           VARCHAR(32) NULL,

                primary_score                DECIMAL(5,4) NOT NULL,
                second_best_score            DECIMAL(5,4) NOT NULL,
                confidence                   DECIMAL(5,4) NOT NULL,
                confidence_band              VARCHAR(16) NOT NULL,

                contested                    TINYINT(1) NOT NULL DEFAULT 0,
                unobserved_or_uncertain      TINYINT(1) NOT NULL DEFAULT 0,

                role_reason_json             JSON NULL,

                ui_state                     VARCHAR(16) NOT NULL DEFAULT 'beta',
                stale_for_weight_version     TINYINT(1) NOT NULL DEFAULT 0,

                computed_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (battle_id, alliance_id, sub_fleet_id, character_id, weight_version),

                KEY idx_bcri_battle_side_subfleet (
                    battle_id,
                    alliance_id,
                    sub_fleet_id,
                    confidence DESC
                ),
                KEY idx_bcri_role_lookup (
                    battle_id,
                    alliance_id,
                    sub_fleet_id,
                    primary_role_key,
                    confidence DESC
                ),
                KEY idx_bcri_weight_version (weight_version),

                CONSTRAINT fk_bcri_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id),

                CONSTRAINT fk_bcri_weight_version
                    FOREIGN KEY (weight_version)
                    REFERENCES battle_role_weight_versions(weight_version),

                CONSTRAINT fk_bcri_primary_role
                    FOREIGN KEY (primary_role_key)
                    REFERENCES battle_roles(role_key),

                CONSTRAINT fk_bcri_secondary_role
                    FOREIGN KEY (secondary_role_key)
                    REFERENCES battle_roles(role_key),

                CONSTRAINT chk_bcri_confidence_band
                    CHECK (confidence_band IN ('high', 'medium', 'low')),
                CONSTRAINT chk_bcri_ui_state
                    CHECK (ui_state IN ('beta', 'production', 'hidden'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_character_role_inference');
    }
};
