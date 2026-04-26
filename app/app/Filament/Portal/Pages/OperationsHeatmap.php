<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/operations/heatmap — system threat-surface visualisation.
 *
 * Reads `system_threat_surface` (Phase 4.4F) and `operational_corridors`
 * (Phase 4.4C). Renders a region-grouped heatmap (tier-coloured) plus
 * the top hostile travel lanes. Lightweight — operates on the
 * pre-aggregated tables, never raw events.
 */
class OperationsHeatmap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Operations Heatmap';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Operations Heatmap';

    protected static ?string $slug = 'operations/heatmap';

    protected string $view = 'filament.portal.pages.operations-heatmap';

    public string $regionFilter = '';
    public string $tierFilter = '';

    public function mount(): void
    {
        $this->regionFilter = (string) request()->query('region', '');
        $this->tierFilter = (string) request()->query('tier', '');
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }
        $blocName = DB::table('coalition_blocs')->where('id', $blocId)->value('display_name')
            ?? "Bloc #{$blocId}";

        // Latest threat-surface rows.
        $latestDate = DB::table('system_threat_surface')
            ->where('viewer_bloc_id', $blocId)
            ->max('window_end_date');

        $q = DB::table('system_threat_surface AS s')
            ->leftJoin('ref_regions AS r', 'r.id', '=', 's.region_id')
            ->where('s.viewer_bloc_id', $blocId)
            ->where('s.window_end_date', $latestDate);
        if ($this->regionFilter !== '') {
            $q->where('r.name', 'like', '%' . $this->regionFilter . '%');
        }
        if ($this->tierFilter !== '') {
            $q->where('s.tier', $this->tierFilter);
        }
        $rows = $q->orderByDesc('s.threat_score')
            ->limit(500)
            ->get([
                's.solar_system_id', 's.solar_system_name', 's.region_id',
                's.threat_score', 's.tier',
                's.hostile_cluster_score', 's.escalation_score',
                's.battle_linkage_score', 's.density_score',
                's.reliability_score', 's.corridor_centrality_score',
                'r.name AS region_name',
            ]);

        $byTier = DB::table('system_threat_surface')
            ->where('viewer_bloc_id', $blocId)
            ->where('window_end_date', $latestDate)
            ->groupBy('tier')
            ->select('tier', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'tier')->all();

        $byRegion = DB::table('system_threat_surface AS s')
            ->leftJoin('ref_regions AS r', 'r.id', '=', 's.region_id')
            ->where('s.viewer_bloc_id', $blocId)
            ->where('s.window_end_date', $latestDate)
            ->groupBy('r.name')
            ->select('r.name AS region_name',
                DB::raw('COUNT(*) AS n'),
                DB::raw('MAX(s.threat_score) AS top'),
                DB::raw('SUM(CASE WHEN s.tier IN (\'strategic\',\'hot\') THEN 1 ELSE 0 END) AS hotcount'))
            ->orderByDesc('top')
            ->limit(40)
            ->get();

        // Top corridors for the same bloc.
        $corridors = DB::table('operational_corridors')
            ->where('viewer_bloc_id', $blocId)
            ->orderByDesc('transition_count')
            ->limit(30)
            ->get([
                'from_system_name', 'to_system_name',
                'transition_count', 'distinct_characters',
                'avg_transition_seconds', 'confidence',
            ]);

        return [
            'no_bloc' => false,
            'viewer_bloc_id' => $blocId,
            'viewer_bloc_name' => $blocName,
            'latest_date' => $latestDate,
            'rows' => $rows,
            'by_tier' => $byTier,
            'by_region' => $byRegion,
            'corridors' => $corridors,
            'region_filter' => $this->regionFilter,
            'tier_filter' => $this->tierFilter,
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
