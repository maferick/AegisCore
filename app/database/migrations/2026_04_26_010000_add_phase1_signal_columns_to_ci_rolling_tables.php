<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel Phase 1 signal expansion.
 *
 * Extends the per-character features table with bloc-agnostic signals
 * (dormancy, corp-cadence, pod-survival, cheap-loss, battle-only,
 * naming cluster) and the per-character × per-bloc anomalies table
 * with bloc-relative signals (asymmetric mutual presence, hostile
 * triangulation, community-vs-declared mismatch).
 *
 * All columns nullable / default 0 — back-fill driven by the Python
 * phase1 computer; rows missing the new fields read as "not yet
 * computed" rather than "zero".
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_features_rolling
              ADD COLUMN dormancy_max_gap_days INT UNSIGNED NULL AFTER days_since_last_activity,
              ADD COLUMN dormancy_reactivated_at DATETIME NULL AFTER dormancy_max_gap_days,
              ADD COLUMN dormancy_days_to_corp_change INT UNSIGNED NULL AFTER dormancy_reactivated_at,
              ADD COLUMN corp_tenure_min_days INT UNSIGNED NULL AFTER dormancy_days_to_corp_change,
              ADD COLUMN corp_tenure_stdev_days DECIMAL(10,2) NULL AFTER corp_tenure_min_days,
              ADD COLUMN small_gang_loss_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER corp_tenure_stdev_days,
              ADD COLUMN solo_loss_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER small_gang_loss_count,
              ADD COLUMN pod_loss_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER solo_loss_count,
              ADD COLUMN ship_loss_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER pod_loss_count,
              ADD COLUMN pod_survival_rate DECIMAL(5,4) NULL AFTER ship_loss_count,
              ADD COLUMN cheap_loss_rate DECIMAL(5,4) NULL AFTER pod_survival_rate,
              ADD COLUMN battle_only_score DECIMAL(5,4) NULL AFTER cheap_loss_rate,
              ADD COLUMN naming_cluster_id BIGINT UNSIGNED NULL AFTER battle_only_score,
              ADD COLUMN naming_cluster_size INT UNSIGNED NULL AFTER naming_cluster_id,
              ADD INDEX idx_ci_feat_naming (naming_cluster_id),
              ADD INDEX idx_ci_feat_dormancy (dormancy_max_gap_days)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_anomalies_rolling
              ADD COLUMN asymmetric_top_pair_character_id BIGINT UNSIGNED NULL AFTER hostile_cooccurrence_count,
              ADD COLUMN asymmetric_top_pair_outbound_pct DECIMAL(5,4) NULL AFTER asymmetric_top_pair_character_id,
              ADD COLUMN asymmetric_top_pair_inbound_pct DECIMAL(5,4) NULL AFTER asymmetric_top_pair_outbound_pct,
              ADD COLUMN asymmetric_top_pair_battles INT UNSIGNED NULL AFTER asymmetric_top_pair_inbound_pct,
              ADD COLUMN hostile_triangle_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER asymmetric_top_pair_battles,
              ADD COLUMN hostile_triangle_top_size INT UNSIGNED NULL AFTER hostile_triangle_count,
              ADD COLUMN community_hostile_pct DECIMAL(5,4) NULL AFTER hostile_triangle_top_size,
              ADD COLUMN community_neighbor_count INT UNSIGNED NULL AFTER community_hostile_pct,
              ADD INDEX idx_ci_anom_asym (asymmetric_top_pair_outbound_pct),
              ADD INDEX idx_ci_anom_triangle (hostile_triangle_count)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_features_rolling
              DROP INDEX idx_ci_feat_naming,
              DROP INDEX idx_ci_feat_dormancy,
              DROP COLUMN dormancy_max_gap_days,
              DROP COLUMN dormancy_reactivated_at,
              DROP COLUMN dormancy_days_to_corp_change,
              DROP COLUMN corp_tenure_min_days,
              DROP COLUMN corp_tenure_stdev_days,
              DROP COLUMN small_gang_loss_count,
              DROP COLUMN solo_loss_count,
              DROP COLUMN pod_loss_count,
              DROP COLUMN ship_loss_count,
              DROP COLUMN pod_survival_rate,
              DROP COLUMN cheap_loss_rate,
              DROP COLUMN battle_only_score,
              DROP COLUMN naming_cluster_id,
              DROP COLUMN naming_cluster_size
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_anomalies_rolling
              DROP INDEX idx_ci_anom_asym,
              DROP INDEX idx_ci_anom_triangle,
              DROP COLUMN asymmetric_top_pair_character_id,
              DROP COLUMN asymmetric_top_pair_outbound_pct,
              DROP COLUMN asymmetric_top_pair_inbound_pct,
              DROP COLUMN asymmetric_top_pair_battles,
              DROP COLUMN hostile_triangle_count,
              DROP COLUMN hostile_triangle_top_size,
              DROP COLUMN community_hostile_pct,
              DROP COLUMN community_neighbor_count
        SQL);
    }
};
