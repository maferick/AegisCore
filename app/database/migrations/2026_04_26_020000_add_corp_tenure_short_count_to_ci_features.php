<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stabilization for the corp_hopping signal.
 *
 * The raw `corp_tenure_min_days` reads 0 for the dominant ESI artifact
 * where character_corporation_history has back-to-back rows with
 * start_date == end_date. That made every long-history pilot look like
 * a fast-churn alt, driving the corp_hopping flag to 47% population
 * after the first run.
 *
 * `corp_tenure_short_count` is the count of distinct corp memberships
 * with a tenure of 1-30 days (i.e. real short stays, not ESI noise).
 * Phase 1 dossier rendering uses this for the corp_hopping flag.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_features_rolling
              ADD COLUMN corp_tenure_short_count INT UNSIGNED NULL AFTER corp_tenure_stdev_days,
              ADD INDEX idx_ci_feat_short_count (corp_tenure_short_count)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_features_rolling
              DROP INDEX idx_ci_feat_short_count,
              DROP COLUMN corp_tenure_short_count
        SQL);
    }
};
