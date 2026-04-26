<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 — intelligence freshness across every operator surface.
 *
 * Adds three uniform columns to each intel artifact:
 *
 *   freshness_state ENUM('fresh','aging','stale','expired')
 *   source_window_start DATETIME NULL
 *   source_window_end   DATETIME NULL
 *
 * Some tables already carry start/end columns (operational_incidents,
 * operational_hostile_clusters); we still add the explicit
 * source_window_* pair so renderers can rely on a uniform schema
 * across surfaces. The freshness compute pass re-derives the state
 * from a per-surface TTL ladder; this column lets cheap reads avoid
 * re-evaluating each render.
 */
return new class extends Migration {
    public function up(): void
    {
        $surfaces = [
            'daily_operational_digest',
            'strategic_alerts',
            'operational_incidents',
            'operational_hostile_clusters',
            'operational_corridors',
            'operational_force_compositions',
            'system_threat_surface',
            'alliance_operational_profiles',
            'coalition_behavior_comparisons',
            'incident_narratives',
            'doctrine_evolution_events',
            'verified_intelligence_items',
        ];
        foreach ($surfaces as $t) {
            DB::statement("ALTER TABLE {$t}
                ADD COLUMN freshness_state ENUM('fresh','aging','stale','expired') NOT NULL DEFAULT 'fresh',
                ADD COLUMN source_window_start DATETIME NULL,
                ADD COLUMN source_window_end DATETIME NULL,
                ADD INDEX idx_{$t}_fresh (freshness_state)");
        }
    }

    public function down(): void
    {
        $surfaces = [
            'daily_operational_digest',
            'strategic_alerts',
            'operational_incidents',
            'operational_hostile_clusters',
            'operational_corridors',
            'operational_force_compositions',
            'system_threat_surface',
            'alliance_operational_profiles',
            'coalition_behavior_comparisons',
            'incident_narratives',
            'doctrine_evolution_events',
            'verified_intelligence_items',
        ];
        foreach ($surfaces as $t) {
            DB::statement("ALTER TABLE {$t}
                DROP INDEX idx_{$t}_fresh,
                DROP COLUMN source_window_end,
                DROP COLUMN source_window_start,
                DROP COLUMN freshness_state");
        }
    }
};
