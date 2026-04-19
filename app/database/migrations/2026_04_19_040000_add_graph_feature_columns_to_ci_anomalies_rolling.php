<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel Dossier — Commit D: wire Step 2 graph features into the
 * anomaly row so the dashboard + dossier can render them without an
 * extra join. anomalies.py now copies these values from
 * ci_character_graph_features_rolling into the anomaly row and folds
 * seed_neighbors_count + bridge_internal_pct into review_priority_score.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_anomalies_rolling
              ADD COLUMN ring_id BIGINT NULL AFTER bridge_anomaly_pct,
              ADD COLUMN ring_size INT UNSIGNED NOT NULL DEFAULT 0 AFTER ring_id,
              ADD COLUMN bridge_internal_pct DECIMAL(5,4) NULL AFTER ring_size,
              ADD COLUMN seed_neighbors_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER bridge_internal_pct,
              ADD COLUMN seed_neighbors_max_score DECIMAL(5,4) NULL AFTER seed_neighbors_count,
              ADD COLUMN is_seed TINYINT(1) NOT NULL DEFAULT 0 AFTER seed_neighbors_max_score
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_anomalies_rolling
              DROP COLUMN ring_id,
              DROP COLUMN ring_size,
              DROP COLUMN bridge_internal_pct,
              DROP COLUMN seed_neighbors_count,
              DROP COLUMN seed_neighbors_max_score,
              DROP COLUMN is_seed
        SQL);
    }
};
