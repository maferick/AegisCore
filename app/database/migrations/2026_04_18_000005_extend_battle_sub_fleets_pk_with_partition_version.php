<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Extend battle_sub_fleets PK with partition_algo_version
|--------------------------------------------------------------------------
|
| Spec 3 § 10 requires that sub-fleet rows under different partition
| rule versions coexist. The Spec 1 PK (battle_id, alliance_id,
| sub_fleet_id) omits partition_algo_version, so a v2 partition run
| with different community thresholds UPSERTs on top of v1's rows
| and corrupts both.
|
| Fix: drop the PK, rebuild it with partition_algo_version included.
| Every FK that targets the composite key has to be rewritten to
| carry partition_algo_version too. All affected dependent tables
| (features, scores, inference, membership) are either empty at this
| migration or newly created by Spec 3, so the FK rewrite is cheap.
|
| The v3 test run earlier in Spec 3 implementation left corrupt
| sub_fleets state (v3 row clobbered v1's sub_fleet_id=0). Downstream
| membership rows still hold partition_algo_version=1 / 3 correctly,
| so a one-shot partition re-run under each version after this
| migration rebuilds both sides cleanly.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop the child FKs that reference the old PK. Features /
        //    scores / inference were populated only in the Spec 1
        //    verification harness (cleaned up at the end) so they
        //    should be empty now; membership has real rows but its
        //    FK was added by Spec 3 with the old shape.
        DB::statement('ALTER TABLE battle_character_role_features DROP FOREIGN KEY fk_bcrf_subfleet');
        DB::statement('ALTER TABLE battle_character_role_scores DROP FOREIGN KEY fk_bcrs_subfleet');
        DB::statement('ALTER TABLE battle_character_role_inference DROP FOREIGN KEY fk_bcri_subfleet');
        DB::statement('ALTER TABLE battle_character_sub_fleet_membership DROP FOREIGN KEY fk_bcsfm_sub_fleet');

        // 2. Rebuild the PK to include partition_algo_version. Any
        //    rows sharing (battle, alliance, sub_fleet_id) across
        //    versions are no longer a collision.
        DB::statement('ALTER TABLE battle_sub_fleets DROP PRIMARY KEY, ADD PRIMARY KEY (battle_id, alliance_id, sub_fleet_id, partition_algo_version)');

        // 3. Re-add child FKs, now keyed on the full composite.
        //    Features already carries partition_algo_version from
        //    migration 2026_04_18_000002; scores and inference need
        //    the column added before the FK can reference it.
        DB::statement('ALTER TABLE battle_character_role_scores ADD COLUMN partition_algo_version INT NOT NULL DEFAULT 1 AFTER sub_fleet_id');
        DB::statement('ALTER TABLE battle_character_role_scores ALTER COLUMN partition_algo_version DROP DEFAULT');
        DB::statement('ALTER TABLE battle_character_role_inference ADD COLUMN partition_algo_version INT NOT NULL DEFAULT 1 AFTER sub_fleet_id');
        DB::statement('ALTER TABLE battle_character_role_inference ALTER COLUMN partition_algo_version DROP DEFAULT');

        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT fk_bcrf_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id, partition_algo_version)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id, partition_algo_version)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_scores
                ADD CONSTRAINT fk_bcrs_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id, partition_algo_version)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id, partition_algo_version)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_inference
                ADD CONSTRAINT fk_bcri_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id, partition_algo_version)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id, partition_algo_version)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_sub_fleet_membership
                ADD CONSTRAINT fk_bcsfm_sub_fleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id, partition_algo_version)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id, partition_algo_version)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE battle_character_sub_fleet_membership DROP FOREIGN KEY fk_bcsfm_sub_fleet');
        DB::statement('ALTER TABLE battle_character_role_inference DROP FOREIGN KEY fk_bcri_subfleet');
        DB::statement('ALTER TABLE battle_character_role_scores DROP FOREIGN KEY fk_bcrs_subfleet');
        DB::statement('ALTER TABLE battle_character_role_features DROP FOREIGN KEY fk_bcrf_subfleet');

        DB::statement('ALTER TABLE battle_character_role_inference DROP COLUMN partition_algo_version');
        DB::statement('ALTER TABLE battle_character_role_scores DROP COLUMN partition_algo_version');

        DB::statement('ALTER TABLE battle_sub_fleets DROP PRIMARY KEY, ADD PRIMARY KEY (battle_id, alliance_id, sub_fleet_id)');

        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_sub_fleet_membership
                ADD CONSTRAINT fk_bcsfm_sub_fleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT fk_bcrf_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_scores
                ADD CONSTRAINT fk_bcrs_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_inference
                ADD CONSTRAINT fk_bcri_subfleet
                    FOREIGN KEY (battle_id, alliance_id, sub_fleet_id)
                    REFERENCES battle_sub_fleets(battle_id, alliance_id, sub_fleet_id)
        SQL);
    }
};
