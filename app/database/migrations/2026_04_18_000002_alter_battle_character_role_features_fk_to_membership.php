<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Close the FK chain: features → membership
|--------------------------------------------------------------------------
|
| Per Spec 3 § 12 + § 13. Features table is empty at this point so the
| ALTER is cheap; Spec 4 must set partition_algo_version explicitly on
| every feature row. The DROP DEFAULT after the ADD COLUMN is the
| no-silent-default discipline — features without an explicit partition
| version fail loudly rather than silently route to version 1.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // ADD COLUMN with a DEFAULT to satisfy NOT NULL during migration,
        // then immediately DROP that DEFAULT so Spec 4 can't inherit it.
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD COLUMN partition_algo_version INT NOT NULL DEFAULT 1 AFTER sub_fleet_id
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ALTER COLUMN partition_algo_version DROP DEFAULT
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT fk_bcrf_membership
                    FOREIGN KEY (battle_id, alliance_id, character_id, partition_algo_version)
                    REFERENCES battle_character_sub_fleet_membership
                        (battle_id, alliance_id, character_id, partition_algo_version)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                DROP FOREIGN KEY fk_bcrf_membership,
                DROP COLUMN partition_algo_version
        SQL);
    }
};
