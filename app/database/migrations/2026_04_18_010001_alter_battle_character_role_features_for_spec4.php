<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 4 Role Feature Extraction (v1, Scoped) — schema
|--------------------------------------------------------------------------
|
| v1 computes 15 features per (battle, alliance, sub_fleet, character).
| 8 of them already have columns from Spec 1 (damage_share,
| kill_participation_rate, presence_span, early_presence,
| late_presence, death_order_pct, degree_centrality, pagerank); 7
| need to be added here.
|
| Per-row added columns:
|   - ship_class_category            VARCHAR(16) nullable (null = "other")
|   - is_in_subfleet_0               TINYINT(1)  NOT NULL — no default
|                                    so every row must be written by
|                                    the extractor, preventing silent
|                                    fall-through to 0 for real sub-fleets.
|
| Per-sub-fleet features denormalized onto each row (same value across
| rows in the same sub-fleet):
|   - subfleet_member_count          INT nullable
|   - subfleet_damage_share_of_side  DECIMAL(5,4) nullable
|   - subfleet_dominant_hull_class   VARCHAR(16) nullable
|   - subfleet_hull_class_concentration DECIMAL(5,4) nullable
|   - subfleet_has_logi              TINYINT(1) nullable
|
| Spec 1 columns that v1 does not populate are made NULLABLE so the
| extractor writes explicit NULL rather than a misleading 0.0000.
| This is a one-way change — a future v2 that populates them again
| can still write non-null values without a migration.
|
*/
return new class extends Migration
{
    /**
     * Spec 1 feature columns NOT populated by v1. Extractor writes NULL.
     */
    private const UNPOPULATED_V1 = [
        'primary_sub_fleet_share',
        'victim_overlap_density',
        'same_bucket_cooccurrence',
        'engagement_phase_count_norm',
        'betweenness_centrality',
        'clustering_coefficient',
        'local_blob_score',
        'support_ring_score',
        'edge_cluster_score',
        'logi_ring_affinity',
        'fc_core_affinity',
        'final_blow_rate',
        'contributed_kill_rate',
        'isk_killed_share',
        'isk_lost_norm',
        'target_spread',
        'focus_fire_alignment',
    ];

    public function up(): void
    {
        // 1. Drop the primary_sub_fleet_share CHECK — we're about to
        //    relax it to nullable; CHECK constraint on NULL values is
        //    always TRUE so functionally equivalent after, but the
        //    existing bound is 0..1 which still holds for non-null.
        //    Keep it; just relax NULLability.
        foreach (self::UNPOPULATED_V1 as $col) {
            DB::statement("ALTER TABLE battle_character_role_features MODIFY COLUMN `{$col}` DECIMAL(5,4) NULL DEFAULT NULL");
        }

        // 2. Add Spec 4 columns. is_in_subfleet_0 has no default so
        //    every write is forced to set it.
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD COLUMN ship_class_category VARCHAR(16) NULL DEFAULT NULL AFTER ship_type_id,
                ADD COLUMN is_in_subfleet_0 TINYINT(1) NOT NULL AFTER ship_class_category,
                ADD COLUMN subfleet_member_count INT NULL DEFAULT NULL AFTER is_in_subfleet_0,
                ADD COLUMN subfleet_damage_share_of_side DECIMAL(5,4) NULL DEFAULT NULL AFTER subfleet_member_count,
                ADD COLUMN subfleet_dominant_hull_class VARCHAR(16) NULL DEFAULT NULL AFTER subfleet_damage_share_of_side,
                ADD COLUMN subfleet_hull_class_concentration DECIMAL(5,4) NULL DEFAULT NULL AFTER subfleet_dominant_hull_class,
                ADD COLUMN subfleet_has_logi TINYINT(1) NULL DEFAULT NULL AFTER subfleet_hull_class_concentration
        SQL);

        DB::statement("ALTER TABLE battle_character_role_features ALTER COLUMN is_in_subfleet_0 DROP DEFAULT");

        // 3. Category CHECK: ship_class_category is null-or-one-of-five.
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_ship_class_category
                    CHECK (ship_class_category IS NULL OR ship_class_category IN ('logi','bomber','command','tackle','mainline'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_subfleet_dominant_hull_class
                    CHECK (subfleet_dominant_hull_class IS NULL OR subfleet_dominant_hull_class IN ('logi','bomber','command','tackle','mainline'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_subfleet_hull_class_concentration
                    CHECK (subfleet_hull_class_concentration IS NULL OR (subfleet_hull_class_concentration >= 0.0000 AND subfleet_hull_class_concentration <= 1.0000))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                ADD CONSTRAINT chk_bcrf_subfleet_damage_share_of_side
                    CHECK (subfleet_damage_share_of_side IS NULL OR (subfleet_damage_share_of_side >= 0.0000 AND subfleet_damage_share_of_side <= 1.0000))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_subfleet_damage_share_of_side');
        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_subfleet_hull_class_concentration');
        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_subfleet_dominant_hull_class');
        DB::statement('ALTER TABLE battle_character_role_features DROP CONSTRAINT chk_bcrf_ship_class_category');

        DB::statement(<<<'SQL'
            ALTER TABLE battle_character_role_features
                DROP COLUMN subfleet_has_logi,
                DROP COLUMN subfleet_hull_class_concentration,
                DROP COLUMN subfleet_dominant_hull_class,
                DROP COLUMN subfleet_damage_share_of_side,
                DROP COLUMN subfleet_member_count,
                DROP COLUMN is_in_subfleet_0,
                DROP COLUMN ship_class_category
        SQL);

        foreach (self::UNPOPULATED_V1 as $col) {
            DB::statement("ALTER TABLE battle_character_role_features MODIFY COLUMN `{$col}` DECIMAL(5,4) NOT NULL DEFAULT 0.0000");
        }
    }
};
