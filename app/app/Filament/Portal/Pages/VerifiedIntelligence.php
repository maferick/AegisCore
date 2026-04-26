<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/verified — human-verified intelligence layer.
 *
 * Surfaces analyst-pinned incidents, curated summaries, strategic
 * events, and analyst notes. Authoritative for human-curated
 * judgments — sits above the automated aggregation surfaces.
 */
class VerifiedIntelligence extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Verified intelligence';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Verified intelligence';

    protected static ?string $slug = 'intelligence/verified';

    protected string $view = 'filament.portal.pages.verified-intelligence';

    public string $kindFilter = '';
    public string $sigFilter = '';
    public bool $pinnedOnly = false;

    public string $newKind = 'curated_summary';
    public string $newTitle = '';
    public string $newBody = '';
    public string $newSignificance = 'medium';
    public ?int $newRelatedIncidentId = null;
    public ?int $newRelatedAlertId = null;

    public function mount(): void
    {
        $this->kindFilter = (string) request()->query('kind', '');
        $this->sigFilter = (string) request()->query('sig', '');
        $this->pinnedOnly = (bool) request()->query('pinned');
    }

    public function create(): void
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) return;
        if (trim($this->newTitle) === '') return;
        $allowedKinds = ['pinned_incident', 'curated_summary', 'strategic_event',
                         'analyst_note', 'narrative_override'];
        if (! in_array($this->newKind, $allowedKinds, true)) return;
        $allowedSig = ['low', 'medium', 'high', 'coalition_level'];
        if (! in_array($this->newSignificance, $allowedSig, true)) {
            $this->newSignificance = 'medium';
        }

        DB::table('verified_intelligence_items')->insert([
            'viewer_bloc_id' => $blocId,
            'item_kind' => $this->newKind,
            'title' => mb_substr($this->newTitle, 0, 220),
            'body_md' => $this->newBody !== '' ? $this->newBody : null,
            'related_incident_id' => $this->newRelatedIncidentId,
            'related_alert_id' => $this->newRelatedAlertId,
            'pinned' => $this->newKind === 'pinned_incident' ? 1 : 0,
            'strategic_significance' => $this->newSignificance,
            'created_by_user_id' => Auth::id(),
            'verified_by_user_id' => Auth::id(),
            'verified_at' => now(),
            'created_at' => now(),
        ]);

        $this->reset(['newTitle', 'newBody', 'newRelatedIncidentId', 'newRelatedAlertId']);
    }

    public function togglePin(int $id): void
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) return;
        $cur = DB::table('verified_intelligence_items')
            ->where('id', $id)
            ->where('viewer_bloc_id', $blocId)
            ->value('pinned');
        DB::table('verified_intelligence_items')
            ->where('id', $id)
            ->where('viewer_bloc_id', $blocId)
            ->update(['pinned' => $cur ? 0 : 1]);
    }

    public function publish(int $id): void
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) return;
        DB::table('verified_intelligence_items')
            ->where('id', $id)
            ->where('viewer_bloc_id', $blocId)
            ->update([
                'published' => 1,
                'verified_by_user_id' => Auth::id(),
                'verified_at' => now(),
            ]);
    }

    public function delete(int $id): void
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) return;
        DB::table('verified_intelligence_items')
            ->where('id', $id)
            ->where('viewer_bloc_id', $blocId)
            ->delete();
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $q = DB::table('verified_intelligence_items')->where('viewer_bloc_id', $blocId);
        if ($this->kindFilter !== '') $q->where('item_kind', $this->kindFilter);
        if ($this->sigFilter !== '') $q->where('strategic_significance', $this->sigFilter);
        if ($this->pinnedOnly) $q->where('pinned', 1);
        $items = $q->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $kindCounts = DB::table('verified_intelligence_items')
            ->where('viewer_bloc_id', $blocId)
            ->groupBy('item_kind')
            ->selectRaw('item_kind, COUNT(*) AS n')
            ->pluck('n', 'item_kind')
            ->all();

        return [
            'no_bloc' => false,
            'items' => $items,
            'kind_counts' => $kindCounts,
            'kind_filter' => $this->kindFilter,
            'sig_filter' => $this->sigFilter,
            'pinned_only' => $this->pinnedOnly,
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
