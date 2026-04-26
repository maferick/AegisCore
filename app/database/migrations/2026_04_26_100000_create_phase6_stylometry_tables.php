<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — typed-text stylometry / writing fingerprint scaffold.
 *
 * NOT proof of identity. Treated as a low-confidence supporting
 * signal only — see ADR-0010 for the hard rules around minimum
 * sample size, confidence reporting, and refusal to display raw
 * private messages broadly.
 *
 * Tables:
 *   eve_log_author_style_profiles — per (actor, window) feature vector
 *   eve_log_author_style_edges    — pairwise similarity edges
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_author_style_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_name VARCHAR(120) NOT NULL,
                window_start DATETIME NOT NULL,
                window_end DATETIME NOT NULL,
                message_count INT UNSIGNED NOT NULL DEFAULT 0,
                avg_message_length DECIMAL(8,2) NULL,
                punctuation_vector_json TEXT NULL,
                casing_vector_json TEXT NULL,
                spacing_vector_json TEXT NULL,
                abbreviation_vector_json TEXT NULL,
                language_hint_json TEXT NULL,
                common_terms_json TEXT NULL,
                short_command_usage_json TEXT NULL,
                cadence_hour_histogram_json TEXT NULL,
                stylometry_hash CHAR(64) NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'insufficient',
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_actor_window (actor_name, window_end),
                INDEX idx_hash (stylometry_hash),
                INDEX idx_actor (actor_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE eve_log_author_style_edges (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_a VARCHAR(120) NOT NULL,
                actor_b VARCHAR(120) NOT NULL,
                window_start DATETIME NOT NULL,
                window_end DATETIME NOT NULL,
                similarity_score DECIMAL(6,4) NOT NULL,
                shared_features_json TEXT NULL,
                sample_size_a INT UNSIGNED NOT NULL,
                sample_size_b INT UNSIGNED NOT NULL,
                confidence ENUM('insufficient','low','medium','high') NOT NULL DEFAULT 'insufficient',
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_pair_window (actor_a, actor_b, window_end),
                INDEX idx_score (similarity_score),
                INDEX idx_actor_a (actor_a, window_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS eve_log_author_style_edges');
        DB::statement('DROP TABLE IF EXISTS eve_log_author_style_profiles');
    }
};
