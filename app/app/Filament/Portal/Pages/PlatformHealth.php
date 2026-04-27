<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/platform-health — observability dashboard for
 * the intelligence platform itself.
 *
 * Reads compute_lane_metrics, compute_run_log, system_quality_events,
 * plus rolling snapshots of freshness distribution per surface.
 *
 * Read-only. Re-run pipelines via the existing make targets — this
 * page surfaces state, not actions.
 */
class PlatformHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Platform health';

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 13;

    protected static ?string $title = 'Platform health';

    protected static ?string $slug = 'intelligence/platform-health';

    protected string $view = 'filament.portal.pages.platform-health';

    public function ackQualityEvent(int $id): void
    {
        $blocId = $this->resolveViewerBlocId();
        DB::table('system_quality_events')
            ->where('id', $id)
            ->where(function ($q) use ($blocId) {
                $q->whereNull('viewer_bloc_id')->orWhere('viewer_bloc_id', $blocId);
            })
            ->update([
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => Auth::id(),
            ]);
    }

    public function resolveQualityEvent(int $id): void
    {
        $blocId = $this->resolveViewerBlocId();
        DB::table('system_quality_events')
            ->where('id', $id)
            ->where(function ($q) use ($blocId) {
                $q->whereNull('viewer_bloc_id')->orWhere('viewer_bloc_id', $blocId);
            })
            ->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => Auth::id(),
            ]);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $lanes = DB::table('compute_lane_metrics')
            ->orderByRaw("FIELD(lane_state,'failed','starved','backlogged','degraded','healthy')")
            ->orderBy('lane')
            ->get();

        // Mark lanes with zero historical compute_run_log entries as
        // 'not_instrumented' so the dashboard doesn't report them as
        // healthy when really there's nothing reporting in.
        $totalRunsByLane = DB::table('compute_run_log')
            ->groupBy('lane')
            ->selectRaw('lane, COUNT(*) AS n')
            ->pluck('n', 'lane')
            ->all();
        foreach ($lanes as $l) {
            $l->total_runs = (int) ($totalRunsByLane[$l->lane] ?? 0);
            if ($l->total_runs === 0) {
                $l->lane_state = 'not_instrumented';
            }
        }

        $recentRuns = DB::table('compute_run_log')
            ->where(function ($q) use ($blocId) {
                $q->whereNull('viewer_bloc_id')->orWhere('viewer_bloc_id', $blocId);
            })
            ->orderByDesc('compute_started_at')
            ->limit(40)
            ->get();

        $openCircuits = DB::table('compute_circuit_state')
            ->whereIn('state', ['open', 'half_open'])
            ->orderByDesc('opened_at')
            ->get();

        $topRetryPipelines = DB::table('compute_run_log')
            ->where('compute_started_at', '>=', now()->subHours(24))
            ->where('retry_count', '>', 0)
            ->groupBy('pipeline', 'retry_reason')
            ->selectRaw("pipeline, retry_reason, COUNT(*) AS retried_runs, SUM(retry_count) AS total_retries, SUM(status='succeeded') AS succeeded_after_retry, SUM(status='failed') AS failed_after_retry")
            ->orderByDesc('total_retries')
            ->limit(15)
            ->get();

        // Per-lane aggregates: retry rate + open circuits + last failure.
        $laneRetry = [];
        foreach ($lanes as $l) {
            $row = DB::table('compute_run_log')
                ->where('lane', $l->lane)
                ->where('compute_started_at', '>=', now()->subHours(24))
                ->selectRaw('SUM(retry_count) AS retries, SUM(retry_count > 0) AS retried_runs, COUNT(*) AS runs')
                ->first();
            $openForLane = DB::table('compute_circuit_state')
                ->where('lane', $l->lane)
                ->whereIn('state', ['open', 'half_open'])
                ->count();
            $laneRetry[$l->lane] = [
                'retries' => (int) ($row->retries ?? 0),
                'retried_runs' => (int) ($row->retried_runs ?? 0),
                'runs_24h' => (int) ($row->runs ?? 0),
                'open_circuits' => $openForLane,
            ];
        }

        $runningTooLong = DB::table('compute_run_log')
            ->where('status', 'running')
            ->where('compute_started_at', '<=', now()->subMinutes(15))
            ->orderBy('compute_started_at')
            ->limit(20)
            ->get();

        $qualityEvents = DB::table('system_quality_events')
            ->where(function ($q) use ($blocId) {
                $q->whereNull('viewer_bloc_id')->orWhere('viewer_bloc_id', $blocId);
            })
            ->whereNull('resolved_at')
            ->orderByRaw("FIELD(severity,'critical','elevated','warning','info')")
            ->orderByDesc('detected_at')
            ->limit(40)
            ->get();

        // Per-surface freshness rollup (re-using Phase 4.9 columns).
        // Surface health is derived from the age of the *newest* row,
        // not a ratio over the full history. Surfaces like incidents
        // and corridors accumulate sealed historical records — 90%
        // expired only means 90% of rows are 90 days old, not that
        // the writer pipeline is broken. The operator wants "is the
        // latest data fresh enough to act on?", which the
        // newest-row-age comparison answers directly. We still surface
        // the per-state tally for context.
        $ttlConfig = $this->loadFreshnessTtl();
        $surfaces = [
            'alert' => ['table' => 'strategic_alerts', 'ts' => 'detected_at', 'ttl' => 'alert'],
            'digest' => ['table' => 'daily_operational_digest', 'ts' => 'generated_at', 'ttl' => 'digest'],
            'narrative' => ['table' => 'incident_narratives', 'ts' => 'computed_at', 'ttl' => 'narrative'],
            'incident' => ['table' => 'operational_incidents', 'ts' => 'end_at', 'ttl' => 'incident'],
            'corridor' => ['table' => 'operational_corridors', 'ts' => 'last_seen_at', 'ttl' => 'corridor'],
            'force_composition' => ['table' => 'operational_force_compositions', 'ts' => 'snapshot_at', 'ttl' => 'force_composition'],
            'alliance_profile' => ['table' => 'alliance_operational_profiles', 'ts' => 'computed_at', 'ttl' => 'alliance_profile'],
            'threat_surface' => ['table' => 'system_threat_surface', 'ts' => 'computed_at', 'ttl' => 'threat_surface'],
            'doctrine_evolution' => ['table' => 'doctrine_evolution_events', 'ts' => 'computed_at', 'ttl' => 'doctrine_evolution'],
        ];
        $surfaceFreshness = [];
        $surfaceHealth = [];
        foreach ($surfaces as $surface => $cfg) {
            $tally = DB::table($cfg['table'])
                ->where('viewer_bloc_id', $blocId)
                ->groupBy('freshness_state')
                ->selectRaw('freshness_state, COUNT(*) AS n')
                ->pluck('n', 'freshness_state')
                ->all();
            $total = array_sum($tally);
            if ($total === 0) continue;

            // Newest-row age in hours. Skips NULL ts (unbounded /
            // never-finished rows like open incidents) so an open
            // incident with NULL end_at doesn't poison the metric.
            $newestTs = DB::table($cfg['table'])
                ->where('viewer_bloc_id', $blocId)
                ->whereNotNull($cfg['ts'])
                ->max($cfg['ts']);

            [$freshH, $agingH, $staleH] = $ttlConfig[$cfg['ttl']] ?? [24, 168, 720];
            $health = 'failed';
            $newestAgeH = null;
            if ($newestTs !== null) {
                $newestAgeH = max(0, (int) round((time() - strtotime((string) $newestTs)) / 3600, 2));
                if ($newestAgeH <= $freshH) $health = 'healthy';
                elseif ($newestAgeH <= $agingH) $health = 'aging';
                elseif ($newestAgeH <= $staleH) $health = 'stale';
                else $health = 'failed';
            }

            $surfaceFreshness[$surface] = $tally + [
                'total' => $total,
                'newest_age_h' => $newestAgeH,
                'fresh_ttl_h' => $freshH,
            ];
            $surfaceHealth[$surface] = $health;
        }

        // Ingest + parser pulses.
        $ingestPulse = $this->ingestPulse();
        $parserPulse = $this->parserPulse();
        $alertPulse = DB::table('strategic_alerts')
            ->where('viewer_bloc_id', $blocId)
            ->where('detected_at', '>=', now()->subHours(24))
            ->count();
        $incidentPulse = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where('start_at', '>=', now()->subHours(24))
            ->count();

        // One-line verdict for the top of the page so an operator
        // gets the answer at a glance instead of scanning eight
        // tables. Severity is the worst signal across:
        //   - failed/stale surfaces
        //   - open circuits
        //   - running-too-long pipelines
        //   - open critical/elevated quality events
        $verdict = $this->buildVerdict(
            $surfaceHealth, $openCircuits, $runningTooLong, $qualityEvents,
        );

        // Split lanes by instrumentation status so the dashboard can
        // collapse the wall of repeated "no instrumented pipelines
        // reporting" rows into a single summary line.
        $instrumentedLanes = [];
        $notInstrumentedLanes = [];
        foreach ($lanes as $l) {
            if (($l->lane_state ?? null) === 'not_instrumented') {
                $notInstrumentedLanes[] = $l;
            } else {
                $instrumentedLanes[] = $l;
            }
        }

        return [
            'no_bloc' => false,
            'verdict' => $verdict,
            'lanes' => $lanes,  // legacy alias, blade migrates off
            'instrumented_lanes' => $instrumentedLanes,
            'not_instrumented_lanes' => $notInstrumentedLanes,
            'recent_runs' => $recentRuns,
            'running_too_long' => $runningTooLong,
            'quality_events' => $qualityEvents,
            'surface_freshness' => $surfaceFreshness,
            'surface_health' => $surfaceHealth,
            'ingest_pulse' => $ingestPulse,
            'parser_pulse' => $parserPulse,
            'alert_pulse' => $alertPulse,
            'incident_pulse' => $incidentPulse,
            'open_circuits' => $openCircuits,
            'lane_retry' => $laneRetry,
            'top_retry_pipelines' => $topRetryPipelines,
        ];
    }

    /**
     * Compose the page-level verdict — `severity`, `headline`, and
     * a short `details` list. Severity bands map to UI colour
     * (info=neutral, warning=yellow, elevated=orange, critical=red).
     *
     * @param  array<string, string>  $surfaceHealth
     * @return array{severity:string, headline:string, details:array<int, string>}
     */
    private function buildVerdict(array $surfaceHealth, $openCircuits, $runningTooLong, $qualityEvents): array
    {
        $failedSurfaces = array_keys(array_filter($surfaceHealth, fn ($s) => $s === 'failed'));
        $staleSurfaces  = array_keys(array_filter($surfaceHealth, fn ($s) => $s === 'stale'));
        $agingSurfaces  = array_keys(array_filter($surfaceHealth, fn ($s) => $s === 'aging'));

        $criticalQE = collect($qualityEvents)->filter(fn ($e) => $e->severity === 'critical')->count();
        $elevatedQE = collect($qualityEvents)->filter(fn ($e) => $e->severity === 'elevated')->count();

        $details = [];
        if ($failedSurfaces) {
            $details[] = count($failedSurfaces) . ' surface' . (count($failedSurfaces) === 1 ? '' : 's')
                . ' failed: ' . implode(', ', array_map(fn ($s) => str_replace('_', ' ', $s), $failedSurfaces));
        }
        if ($staleSurfaces) {
            $details[] = count($staleSurfaces) . ' stale: ' . implode(', ', array_map(fn ($s) => str_replace('_', ' ', $s), $staleSurfaces));
        }
        if (count($openCircuits) > 0) {
            $details[] = count($openCircuits) . ' open circuit' . (count($openCircuits) === 1 ? '' : 's');
        }
        if (count($runningTooLong) > 0) {
            $details[] = count($runningTooLong) . ' pipeline' . (count($runningTooLong) === 1 ? '' : 's') . ' running > 15m';
        }
        if ($criticalQE > 0) {
            $details[] = "{$criticalQE} critical quality event" . ($criticalQE === 1 ? '' : 's');
        }
        if ($elevatedQE > 0) {
            $details[] = "{$elevatedQE} elevated quality event" . ($elevatedQE === 1 ? '' : 's');
        }

        // Severity ladder (worst wins).
        $severity = 'info';
        $headline = 'System healthy';
        if ($criticalQE > 0 || count($openCircuits) > 0) {
            $severity = 'critical';
            $headline = 'System degraded — needs attention';
        } elseif (count($failedSurfaces) > 0 || count($runningTooLong) > 0) {
            $severity = 'elevated';
            $headline = count($failedSurfaces) === 1
                ? 'One surface failed'
                : 'System degraded';
        } elseif ($elevatedQE > 0 || count($staleSurfaces) > 0) {
            $severity = 'warning';
            $headline = 'Stale data observed';
        } elseif (count($agingSurfaces) > 0) {
            $severity = 'info';
            $headline = 'System healthy — some surfaces aging';
        }

        return ['severity' => $severity, 'headline' => $headline, 'details' => $details];
    }

    /**
     * Load the per-surface freshness TTL ladder from
     * config/intel_ttl.json (single source of truth shared with the
     * Python freshness compute). Returns [fresh_h, aging_h, stale_h]
     * per surface key.
     *
     * @return array<string, array{0:float,1:float,2:float}>
     */
    private function loadFreshnessTtl(): array
    {
        $path = config_path('intel_ttl.json');
        if (! is_readable($path)) return [];
        $blob = json_decode((string) file_get_contents($path), true);
        return is_array($blob['freshness_ttl_hours'] ?? null)
            ? $blob['freshness_ttl_hours']
            : [];
    }

    private function ingestPulse(): array
    {
        // Window keys on created_at (ingest time) to match parserPulse.
        // Re-uploads of historical logs would otherwise undercount n_24h
        // and make the parse-error ratio meaningless. `latest` still
        // reports the newest event_timestamp seen so operators can
        // notice when fresh data has stopped arriving.
        try {
            $row = DB::table('eve_log_events')
                ->where('created_at', '>=', now()->subHours(24))
                ->selectRaw('COUNT(*) AS n, MAX(event_timestamp) AS latest')
                ->first();
            return [
                'n_24h' => (int) ($row->n ?? 0),
                'latest' => $row->latest ?? null,
            ];
        } catch (\Throwable) {
            return ['n_24h' => 0, 'latest' => null];
        }
    }

    private function parserPulse(): array
    {
        // All windows key on created_at (ingest time), not
        // event_timestamp. Re-uploads of historical logs land rows now
        // with old event_timestamps; mixing the two time bases produces
        // bogus rates (errors_24h / events_24h > 1).
        //
        // error_rate uses status='open' — rows already upgraded via
        // eve-log:retry-parse-errors (status in retried/reparsed_ok/
        // dismissed) shouldn't count against current parser quality,
        // they're audit trail. resolved_24h is reported separately so
        // the operator still sees the queue-drain progress.
        try {
            $openErrors = DB::table('eve_log_parse_errors')
                ->where('created_at', '>=', now()->subHours(24))
                ->where('status', 'open')
                ->count();
            $resolvedErrors = DB::table('eve_log_parse_errors')
                ->where('created_at', '>=', now()->subHours(24))
                ->whereIn('status', ['retried', 'reparsed_ok', 'dismissed'])
                ->count();
            $events = DB::table('eve_log_events')
                ->where('created_at', '>=', now()->subHours(24))
                ->count();
            $unknown = DB::table('eve_log_events')
                ->where('created_at', '>=', now()->subHours(24))
                ->where('event_type', 'unknown')
                ->count();
            $topReasons = DB::table('eve_log_parse_errors')
                ->where('created_at', '>=', now()->subHours(24))
                ->where('status', 'open')
                ->groupBy('reason')
                ->selectRaw('reason, COUNT(*) AS n')
                ->orderByDesc('n')
                ->limit(5)
                ->get();
            return [
                'errors_24h' => $openErrors,
                'resolved_24h' => $resolvedErrors,
                'events_24h' => $events,
                'unknown_24h' => $unknown,
                'error_rate' => $events > 0 ? round($openErrors / $events, 4) : 0,
                'unknown_rate' => $events > 0 ? round($unknown / $events, 4) : 0,
                'top_reasons' => $topReasons,
            ];
        } catch (\Throwable) {
            return ['errors_24h' => 0, 'resolved_24h' => 0, 'events_24h' => 0,
                    'unknown_24h' => 0, 'error_rate' => 0, 'unknown_rate' => 0,
                    'top_reasons' => collect()];
        }
    }

    private function resolveViewerBlocId(): ?int
    {
        $override = request()->query('bloc_id');
        if ($override !== null && ctype_digit((string) $override)) {
            return (int) $override;
        }
        $user = Auth::user();
        if ($user === null) return null;
        $char = $user->characters()->first();
        if ($char === null || ! $char->alliance_id) return null;
        $blocId = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('entity_id', $char->alliance_id)
            ->where('is_active', 1)
            ->value('bloc_id');
        return $blocId ? (int) $blocId : null;
    }
}
