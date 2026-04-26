<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9B materialization audit — add missing dashboard indexes.
 *
 * EXPLAIN findings (2026-04-27 audit):
 *
 *   strategic_alerts open-status query (StrategicAlerts page,
 *   FcTactical, dossier alert lookup) was a full table scan
 *   filtered by `dismissed_at IS NULL AND analyst_status NOT IN
 *   (...)` then sorted by severity + detected_at. Add a composite
 *   covering the lifecycle filter + sort order.
 *
 *   doctrine_evolution_events magnitude sort was full-scan + filesort.
 *   Small table now (83 rows) but TrustOverview / DirectorStrategic
 *   both order by magnitude — add idx (viewer_bloc_id, magnitude DESC)
 *   so it stays cheap as the corpus grows.
 *
 *   alliance_operational_profiles: filesort on incident_count for
 *   DirectorStrategic. Add (viewer_bloc_id, window_end, incident_count)
 *   so the LIMIT 30 join becomes index-only.
 *
 *   compute_run_log dashboard query: PlatformHealth recent-runs uses
 *   ORDER BY compute_started_at DESC. Already indexed on bloc+time but
 *   the WHERE-bloc-IS-NULL-OR-bloc=X branch picks idx_crl_bloc + filesort.
 *   Add a plain time index for the OR fallback.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE strategic_alerts
              ADD INDEX idx_sa_dashboard_open
                (viewer_bloc_id, dismissed_at, analyst_status, severity, detected_at)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctrine_evolution_events
              ADD INDEX idx_dee_magnitude (viewer_bloc_id, magnitude)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE alliance_operational_profiles
              ADD INDEX idx_aop_director (viewer_bloc_id, window_end, incident_count)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE compute_run_log
              ADD INDEX idx_crl_recent (compute_started_at)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE strategic_alerts DROP INDEX idx_sa_dashboard_open');
        DB::statement('ALTER TABLE doctrine_evolution_events DROP INDEX idx_dee_magnitude');
        DB::statement('ALTER TABLE alliance_operational_profiles DROP INDEX idx_aop_director');
        DB::statement('ALTER TABLE compute_run_log DROP INDEX idx_crl_recent');
    }
};
