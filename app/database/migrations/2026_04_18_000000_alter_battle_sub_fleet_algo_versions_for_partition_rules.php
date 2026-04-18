<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Extend battle_sub_fleet_algo_versions with partition rule config
|--------------------------------------------------------------------------
|
| Per Spec 3 § 8. The Spec 1 table was reserved as a header placeholder;
| Spec 3 adds the actual rule-configuration columns (min_community_size,
| orphan_reassignment_rule) plus single-default enforcement via the same
| virtual-column pattern already used by weight + graph profile tables.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE battle_sub_fleet_algo_versions
                ADD COLUMN min_community_size INT NOT NULL DEFAULT 10 AFTER description,
                ADD COLUMN orphan_reassignment_rule VARCHAR(32) NOT NULL DEFAULT 'absorb_into_sub_fleet_zero'
                    AFTER min_community_size,
                ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER orphan_reassignment_rule,
                ADD COLUMN is_default_key INT GENERATED ALWAYS AS (
                    CASE WHEN is_default = 1 THEN 1 ELSE NULL END
                ) VIRTUAL AFTER is_default,
                ADD CONSTRAINT chk_bsfav_min_community_size
                    CHECK (min_community_size >= 1),
                ADD CONSTRAINT chk_bsfav_orphan_rule
                    CHECK (orphan_reassignment_rule IN ('absorb_into_sub_fleet_zero')),
                ADD UNIQUE KEY uk_bsfav_single_default (is_default_key)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE battle_sub_fleet_algo_versions
                DROP INDEX uk_bsfav_single_default,
                DROP CONSTRAINT chk_bsfav_orphan_rule,
                DROP CONSTRAINT chk_bsfav_min_community_size,
                DROP COLUMN is_default_key,
                DROP COLUMN is_default,
                DROP COLUMN orphan_reassignment_rule,
                DROP COLUMN min_community_size
        SQL);
    }
};
