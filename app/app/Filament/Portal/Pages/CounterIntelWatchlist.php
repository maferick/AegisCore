<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/counter-intel/watchlist — bloc-scoped Counter-Intel
 * watchlist queue. Shows every ci_watchlist_entries row for the
 * viewer's bloc, grouped by status, with the latest Phase 1 band
 * pulled from ci_render_diagnostics for fast triage.
 *
 * Read-only listing; status changes happen on the character lookup
 * card via the embedded Livewire watchlist button.
 */
class CounterIntelWatchlist extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationLabel = 'Counter-Intel Watchlist';

    protected static string|UnitEnum|null $navigationGroup = 'Watchlist & verified';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Counter-Intel Watchlist';

    protected static ?string $slug = 'counter-intel/watchlist';

    protected string $view = 'filament.portal.pages.counter-intel-watchlist';

    public ?string $statusFilter = null;

    public function mount(): void
    {
        $this->statusFilter = (string) request()->query('status', '');
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

        $q = DB::table('ci_watchlist_entries AS w')
            ->leftJoin('esi_entity_names AS en', function ($j): void {
                $j->on('en.entity_id', '=', 'w.character_id')->where('en.category', 'character');
            })
            ->leftJoin('ci_render_diagnostics AS d', function ($j) use ($blocId): void {
                $j->on('d.character_id', '=', 'w.character_id')
                    ->where('d.viewer_bloc_id', '=', $blocId);
            })
            ->leftJoin('users AS u', 'u.id', '=', 'w.added_by_user_id')
            ->where('w.viewer_bloc_id', $blocId);

        if ($this->statusFilter !== null && $this->statusFilter !== '') {
            $q->where('w.status', $this->statusFilter);
        }

        $rows = $q->select(
            'w.id', 'w.character_id', 'w.status', 'w.reason', 'w.notes',
            'w.priority_override', 'w.created_at', 'w.last_status_change_at',
            'en.name AS character_name',
            'd.rendered_band', 'd.confidence', 'd.flag_count', 'd.note_count', 'd.declared_in_bloc',
            'u.name AS added_by_name',
        )
            ->orderByRaw("FIELD(w.status, 'escalated', 'watching', 'cleared', 'archived')")
            ->orderByDesc('w.last_status_change_at')
            ->limit(500)
            ->get();

        $counts = DB::table('ci_watchlist_entries')
            ->where('viewer_bloc_id', $blocId)
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'status')
            ->all();

        return [
            'no_bloc' => false,
            'viewer_bloc_id' => $blocId,
            'viewer_bloc_name' => $blocName,
            'rows' => $rows,
            'counts' => $counts,
            'status_filter' => $this->statusFilter,
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
