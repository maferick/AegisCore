<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_roles — lookup table for supported role keys
|--------------------------------------------------------------------------
|
| Per Spec 1. Role keys are normalized through this lookup; downstream
| tables carry role_key as FK rather than repeating the string value
| across every score row. No CHECK constraint on role_key — the FK to
| this table is the source of truth.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_roles (
                role_key             VARCHAR(32) NOT NULL,
                display_name         VARCHAR(64) NOT NULL,
                sort_order           INT NOT NULL DEFAULT 0,
                is_active            TINYINT(1) NOT NULL DEFAULT 1,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (role_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_roles');
    }
};
