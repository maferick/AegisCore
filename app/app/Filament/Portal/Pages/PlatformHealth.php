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

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

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
        $surfaceTables = [
            'alert' => 'strategic_alerts',
            'digest' => 'daily_operational_digest',
            'narrative' => 'incident_narratives',
            'incident' => 'operational_incidents',
            'corridor' => 'operational_corridors',
            'force_composition' => 'operational_force_compositions',
            'alliance_profile' => 'alliance_operational_profiles',
            'threat_surface' => 'system_threat_surface',
            'doctrine_evolution' => 'doctrine_evolution_events',
        ];
        $surfaceFreshness = [];
        $surfaceHealth = [];
        foreach ($surfaceTables as $surface => $table) {
            $tally = DB::table($table)
                ->where('viewer_bloc_id', $blocId)
                ->groupBy('freshness_state')
                ->selectRaw('freshness_state, COUNT(*) AS n')
                ->pluck('n', 'freshness_state')
                ->all();
            $total = array_sum($tally);
            if ($total === 0) continue;

            $fresh = $tally['fresh'] ?? 0;
            $expired = $tally['expired'] ?? 0;
            $freshRate = $fresh / $total;
            $expRate = $expired / $total;

            $health = 'healthy';
            if ($expRate >= 0.95) $health = 'failed';
            elseif ($freshRate < 0.05 && $expRate >= 0.50) $health = 'stale';
            elseif ($expRate >= 0.50) $health = 'degraded';
            elseif ($freshRate < 0.30) $health = 'backlogged';

            $surfaceFreshness[$surface] = $tally + ['total' => $total];
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

        return [
            'no_bloc' => false,
            'lanes' => $lanes,
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
        ];
    }

    private function ingestPulse(): array
    {
        try {
            $row = DB::table('eve_log_events')
                ->where('event_timestamp', '>=', now()->subHours(24))
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
        try {
            $errors = DB::table('eve_log_parse_errors')
                ->where('created_at', '>=', now()->subHours(24))
                ->count();
            $events = DB::table('eve_log_events')
                ->where('event_timestamp', '>=', now()->subHours(24))
                ->count();
            $unknown = DB::table('eve_log_events')
                ->where('event_timestamp', '>=', now()->subHours(24))
                ->where('event_type', 'unknown')
                ->count();
            return [
                'errors_24h' => $errors,
                'events_24h' => $events,
                'unknown_24h' => $unknown,
                'error_rate' => $events > 0 ? round($errors / $events, 4) : 0,
                'unknown_rate' => $events > 0 ? round($unknown / $events, 4) : 0,
            ];
        } catch (\Throwable) {
            return ['errors_24h' => 0, 'events_24h' => 0, 'unknown_24h' => 0,
                    'error_rate' => 0, 'unknown_rate' => 0];
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
