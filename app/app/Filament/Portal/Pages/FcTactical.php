<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/fc — FC tactical surface.
 *
 * Active threats, current corridors, nearby hostile activity,
 * operational tempo. Last 6 hours by default. Designed for an FC
 * deciding what to do in the next 30 minutes.
 */
class FcTactical extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'FC tactical';

    protected static string|UnitEnum|null $navigationGroup = 'Daily ops';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'FC tactical view';

    protected static ?string $slug = 'intelligence/fc';

    protected string $view = 'filament.portal.pages.fc-tactical';

    public int $hours = 6;

    public function mount(): void
    {
        $h = (int) request()->query('hours', 6);
        $this->hours = max(1, min(48, $h));
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $cutoff = now()->subHours($this->hours);

        $activeIncidents = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where('start_at', '>=', $cutoff)
            ->whereIn('severity', ['tactical', 'strategic', 'escalation', 'coalition_level'])
            ->orderByRaw("FIELD(severity,'coalition_level','escalation','strategic','tactical')")
            ->orderByDesc('start_at')
            ->limit(30)
            ->get();

        $openAlerts = DB::table('strategic_alerts')
            ->where('viewer_bloc_id', $blocId)
            ->whereNull('dismissed_at')
            ->whereIn('severity', ['urgent', 'elevated'])
            ->where('detected_at', '>=', now()->subHours($this->hours * 2))
            ->orderByRaw("FIELD(severity,'urgent','elevated','watch','info')")
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get();

        $hotCorridors = DB::table('operational_corridors')
            ->where('viewer_bloc_id', $blocId)
            ->where('last_seen_at', '>=', now()->subHours(48))
            ->whereIn('route_classification', ['reinforcement', 'escalation_path', 'staging'])
            ->orderByDesc('transition_count')
            ->limit(20)
            ->get();

        $hottestSystems = DB::table('system_threat_surface')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end_date', '>=', now()->subDays(7)->toDateString())
            ->whereIn('tier', ['hot', 'strategic'])
            ->orderByDesc('threat_score')
            ->limit(15)
            ->get();

        $recentClusters = DB::table('operational_hostile_clusters')
            ->where('viewer_bloc_id', $blocId)
            ->where('start_at', '>=', $cutoff)
            ->whereIn('quality', ['strong', 'strategic'])
            ->orderByDesc('start_at')
            ->limit(20)
            ->get();

        $tempo = DB::table('system_response_times')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end_date', '>=', now()->subDays(2)->toDateString())
            ->whereNotNull('intel_to_combat_median_seconds')
            ->where('intel_to_combat_count', '>=', 3)
            ->orderBy('intel_to_combat_median_seconds')
            ->limit(10)
            ->get();

        return [
            'no_bloc' => false,
            'hours' => $this->hours,
            'active_incidents' => $activeIncidents,
            'open_alerts' => $openAlerts,
            'hot_corridors' => $hotCorridors,
            'hottest_systems' => $hottestSystems,
            'recent_clusters' => $recentClusters,
            'tempo' => $tempo,
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
