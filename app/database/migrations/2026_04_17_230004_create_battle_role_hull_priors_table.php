<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_role_hull_priors — sparse hull prior store
|--------------------------------------------------------------------------
|
| Per Spec 1. Hull → role prior weight. Sparse by design: only
| unambiguous canonical hulls are seeded. Missing rows fall through to
| the scorer's default fallback prior. T3Cs, command destroyers, and
| fit-dependent hulls belong in calibration, not here.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_role_hull_priors (
                role_key             VARCHAR(32) NOT NULL,
                ship_type_id         INT NOT NULL,
                prior_weight         DECIMAL(5,4) NOT NULL,
                notes                VARCHAR(255) NULL,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (role_key, ship_type_id),
                CONSTRAINT fk_brhp_role
                    FOREIGN KEY (role_key)
                    REFERENCES battle_roles(role_key),
                CONSTRAINT chk_brhp_prior_weight
                    CHECK (prior_weight >= 0.0000 AND prior_weight <= 1.0000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_role_hull_priors');
    }
};
