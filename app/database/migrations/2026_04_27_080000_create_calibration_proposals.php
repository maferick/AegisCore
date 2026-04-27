<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V1 §14.6 — calibration_proposals.
 *
 * One row per proposed threshold change. Append-only by
 * convention. status transitions:
 *
 *   proposed → reviewed → adopted
 *   proposed → reviewed → rejected
 *   adopted → superseded   (future change replaces this one)
 *
 * Reviewer + decision tracking matches ADR 0011 Rule 4 (two
 * operators sign off on every adopted proposal).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE calibration_proposals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                proposal_date DATE NOT NULL,
                surface VARCHAR(60) NOT NULL,
                field VARCHAR(120) NOT NULL,
                prior_value VARCHAR(120) NULL,
                proposed_value VARCHAR(120) NOT NULL,
                evidence_json TEXT NULL,
                status ENUM(
                    'proposed','reviewed','adopted','rejected','superseded'
                ) NOT NULL DEFAULT 'proposed',
                reviewer_user_ids TEXT NULL,
                baseline_ref VARCHAR(120) NULL,
                rationale TEXT NULL,
                decided_at DATETIME NULL,
                superseded_by_id BIGINT UNSIGNED NULL,
                created_by_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_cp_surface (surface, proposal_date),
                INDEX idx_cp_status (status, proposal_date),
                INDEX idx_cp_field (field, surface)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS calibration_proposals');
    }
};
