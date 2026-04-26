<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.6 — coalition / doctrine behavior intelligence.
 *
 * alliance_operational_profiles    per (alliance, window) operational
 *                                   style + metrics.
 *
 * coalition_behavior_comparisons    per (bloc, window) bloc-level
 *                                   roll-up across alliances.
 *
 * doctrine_evolution_events         adoption, abandonment, sudden
 *                                   shifts in alliance doctrine mix.
 *
 * operator_operational_fingerprints per-character non-identity
 *                                   operational style profile.
 *
 * operational_corridors gains route_classification + staging_score
 * to surface staging / reinforcement / escalation routes.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE alliance_operational_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                alliance_id BIGINT UNSIGNED NOT NULL,
                alliance_name VARCHAR(150) NULL,
                bloc_id BIGINT UNSIGNED NULL,
                window_start DATE NOT NULL,
                window_end DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                incident_count INT UNSIGNED NOT NULL DEFAULT 0,
                cluster_count INT UNSIGNED NOT NULL DEFAULT 0,
                composition_count INT UNSIGNED NOT NULL DEFAULT 0,
                doctrine_distribution_json TEXT NULL,
                escalation_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                disengagement_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                avg_response_minutes DECIMAL(8,2) NULL,
                avg_fleet_size DECIMAL(8,2) NULL,
                avg_capital_presence DECIMAL(5,2) NOT NULL DEFAULT 0,
                avg_super_presence DECIMAL(5,2) NOT NULL DEFAULT 0,
                avg_logistics_ratio DECIMAL(5,4) NOT NULL DEFAULT 0,
                avg_tackle_ratio DECIMAL(5,4) NOT NULL DEFAULT 0,
                avg_mobility_score DECIMAL(5,4) NULL,
                primary_mobility ENUM('static','slow','medium','fast','mixed') NULL,
                primary_projection ENUM('local','sub_regional','regional','strategic','coalition') NULL,
                primary_brawl_range ENUM('close','mid','long','sniper','mixed','unknown') NULL,
                avg_engagement_minutes DECIMAL(8,2) NULL,
                strategic_system_share DECIMAL(5,4) NOT NULL DEFAULT 0,
                corridor_usage_json TEXT NULL,
                operational_style ENUM(
                    'heavy_brawl','fast_response','capital_heavy','harassment',
                    'corridor_control','structure_warfare','defensive',
                    'opportunistic','escalation_prone','undetermined'
                ) NOT NULL DEFAULT 'undetermined',
                style_confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'insufficient',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_aop (viewer_bloc_id, alliance_id, window_end, window_days),
                INDEX idx_aop_bloc (bloc_id, window_end),
                INDEX idx_aop_style (operational_style),
                INDEX idx_aop_alliance (alliance_id, window_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE coalition_behavior_comparisons (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                bloc_id BIGINT UNSIGNED NOT NULL,
                bloc_code VARCHAR(32) NULL,
                bloc_display_name VARCHAR(120) NULL,
                window_start DATE NOT NULL,
                window_end DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                alliance_count INT UNSIGNED NOT NULL DEFAULT 0,
                incident_count INT UNSIGNED NOT NULL DEFAULT 0,
                escalation_count INT UNSIGNED NOT NULL DEFAULT 0,
                avg_response_minutes DECIMAL(8,2) NULL,
                avg_fleet_size DECIMAL(8,2) NULL,
                escalation_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                doctrine_diversity DECIMAL(5,4) NOT NULL DEFAULT 0,
                strategic_density DECIMAL(5,4) NOT NULL DEFAULT 0,
                capital_usage_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
                avg_logistics_ratio DECIMAL(5,4) NOT NULL DEFAULT 0,
                primary_mobility ENUM('static','slow','medium','fast','mixed') NULL,
                operational_footprint_systems INT UNSIGNED NOT NULL DEFAULT 0,
                top_doctrines_json TEXT NULL,
                top_systems_json TEXT NULL,
                style_distribution_json TEXT NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'insufficient',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_cbc (viewer_bloc_id, bloc_id, window_end, window_days),
                INDEX idx_cbc_bloc (bloc_id, window_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE doctrine_evolution_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                alliance_id BIGINT UNSIGNED NOT NULL,
                alliance_name VARCHAR(150) NULL,
                bloc_id BIGINT UNSIGNED NULL,
                event_type ENUM(
                    'adoption','abandonment','sudden_increase','sudden_decrease',
                    'kite_to_brawl','brawl_to_kite','capital_emergence',
                    'logistics_heavy_shift','anti_cap_emergence','meta_shift'
                ) NOT NULL,
                doctrine_id BIGINT UNSIGNED NULL,
                doctrine_name VARCHAR(191) NULL,
                window_end DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 14,
                prior_share DECIMAL(5,4) NULL,
                current_share DECIMAL(5,4) NULL,
                delta DECIMAL(6,4) NULL,
                magnitude DECIMAL(6,4) NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'low',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_dee (viewer_bloc_id, alliance_id, event_type, doctrine_id, window_end),
                INDEX idx_dee_alliance (alliance_id, window_end),
                INDEX idx_dee_type (event_type, window_end),
                INDEX idx_dee_bloc (bloc_id, window_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE operator_operational_fingerprints (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                character_id BIGINT UNSIGNED NOT NULL,
                character_name VARCHAR(150) NULL,
                alliance_id BIGINT UNSIGNED NULL,
                window_start DATE NOT NULL,
                window_end DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 30,
                incident_count INT UNSIGNED NOT NULL DEFAULT 0,
                cluster_appearances INT UNSIGNED NOT NULL DEFAULT 0,
                escalation_appearances INT UNSIGNED NOT NULL DEFAULT 0,
                disengagement_appearances INT UNSIGNED NOT NULL DEFAULT 0,
                rapid_escalation_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                heavy_logistics_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                conservative_disengage_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                bait_engagement_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                corridor_camp_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                response_tempo_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                primary_style ENUM(
                    'rapid_escalator','heavy_logi_anchor','conservative_disengager',
                    'bait_specialist','corridor_camper','fast_responder',
                    'generalist','undetermined'
                ) NOT NULL DEFAULT 'undetermined',
                style_confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'insufficient',
                evidence_json TEXT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_oof (viewer_bloc_id, character_id, window_end, window_days),
                INDEX idx_oof_char (character_id, window_end),
                INDEX idx_oof_alliance (alliance_id, window_end),
                INDEX idx_oof_style (primary_style)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE operational_corridors
              ADD COLUMN route_classification ENUM(
                  'staging','reinforcement','escalation_path',
                  'deployment_migration','transit','unclassified'
              ) NOT NULL DEFAULT 'unclassified' AFTER confidence,
              ADD COLUMN staging_score DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER route_classification,
              ADD COLUMN reinforcement_score DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER staging_score,
              ADD COLUMN escalation_path_score DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER reinforcement_score,
              ADD INDEX idx_oc_route (route_classification)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE operational_corridors
              DROP INDEX idx_oc_route,
              DROP COLUMN escalation_path_score,
              DROP COLUMN reinforcement_score,
              DROP COLUMN staging_score,
              DROP COLUMN route_classification
        SQL);
        DB::statement('DROP TABLE IF EXISTS operator_operational_fingerprints');
        DB::statement('DROP TABLE IF EXISTS doctrine_evolution_events');
        DB::statement('DROP TABLE IF EXISTS coalition_behavior_comparisons');
        DB::statement('DROP TABLE IF EXISTS alliance_operational_profiles');
    }
};
