<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.5 — doctrine + force-composition intelligence.
 *
 * operational_force_compositions  per cluster / incident: ship-class
 *                                  breakdown, doctrine match, role
 *                                  totals (logistics / tackle / dps /
 *                                  capital / bomber / ewar / command),
 *                                  estimated profile metrics.
 *
 * operational_force_transitions   sequential dscan deltas inside an
 *                                  incident: tackle→capital,
 *                                  kite→brawl, logistics spike, etc.
 *
 * system_threat_surface gains capital / logistics / doctrine_threat
 * sub-scores so map overlays can show fleet-character not just
 * frequency.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE operational_force_compositions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                cluster_id BIGINT UNSIGNED NULL,
                incident_id BIGINT UNSIGNED NULL,
                dscan_snapshot_id VARCHAR(64) NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                snapshot_at DATETIME NULL,
                primary_doctrine_name VARCHAR(191) NULL,
                primary_doctrine_id BIGINT UNSIGNED NULL,
                doctrine_confidence DECIMAL(5,4) NULL,
                doctrine_match_pct DECIMAL(5,4) NULL,
                doctrine_secondary_json TEXT NULL,
                ship_breakdown_json TEXT NOT NULL,
                ship_total INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_pilot_count INT UNSIGNED NULL,
                estimated_logistics_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_tackle_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_dps_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_bomber_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_ewar_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_command_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_capital_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_super_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_logistics_ratio DECIMAL(5,4) NULL,
                estimated_tackle_ratio DECIMAL(5,4) NULL,
                projection_strength ENUM('local','sub_regional','regional','strategic','coalition') NOT NULL DEFAULT 'local',
                mobility ENUM('static','slow','medium','fast','warp_capable') NOT NULL DEFAULT 'medium',
                brawl_range ENUM('close','mid','long','sniper','mixed','unknown') NOT NULL DEFAULT 'unknown',
                evidence_json TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_comp_snap (cluster_id, dscan_snapshot_id),
                INDEX idx_ofc_bloc (viewer_bloc_id, snapshot_at),
                INDEX idx_ofc_incident (incident_id),
                INDEX idx_ofc_doctrine (primary_doctrine_id),
                INDEX idx_ofc_super (estimated_super_count),
                INDEX idx_ofc_capital (estimated_capital_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE operational_force_transitions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                incident_id BIGINT UNSIGNED NOT NULL,
                from_composition_id BIGINT UNSIGNED NOT NULL,
                to_composition_id BIGINT UNSIGNED NOT NULL,
                from_at DATETIME NOT NULL,
                to_at DATETIME NOT NULL,
                transition_type ENUM(
                    'tackle_to_capital','subcap_to_capital',
                    'kite_to_brawl','brawl_to_kite',
                    'bomber_reinforcement','logistics_spike',
                    'doctrine_swap','escalation','de_escalation','unknown'
                ) NOT NULL DEFAULT 'unknown',
                ship_count_delta INT NOT NULL DEFAULT 0,
                logistics_delta INT NOT NULL DEFAULT 0,
                tackle_delta INT NOT NULL DEFAULT 0,
                capital_delta INT NOT NULL DEFAULT 0,
                duration_seconds INT UNSIGNED NOT NULL,
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_trans (incident_id, from_composition_id, to_composition_id),
                INDEX idx_oft_type (transition_type, from_at),
                INDEX idx_oft_incident (incident_id, from_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE system_threat_surface
              ADD COLUMN capital_score DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER dscan_score,
              ADD COLUMN logistics_score DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER capital_score,
              ADD COLUMN doctrine_threat_score DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER logistics_score,
              ADD COLUMN escalation_propensity_score DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER doctrine_threat_score,
              ADD COLUMN mobility_profile ENUM('static','slow','medium','fast','mixed') NULL AFTER escalation_propensity_score
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE system_threat_surface
              DROP COLUMN capital_score,
              DROP COLUMN logistics_score,
              DROP COLUMN doctrine_threat_score,
              DROP COLUMN escalation_propensity_score,
              DROP COLUMN mobility_profile
        SQL);
        DB::statement('DROP TABLE IF EXISTS operational_force_transitions');
        DB::statement('DROP TABLE IF EXISTS operational_force_compositions');
    }
};
