<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_role_scoring_weights — versioned coefficient store
|--------------------------------------------------------------------------
|
| Per Spec 1. One row per (weight_version, role_key, score_class,
| coefficient_key). Formulas and coefficient keys themselves are
| deferred to later specs; this table guarantees the storage shape
| they will write into.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_role_scoring_weights (
                weight_version       INT NOT NULL,
                role_key             VARCHAR(32) NOT NULL,
                score_class          VARCHAR(32) NOT NULL,
                coefficient_key      VARCHAR(64) NOT NULL,
                coefficient_value    DECIMAL(7,4) NOT NULL,
                is_active            TINYINT(1) NOT NULL DEFAULT 1,
                notes                VARCHAR(255) NULL,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (weight_version, role_key, score_class, coefficient_key),
                CONSTRAINT fk_brsw_weight_version
                    FOREIGN KEY (weight_version)
                    REFERENCES battle_role_weight_versions(weight_version),
                CONSTRAINT fk_brsw_role
                    FOREIGN KEY (role_key)
                    REFERENCES battle_roles(role_key),
                CONSTRAINT chk_brsw_score_class
                    CHECK (score_class IN ('structural', 'temporal', 'hull', 'final'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_role_scoring_weights');
    }
};
