<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/operations/incidents/{incident} — single-incident dossier.
 *
 * Surfaces the full operational story: fused timeline, member
 * hostile clusters, member timeline events, top reporters, top
 * named hostiles, linked battle/theater, severity reasoning.
 *
 * Read-only. Bloc-scoped: incidents are only visible to the bloc
 * that materialised them.
 */
class OperationsIncidentDossier extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $slug = 'operations/incidents/{incident}';

    protected static ?string $title = 'Operations · incident dossier';

    protected string $view = 'filament.portal.pages.operations-incident-dossier';

    public int $incidentId;

    public function mount(int $incident): void
    {
        $this->incidentId = $incident;
    }

    public function getTitle(): string
    {
        return "Operations · incident #{$this->incidentId}";
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $incident = DB::table('operational_incidents')
            ->where('id', $this->incidentId)
            ->where('viewer_bloc_id', $blocId)
            ->first();
        if ($incident === null) {
            return ['not_found' => true, 'incident_id' => $this->incidentId];
        }

        $clusterIds = json_decode($incident->hostile_cluster_ids_json ?? '[]', true) ?: [];
        $timelineIds = json_decode($incident->timeline_event_ids_json ?? '[]', true) ?: [];
        $signalTypes = json_decode($incident->signal_types_json ?? '[]', true) ?: [];

        $clusters = $clusterIds
            ? DB::table('operational_hostile_clusters')->whereIn('id', $clusterIds)->orderBy('start_at')->get()
            : collect();
        $timelineEvents = $timelineIds
            ? DB::table('operational_timeline_events')->whereIn('id', $timelineIds)->orderBy('event_timestamp')->get()
            : collect();

        // Aggregate top reporters + named hostiles across all clusters.
        $reporters = [];
        $namedHostileIds = [];
        $namedHostileNames = [];
        foreach ($clusters as $c) {
            $names = json_decode($c->involved_character_names_json ?? '[]', true) ?: [];
            $ids = json_decode($c->involved_character_ids_json ?? '[]', true) ?: [];
            foreach ($ids as $i => $id) {
                $namedHostileIds[(int) $id] = ($namedHostileIds[(int) $id] ?? 0) + 1;
                if (isset($names[$i])) {
                    $namedHostileNames[(int) $id] = (string) $names[$i];
                }
            }
        }
        arsort($namedHostileIds);
        $topHostiles = [];
        foreach (array_slice($namedHostileIds, 0, 30, true) as $cid => $cnt) {
            $topHostiles[] = [
                'character_id' => $cid,
                'name' => $namedHostileNames[$cid] ?? "#{$cid}",
                'mentions' => $cnt,
            ];
        }

        // Fused chronological strip: each cluster + each timeline event
        // sorted by time, with type tags.
        $strip = [];
        foreach ($clusters as $c) {
            $strip[] = [
                'kind' => 'hostile_cluster',
                'ts' => (string) $c->start_at,
                'system' => $c->primary_system_name,
                'detail' => "Cluster · {$c->reporter_count} reporter(s), {$c->report_count} report(s) ({$c->confidence}/{$c->quality})",
                'object_id' => (int) $c->id,
            ];
        }
        foreach ($timelineEvents as $t) {
            $strip[] = [
                'kind' => $t->timeline_type,
                'ts' => (string) $t->event_timestamp,
                'system' => $t->solar_system_name,
                'detail' => $t->event_summary,
                'object_id' => (int) $t->id,
            ];
        }
        usort($strip, fn ($a, $b) => strcmp($a['ts'], $b['ts']));

        // Battle linkage detail.
        $battleSummary = null;
        if ($incident->battle_id) {
            $battleSummary = DB::table('battle_theaters')
                ->where('id', $incident->battle_id)
                ->select('id', 'primary_system_id', 'start_time', 'end_time')
                ->first();
        }

        return [
            'no_bloc' => false,
            'not_found' => false,
            'incident' => $incident,
            'signal_types' => $signalTypes,
            'clusters' => $clusters,
            'timeline_events' => $timelineEvents,
            'top_hostiles' => $topHostiles,
            'fused_strip' => $strip,
            'battle' => $battleSummary,
            'evidence_json' => json_decode($incident->evidence_json ?? '{}', true) ?: [],
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
