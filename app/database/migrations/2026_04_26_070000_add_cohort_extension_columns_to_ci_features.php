<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0008 Phase 2.5 — extend the k-NN cohort feature vector with
 * timezone centroid + graph-density z-scores. See
 * docs/adr/0008-ci-knn-cohort-extension.md for design.
 *
 * `tz_centroid_sin/cos` encode the circular mean of the existing
 * hour_histogram. The (sin, cos) pair lets L2 distance treat
 * 23:00 ↔ 01:00 as adjacent (otherwise the cohort would split EU/AU
 * pilots whose play windows straddle midnight UTC).
 *
 * `pagerank_z/betweenness_z` are z-normalised versions of existing
 * properties — populated by a follow-up pass in similarity.py.
 *
 * Doctrine match rate is deferred to Phase 2.6 (separate migration)
 * because doctrine compute requires the role-inference pipeline to
 * settle first.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_features_rolling
              ADD COLUMN tz_centroid_sin DECIMAL(6,5) NULL AFTER hour_histogram,
              ADD COLUMN tz_centroid_cos DECIMAL(6,5) NULL AFTER tz_centroid_sin,
              ADD COLUMN pagerank_z DECIMAL(8,4) NULL AFTER tz_centroid_cos,
              ADD COLUMN betweenness_z DECIMAL(8,4) NULL AFTER pagerank_z
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_features_rolling
              DROP COLUMN tz_centroid_sin,
              DROP COLUMN tz_centroid_cos,
              DROP COLUMN pagerank_z,
              DROP COLUMN betweenness_z
        SQL);
    }
};
