<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 7 — historical priors + calibration run tables
|--------------------------------------------------------------------------
|
| Two new tables:
|
|   character_role_historical_priors — per-character per-role prior
|     computed nightly from the last 90 days of feature rows.
|     Unique on (character_id, role_key). Rows with fewer than
|     5 observed battles are deleted so cold-start pilots don't
|     get flimsy priors.
|
|   battle_role_calibration_runs — audit of Spec 5 inference
|     accuracy against donor attestations, keyed by weight_version
|     and role_key. Operator reads these to decide whether to
|     promote a weight_version to production and which roles to
|     flip from `ui_state = 'beta'` to `'production'`.
|
| The `score_class` CHECK was already relaxed to admit 'historical'
| by Spec 5's schema prep migration (2026_04_18_030000), so the
| Python scorer can start writing historical score rows the moment
| a weight_version has historical coefficients seeded.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // Relax battle_role_scoring_weights.score_class CHECK to admit
        // 'historical'. (Spec 5 relaxed the analogous CHECK on the
        // scores table; the weights table's CHECK was a separate one
        // we didn't touch at the time.)
        DB::statement('ALTER TABLE battle_role_scoring_weights DROP CONSTRAINT chk_brsw_score_class');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_role_scoring_weights
                ADD CONSTRAINT chk_brsw_score_class
                    CHECK (score_class IN ('structural','temporal','hull','historical','final'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE character_role_historical_priors (
                character_id       BIGINT NOT NULL,
                role_key           VARCHAR(32) NOT NULL,
                prior_value        DECIMAL(5,4) NOT NULL,
                battles_observed   INT NOT NULL,
                window_start       DATE NOT NULL,
                window_end         DATE NOT NULL,
                source_breakdown   JSON NULL,
                computed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (character_id, role_key),
                KEY idx_crhp_role (role_key, prior_value DESC),
                KEY idx_crhp_window (window_end),

                CONSTRAINT fk_crhp_role FOREIGN KEY (role_key) REFERENCES battle_roles (role_key),
                CONSTRAINT chk_crhp_prior CHECK (prior_value >= 0.0000 AND prior_value <= 1.0000),
                CONSTRAINT chk_crhp_battles CHECK (battles_observed >= 5),
                CONSTRAINT chk_crhp_json CHECK (source_breakdown IS NULL OR JSON_VALID(source_breakdown))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE battle_role_calibration_runs (
                run_id             BIGINT NOT NULL AUTO_INCREMENT,
                weight_version     INT NOT NULL,
                role_key           VARCHAR(32) NOT NULL,
                evaluated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                attestation_count  INT NOT NULL,
                correct_count      INT NOT NULL,
                accuracy           DECIMAL(5,4) NOT NULL,
                threshold          DECIMAL(5,4) NOT NULL,
                passed             TINYINT(1) NOT NULL,
                notes              TEXT NULL,

                PRIMARY KEY (run_id),
                KEY idx_brcr_weight_role (weight_version, role_key, evaluated_at DESC),

                CONSTRAINT fk_brcr_weight FOREIGN KEY (weight_version) REFERENCES battle_role_weight_versions (weight_version),
                CONSTRAINT fk_brcr_role FOREIGN KEY (role_key) REFERENCES battle_roles (role_key),
                CONSTRAINT chk_brcr_accuracy CHECK (accuracy >= 0.0000 AND accuracy <= 1.0000),
                CONSTRAINT chk_brcr_counts CHECK (correct_count >= 0 AND correct_count <= attestation_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_role_calibration_runs');
        DB::statement('DROP TABLE IF EXISTS character_role_historical_priors');
        DB::statement('ALTER TABLE battle_role_scoring_weights DROP CONSTRAINT chk_brsw_score_class');
        DB::statement(<<<'SQL'
            ALTER TABLE battle_role_scoring_weights
                ADD CONSTRAINT chk_brsw_score_class
                    CHECK (score_class IN ('structural','temporal','hull','final'))
        SQL);
    }
};
