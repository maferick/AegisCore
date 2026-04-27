<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/director — director strategic surface.
 *
 * Coalition behavior, deployments, doctrine shifts, escalation
 * trends, strategic heatmap. Last 30 days by default. Designed for
 * a director deciding what to do this week / month.
 */
class DirectorStrategic extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Director strategic';

    protected static string|UnitEnum|null $navigationGroup = 'Strategic';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Director strategic view';

    protected static ?string $slug = 'intelligence/director';

    protected string $view = 'filament.portal.pages.director-strategic';

    public int $days = 30;

    public function mount(): void
    {
        $d = (int) request()->query('days', 30);
        $this->days = max(7, min(180, $d));
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $latestProfileEnd = DB::table('alliance_operational_profiles')
            ->where('viewer_bloc_id', $blocId)
            ->max('window_end');

        $coalitions = DB::table('coalition_behavior_comparisons')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end', $latestProfileEnd)
            ->orderByDesc('incident_count')
            ->get();

        $alliances = DB::table('alliance_operational_profiles')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end', $latestProfileEnd)
            ->whereIn('confidence', ['medium', 'high'])
            ->orderByDesc('incident_count')
            ->limit(30)
            ->get();

        $doctrineEvents = DB::table('doctrine_evolution_events')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end', '>=', now()->subDays($this->days)->toDateString())
            ->orderByDesc('magnitude')
            ->limit(30)
            ->get();

        $escalationTrend = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where('start_at', '>=', now()->subDays($this->days))
            ->selectRaw("DATE(start_at) AS d, severity, COUNT(*) AS n")
            ->groupBy('d', 'severity')
            ->orderBy('d')
            ->get();

        $heatTiers = DB::table('system_threat_surface')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end_date', '=', DB::raw("(SELECT MAX(window_end_date) FROM system_threat_surface WHERE viewer_bloc_id = $blocId)"))
            ->select('tier', DB::raw('COUNT(*) AS n'))
            ->groupBy('tier')
            ->pluck('n', 'tier')
            ->all();

        $deployments = DB::table('operational_corridors')
            ->where('viewer_bloc_id', $blocId)
            ->where('route_classification', 'deployment_migration')
            ->where('last_seen_at', '>=', now()->subDays($this->days))
            ->orderByDesc('transition_count')
            ->limit(15)
            ->get();

        // Verdict — strategic snapshot for the director.
        $strategic  = (int) ($heatTiers['strategic']  ?? 0);
        $hot        = (int) ($heatTiers['hot']        ?? 0);
        $contested  = (int) ($heatTiers['contested']  ?? 0);
        $migrations = count($deployments);
        $details = [];
        if ($strategic > 0) $details[] = "{$strategic} strategic-tier system" . ($strategic === 1 ? '' : 's');
        if ($hot > 0)       $details[] = "{$hot} hot-tier system" . ($hot === 1 ? '' : 's');
        if ($contested > 0) $details[] = "{$contested} contested system" . ($contested === 1 ? '' : 's');
        if ($migrations > 0) $details[] = "{$migrations} active deployment-migration corridor" . ($migrations === 1 ? '' : 's');
        $severity = 'info';
        $headline = 'Theatre quiet — no tier-flagged systems';
        if ($strategic > 0) {
            $severity = 'critical';
            $headline = 'Strategic-tier activity active';
        } elseif ($hot > 0 || $migrations >= 3) {
            $severity = 'elevated';
            $headline = 'Theatre warm — hostile pressure observed';
        } elseif ($contested > 0 || $migrations > 0) {
            $severity = 'warning';
            $headline = 'Theatre contested in places';
        }
        $verdict = ['severity' => $severity, 'headline' => $headline, 'details' => $details];

        return [
            'no_bloc' => false,
            'days' => $this->days,
            'verdict' => $verdict,
            'latest_profile_end' => $latestProfileEnd,
            'coalitions' => $coalitions,
            'alliances' => $alliances,
            'doctrine_events' => $doctrineEvents,
            'escalation_trend' => $escalationTrend,
            'heat_tiers' => $heatTiers,
            'deployments' => $deployments,
        ];
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
