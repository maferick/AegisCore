<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/operations/timeline — operational incident chronology.
 *
 * Lists operational_incidents for the viewer's bloc, filterable by
 * severity / incident_type / time range / system / linked-battle.
 * Each card summarises the fused signal mix; click-through to
 * /portal/operations/incidents/{id} for the full dossier.
 */
class OperationsTimeline extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'Operations Timeline';

    protected static string|UnitEnum|null $navigationGroup = 'Daily ops';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Operations Timeline';

    protected static ?string $slug = 'operations/timeline';

    protected string $view = 'filament.portal.pages.operations-timeline';

    public string $severityFilter = '';
    public string $typeFilter = '';
    public string $systemFilter = '';
    public string $sinceHours = '168';
    public bool $linkedOnly = false;

    public function mount(): void
    {
        $this->severityFilter = (string) request()->query('severity', '');
        $this->typeFilter = (string) request()->query('type', '');
        $this->systemFilter = (string) request()->query('system', '');
        $this->sinceHours = (string) request()->query('since_hours', '168');
        $this->linkedOnly = (bool) request()->query('linked_only', false);
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

        $sinceHours = max(1, (int) ($this->sinceHours !== '' ? $this->sinceHours : 168));
        if ($sinceHours > 24 * 365) $sinceHours = 168;

        $q = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where('start_at', '>=', now()->subHours($sinceHours));

        if ($this->severityFilter !== '') {
            $q->where('severity', $this->severityFilter);
        }
        if ($this->typeFilter !== '') {
            $q->where('incident_type', $this->typeFilter);
        }
        if ($this->systemFilter !== '') {
            $q->where('primary_system_name', 'like', '%' . $this->systemFilter . '%');
        }
        if ($this->linkedOnly) {
            $q->whereNotNull('battle_id');
        }

        $rows = $q->orderByDesc('start_at')
            ->limit(300)
            ->get([
                'id', 'incident_type', 'severity', 'confidence', 'start_at', 'end_at',
                'primary_system_id', 'primary_system_name', 'primary_region_id',
                'battle_id', 'theater_id', 'participant_estimate',
                'signal_types_json', 'timeline_summary',
                'hostile_cluster_ids_json', 'timeline_event_ids_json',
            ]);

        // Aggregate counts for filter chips (entire window, ignoring
        // active filters — chips show "all"/per-severity totals).
        $countsBySev = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where('start_at', '>=', now()->subHours($sinceHours))
            ->groupBy('severity')
            ->select('severity', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'severity')->all();
        $countsByType = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where('start_at', '>=', now()->subHours($sinceHours))
            ->groupBy('incident_type')
            ->select('incident_type', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'incident_type')->all();

        // Verdict — operator's first-glance answer for the
        // selected window. Severity ladder mirrors the rest of
        // the dashboards so colour pattern transfers.
        $coalition = (int) ($countsBySev['coalition_level'] ?? 0);
        $escalation = (int) ($countsBySev['escalation'] ?? 0);
        $strategic = (int) ($countsBySev['strategic'] ?? 0);
        $tactical  = (int) ($countsBySev['tactical']  ?? 0);
        $details = [];
        if ($coalition > 0)  $details[] = "{$coalition} coalition-level";
        if ($escalation > 0) $details[] = "{$escalation} escalation";
        if ($strategic > 0)  $details[] = "{$strategic} strategic";
        if ($tactical > 0)   $details[] = "{$tactical} tactical";
        if ($coalition > 0 || $escalation > 0) {
            $verdict = ['severity' => 'critical', 'headline' => 'Coalition-level / escalation incidents in window', 'details' => $details];
        } elseif ($strategic > 0) {
            $verdict = ['severity' => 'elevated', 'headline' => 'Strategic-severity incidents in window', 'details' => $details];
        } elseif ($tactical > 0) {
            $verdict = ['severity' => 'warning', 'headline' => 'Tactical-severity incidents in window', 'details' => $details];
        } else {
            $verdict = ['severity' => 'info', 'headline' => 'Quiet window — no notable incidents', 'details' => []];
        }

        return [
            'no_bloc' => false,
            'viewer_bloc_id' => $blocId,
            'viewer_bloc_name' => $blocName,
            'verdict' => $verdict,
            'rows' => $rows,
            'counts_by_severity' => $countsBySev,
            'counts_by_type' => $countsByType,
            'severity_filter' => $this->severityFilter,
            'type_filter' => $this->typeFilter,
            'system_filter' => $this->systemFilter,
            'since_hours' => $sinceHours,
            'linked_only' => $this->linkedOnly,
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
