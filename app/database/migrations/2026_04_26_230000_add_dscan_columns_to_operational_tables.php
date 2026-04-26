<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.4 — propagate dscan evidence into operational aggregations.
 *
 * Quality promotion rules (in compute):
 *   intel with system + character + dscan       → cluster quality+1
 *   dscan with ≥ 50 hostile ships               → severity 'escalation'
 *                                                 candidate when paired
 *                                                 with at least one
 *                                                 cluster
 *   intel with only system + dscan              → cluster quality 'normal'
 *                                                 minimum
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE operational_hostile_clusters
              ADD COLUMN has_dscan TINYINT(1) NOT NULL DEFAULT 0 AFTER quality,
              ADD COLUMN dscan_total_ships INT UNSIGNED NULL AFTER has_dscan,
              ADD COLUMN dscan_snapshot_ids_json TEXT NULL AFTER dscan_total_ships,
              ADD INDEX idx_ohc_dscan (has_dscan, dscan_total_ships)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE operational_incidents
              ADD COLUMN has_dscan TINYINT(1) NOT NULL DEFAULT 0 AFTER severity,
              ADD COLUMN dscan_total_ships INT UNSIGNED NULL AFTER has_dscan,
              ADD INDEX idx_oi_dscan (has_dscan, dscan_total_ships)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE system_threat_surface
              ADD COLUMN dscan_score DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER corridor_centrality_score
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE operational_hostile_clusters
              DROP INDEX idx_ohc_dscan,
              DROP COLUMN has_dscan,
              DROP COLUMN dscan_total_ships,
              DROP COLUMN dscan_snapshot_ids_json
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE operational_incidents
              DROP INDEX idx_oi_dscan,
              DROP COLUMN has_dscan,
              DROP COLUMN dscan_total_ships
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE system_threat_surface
              DROP COLUMN dscan_score
        SQL);
    }
};
