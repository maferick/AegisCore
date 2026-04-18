<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_sub_fleets — pin to graph metrics snapshot + track absorbed orphans
|--------------------------------------------------------------------------
|
| Per Spec 3 § 4 (schema changes). Adds:
|   - source_edge_profile_version / source_algo_profile_version — both
|     NOT NULL, no DEFAULT. DEFAULT is added migration-time to satisfy
|     the NOT NULL against the 1,635 existing theater-sub-fleet rows
|     (there shouldn't be any yet since Spec 3 is the first writer —
|     but Spec 1 reserved the table, so any test fixtures get the
|     sentinel value). DEFAULT drops immediately; Spec 3 writer sets
|     these explicitly from the graph metrics combo it partitioned
|     against.
|   - absorbed_orphan_count — keeps its DEFAULT 0 because 0 is the
|     correct value for any sub-fleet that didn't absorb orphans.
|     CHECK constraint enforces only sub_fleet_id=0 can carry a
|     non-zero count.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE battle_sub_fleets
                ADD COLUMN source_edge_profile_version INT NOT NULL DEFAULT 1
                    AFTER partition_algo_version,
                ADD COLUMN source_algo_profile_version INT NOT NULL DEFAULT 1
                    AFTER source_edge_profile_version,
                ADD COLUMN absorbed_orphan_count INT NOT NULL DEFAULT 0
                    AFTER source_algo_profile_version
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE battle_sub_fleets
                ALTER COLUMN source_edge_profile_version DROP DEFAULT,
                ALTER COLUMN source_algo_profile_version DROP DEFAULT
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE battle_sub_fleets
                ADD CONSTRAINT fk_bsf_source_edge_profile
                    FOREIGN KEY (source_edge_profile_version)
                    REFERENCES battle_graph_edge_profile_versions(edge_profile_version),
                ADD CONSTRAINT fk_bsf_source_algo_profile
                    FOREIGN KEY (source_algo_profile_version)
                    REFERENCES battle_graph_algo_profile_versions(algo_profile_version),
                ADD CONSTRAINT chk_bsf_orphan_count_valid
                    CHECK (absorbed_orphan_count = 0 OR sub_fleet_id = 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE battle_sub_fleets
                DROP CONSTRAINT chk_bsf_orphan_count_valid,
                DROP FOREIGN KEY fk_bsf_source_algo_profile,
                DROP FOREIGN KEY fk_bsf_source_edge_profile,
                DROP COLUMN absorbed_orphan_count,
                DROP COLUMN source_algo_profile_version,
                DROP COLUMN source_edge_profile_version
        SQL);
    }
};
