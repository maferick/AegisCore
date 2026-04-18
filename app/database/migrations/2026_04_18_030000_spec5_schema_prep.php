<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 5 schema prep — fix the latent partition-version PK bugs on
| role_scores + role_inference, and forward-compatibly extend the
| score_class CHECK to admit 'historical' (reserved for a future
| score component; no writer uses it yet).
|--------------------------------------------------------------------------
|
| Two PK rebuilds mirror the pattern Spec 3 used for battle_sub_fleets:
| Spec 1 declared PKs without partition_algo_version, so two concurrent
| partition versions on the same (battle, alliance) clobber each other.
| Features already includes partition_algo_version in its PK (Spec 4
| migration 000002); scores + inference did not.
|
| The FKs referencing battle_sub_fleets already include
| partition_algo_version — they were rewritten in migration 000005.
| Only the PKs need the extension here.
|
| The CHECK extension is forward-only: extending the enum cannot break
| existing rows. No writer emits 'historical' until a future spec does.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. score_class CHECK: add 'historical' as reserved value ---
        DB::statement('ALTER TABLE battle_character_role_scores DROP CONSTRAINT chk_bcrs_score_class');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_scores
                ADD CONSTRAINT chk_bcrs_score_class
                    CHECK (score_class IN ('structural','temporal','hull','historical','final'))
        SQL);

        // --- 2. rebuild scores PK to include partition_algo_version ---
        DB::statement('ALTER TABLE battle_character_role_scores DROP PRIMARY KEY, ADD PRIMARY KEY (battle_id, alliance_id, sub_fleet_id, character_id, partition_algo_version, weight_version, role_key, score_class)');

        // --- 3. rebuild inference PK to include partition_algo_version ---
        DB::statement('ALTER TABLE battle_character_role_inference DROP PRIMARY KEY, ADD PRIMARY KEY (battle_id, alliance_id, sub_fleet_id, character_id, partition_algo_version, weight_version)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE battle_character_role_inference DROP PRIMARY KEY, ADD PRIMARY KEY (battle_id, alliance_id, sub_fleet_id, character_id, weight_version)');
        DB::statement('ALTER TABLE battle_character_role_scores DROP PRIMARY KEY, ADD PRIMARY KEY (battle_id, alliance_id, sub_fleet_id, character_id, weight_version, role_key, score_class)');

        DB::statement('ALTER TABLE battle_character_role_scores DROP CONSTRAINT chk_bcrs_score_class');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_scores
                ADD CONSTRAINT chk_bcrs_score_class
                    CHECK (score_class IN ('structural','temporal','hull','final'))
        SQL);
    }
};
