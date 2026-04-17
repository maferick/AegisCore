<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_role_weight_versions — header table for versioned weight sets
|--------------------------------------------------------------------------
|
| Per Spec 1. Every row in battle_role_scoring_weights /
| battle_character_role_scores / battle_character_role_inference must
| point at a weight_version here for auditability.
|
| The is_default_key virtual column enforces at-most-one default across
| the table because NULL values don't collide in a UNIQUE index.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_role_weight_versions (
                weight_version       INT NOT NULL AUTO_INCREMENT,
                label                VARCHAR(64) NOT NULL,
                description          TEXT NULL,
                is_default           TINYINT(1) NOT NULL DEFAULT 0,
                is_default_key       INT GENERATED ALWAYS AS (
                    CASE WHEN is_default = 1 THEN 1 ELSE NULL END
                ) VIRTUAL,
                created_by           VARCHAR(64) NULL,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (weight_version),
                UNIQUE KEY uk_brwv_label (label),
                UNIQUE KEY uk_brwv_single_default (is_default_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_role_weight_versions');
    }
};
