<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 4 fix pack — resubmission after review
|--------------------------------------------------------------------------
|
| Four changes driven by the Spec 4 review:
|
| 1. Relax battle_character_role_features.degree_centrality and .pagerank
|    to NULL. Spec 1 declared them NOT NULL DEFAULT 0.0000 which was
|    wrong for Spec 4's small-tier case (no graph → no values). This is
|    a Spec 1 schema bug surfaced by Spec 4, analogous to the Spec 3 PK
|    bug surfaced by version coexistence.
|
| 2. Promote 'other' to a first-class category value. 'other' means
|    "hull known, not in the v1 scope" (e.g. Venture, scanning alts).
|    NULL is reserved for the rare "ship_type_id itself missing from
|    killmail data" case. CHECK constraints on both the mapping table
|    and the features table must allow 'other'.
|
| 3. Seed Zarmazd (faction logi cruiser, 49713) into the mapping. It
|    was missing from v1 and Spec 4 review flagged it explicitly.
|
| Both CHECK constraints on battle_character_role_features
| (ship_class_category, subfleet_dominant_hull_class) are rebuilt
| to include 'other'. The mapping table's CHECK is rebuilt as well.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. NULLability for graph-derived feature cols ---
        DB::statement('ALTER TABLE battle_character_role_features MODIFY COLUMN degree_centrality DECIMAL(5,4) NULL DEFAULT NULL');
        DB::statement('ALTER TABLE battle_character_role_features MODIFY COLUMN pagerank DECIMAL(5,4) NULL DEFAULT NULL');

        // --- 2. 'other' as first-class category ---
        // Rebuild mapping CHECK.
        DB::statement('ALTER TABLE ship_class_category_mapping DROP CONSTRAINT chk_sccm_category');
        DB::statement(<<<'SQL'
            ALTER TABLE ship_class_category_mapping
                ADD CONSTRAINT chk_sccm_category
                    CHECK (category IN ('logi','bomber','command','tackle','mainline','other'))
        SQL);

        // Rebuild battle_character_role_features CHECKs on both category columns.
        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_ship_class_category');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_ship_class_category
                    CHECK (ship_class_category IS NULL OR ship_class_category IN ('logi','bomber','command','tackle','mainline','other'))
        SQL);

        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_subfleet_dominant_hull_class');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_subfleet_dominant_hull_class
                    CHECK (subfleet_dominant_hull_class IS NULL OR subfleet_dominant_hull_class IN ('logi','bomber','command','tackle','mainline','other'))
        SQL);

        // --- 3. Seed Zarmazd ---
        DB::statement(
            "INSERT IGNORE INTO ship_class_category_mapping (ship_type_id, category) VALUES (?, ?)",
            [49713, 'logi']
        );
    }

    public function down(): void
    {
        DB::statement("DELETE FROM ship_class_category_mapping WHERE ship_type_id = 49713");

        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_subfleet_dominant_hull_class');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_subfleet_dominant_hull_class
                    CHECK (subfleet_dominant_hull_class IS NULL OR subfleet_dominant_hull_class IN ('logi','bomber','command','tackle','mainline'))
        SQL);

        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_ship_class_category');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_ship_class_category
                    CHECK (ship_class_category IS NULL OR ship_class_category IN ('logi','bomber','command','tackle','mainline'))
        SQL);

        DB::statement('ALTER TABLE ship_class_category_mapping DROP CONSTRAINT chk_sccm_category');
        DB::statement(<<<'SQL'
            ALTER TABLE ship_class_category_mapping
                ADD CONSTRAINT chk_sccm_category
                    CHECK (category IN ('logi','bomber','command','tackle','mainline'))
        SQL);

        // NB: reverting NULLability is destructive when NULL rows exist — we
        // null out any existing NULLs first so the NOT NULL flip can succeed.
        DB::statement('UPDATE battle_character_role_features SET pagerank = 0.0000 WHERE pagerank IS NULL');
        DB::statement('UPDATE battle_character_role_features SET degree_centrality = 0.0000 WHERE degree_centrality IS NULL');
        DB::statement('ALTER TABLE battle_character_role_features MODIFY COLUMN pagerank DECIMAL(5,4) NOT NULL DEFAULT 0.0000');
        DB::statement('ALTER TABLE battle_character_role_features MODIFY COLUMN degree_centrality DECIMAL(5,4) NOT NULL DEFAULT 0.0000');
    }
};
