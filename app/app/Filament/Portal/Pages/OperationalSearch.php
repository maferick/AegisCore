<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/search — cross-intelligence operational search.
 *
 * Single search box. Resolves the term against:
 *   - solar systems (operational_incidents.primary_system_name)
 *   - alliances    (alliance_operational_profiles)
 *   - doctrines    (auto_doctrines.canonical_name)
 *   - ship types   (ref_item_types.name)
 *   - corridors    (operational_corridors.from/to_system_name)
 *   - operators    (operator_operational_fingerprints.character_name)
 *   - battles      (battle_theaters.id)
 *
 * Bloc-scoped on every result set.
 */
class OperationalSearch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Operational search';

    protected static string|UnitEnum|null $navigationGroup = 'Lookups';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Operational search';

    protected static ?string $slug = 'intelligence/search';

    protected string $view = 'filament.portal.pages.operational-search';

    public string $q = '';

    public function mount(): void
    {
        $this->q = trim((string) request()->query('q', ''));
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $term = trim($this->q);
        if ($term === '' || strlen($term) < 2) {
            return ['no_bloc' => false, 'q' => $term, 'empty' => true];
        }

        $like = '%' . $term . '%';
        $exact = $term;

        // Systems (latest 30d incidents on the system).
        $systems = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where('primary_system_name', 'LIKE', $like)
            ->where('start_at', '>=', now()->subDays(60))
            ->select('primary_system_id', 'primary_system_name', DB::raw('COUNT(*) AS incident_count'),
                DB::raw('MAX(start_at) AS last_seen'),
                DB::raw("SUM(CASE WHEN severity IN ('strategic','escalation','coalition_level') THEN 1 ELSE 0 END) AS strategic_count"))
            ->groupBy('primary_system_id', 'primary_system_name')
            ->orderByDesc('incident_count')
            ->limit(15)
            ->get();

        // Alliances.
        $alliances = DB::table('alliance_operational_profiles')
            ->where('viewer_bloc_id', $blocId)
            ->where('alliance_name', 'LIKE', $like)
            ->orderByDesc('window_end')
            ->orderByDesc('incident_count')
            ->limit(15)
            ->get();

        // Doctrines.
        $doctrines = DB::table('auto_doctrines')
            ->where('canonical_name', 'LIKE', $like)
            ->where('is_active', 1)
            ->orderByDesc('observation_count')
            ->limit(15)
            ->get();

        // Ship types.
        $ships = DB::table('ref_item_types')
            ->where('name', 'LIKE', $like)
            ->whereIn('group_id', [
                25, 26, 27, 28, 31, 324, 358, 419, 420, 463,
                485, 547, 1538, 883, 659, 30, 832, 1527,
                831, 894, 541, 834, 833, 893, 540, 1201,
                963, 1305,
            ])
            ->orderBy('name')
            ->limit(20)
            ->get();

        // Corridors.
        $corridors = DB::table('operational_corridors')
            ->where('viewer_bloc_id', $blocId)
            ->where(function ($qb) use ($like) {
                $qb->where('from_system_name', 'LIKE', $like)
                   ->orWhere('to_system_name', 'LIKE', $like);
            })
            ->orderByDesc('transition_count')
            ->limit(15)
            ->get();

        // Operators.
        $operators = DB::table('operator_operational_fingerprints')
            ->where('viewer_bloc_id', $blocId)
            ->where('character_name', 'LIKE', $like)
            ->orderByDesc('window_end')
            ->orderByDesc('cluster_appearances')
            ->limit(15)
            ->get();

        // Recent incidents matching the term (system or summary).
        $incidents = DB::table('operational_incidents')
            ->where('viewer_bloc_id', $blocId)
            ->where(function ($qb) use ($like) {
                $qb->where('primary_system_name', 'LIKE', $like)
                   ->orWhere('timeline_summary', 'LIKE', $like);
            })
            ->orderByDesc('start_at')
            ->limit(15)
            ->get();

        // Battles.
        $battles = collect();
        if (ctype_digit($exact)) {
            $battles = DB::table('battle_theaters')
                ->where('id', (int) $exact)
                ->limit(1)
                ->get();
        }

        return [
            'no_bloc' => false,
            'q' => $term,
            'empty' => false,
            'systems' => $systems,
            'alliances' => $alliances,
            'doctrines' => $doctrines,
            'ships' => $ships,
            'corridors' => $corridors,
            'operators' => $operators,
            'incidents' => $incidents,
            'battles' => $battles,
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
