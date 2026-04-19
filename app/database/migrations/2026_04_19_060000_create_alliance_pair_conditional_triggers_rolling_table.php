<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bloc Intelligence — conditional alignment triggers.
 *
 * For every observed pair (A, B), record the delta in same-side rate
 * conditioned on the presence of a third alliance T. delta > 0 means
 * "A and B ally more often when T is around than when T isn't". Top
 * triggers per pair (by |delta|) feed the UI's "conditionally aligned
 * when X is present" narrative.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE alliance_pair_conditional_triggers_rolling (
                alliance_a_id BIGINT UNSIGNED NOT NULL,
                alliance_b_id BIGINT UNSIGNED NOT NULL,
                trigger_alliance_id BIGINT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,

                n_obs_with_trigger DECIMAL(12,4) NOT NULL DEFAULT 0,
                n_obs_without_trigger DECIMAL(12,4) NOT NULL DEFAULT 0,
                same_side_rate_with DECIMAL(5,4) NULL,
                same_side_rate_without DECIMAL(5,4) NULL,

                -- conditional_delta = rate_with - rate_without (-1..+1).
                conditional_delta DECIMAL(5,4) NULL,
                confidence DECIMAL(5,4) NULL,

                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (alliance_a_id, alliance_b_id, trigger_alliance_id, window_end_date, window_days),
                KEY ix_apct_pair (alliance_a_id, alliance_b_id, window_end_date, conditional_delta),
                KEY ix_apct_trigger (trigger_alliance_id, window_end_date, conditional_delta),
                KEY ix_apct_strong_positive (window_end_date, conditional_delta),
                CONSTRAINT chk_apct_order CHECK (alliance_a_id < alliance_b_id),
                CONSTRAINT chk_apct_trigger_distinct CHECK (trigger_alliance_id <> alliance_a_id AND trigger_alliance_id <> alliance_b_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS alliance_pair_conditional_triggers_rolling');
    }
};
