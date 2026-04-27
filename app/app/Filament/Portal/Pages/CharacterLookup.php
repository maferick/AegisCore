<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/characters/lookup — search any EVE character by name and
 * render the same profile card used on the operator's own
 * Dashboard (kills, history, hulls, graph insights, activity map).
 *
 * Gated at the portal (auth middleware), not to the viewer's own
 * linked characters — any authed operator can look up anyone. Data
 * shown is what we already publish on killmail detail pages (public
 * info) plus the counter-intel graph insights computed from public
 * killmails.
 */
class CharacterLookup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Character Lookup';

    protected static string|UnitEnum|null $navigationGroup = 'Lookups';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Character Lookup';

    protected static ?string $slug = 'characters/lookup';

    protected string $view = 'filament.portal.pages.character-lookup';

    public ?string $search = null;
    public ?int $characterId = null;

    public function mount(): void
    {
        $this->search = (string) request()->query('q', '');
        $aid = request()->query('cid');
        if ($aid !== null && ctype_digit((string) $aid)) {
            $this->characterId = (int) $aid;
        }
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $suggestions = [];
        if ($this->characterId === null && $this->search !== null && mb_strlen($this->search) >= 3) {
            $suggestions = DB::table('esi_entity_names')
                ->where('category', 'character')
                ->where('name', 'like', '%'.$this->search.'%')
                ->orderBy('name')
                ->limit(25)
                ->select('entity_id', 'name')
                ->get()
                ->map(fn ($r) => ['character_id' => (int) $r->entity_id, 'name' => (string) $r->name])
                ->all();
        }
        $card = null;
        if ($this->characterId !== null) {
            // Synthesize the same stub shape Dashboard::buildCharacterCard expects.
            $row = DB::table('esi_entity_names')
                ->where('entity_id', $this->characterId)
                ->where('category', 'character')
                ->first();
            $name = $row->name ?? null;
            // Look up current affiliation from character_corporation_history.
            $corpRow = DB::table('character_corporation_history')
                ->where('character_id', $this->characterId)
                ->where('is_deleted', 0)
                ->whereNull('end_date')
                ->orderByDesc('start_date')
                ->first();
            $corpId = $corpRow?->corporation_id;
            $allyId = null;
            if ($corpId !== null) {
                $allyRow = DB::table('corporation_alliance_history')
                    ->where('corporation_id', $corpId)
                    ->whereNull('end_date')
                    ->orderByDesc('start_date')
                    ->first();
                $allyId = $allyRow?->alliance_id;
            }
            $stub = (object) [
                'character_id' => $this->characterId,
                'character_name' => $name,
                'corporation_id' => $corpId ? (int) $corpId : null,
                'alliance_id' => $allyId ? (int) $allyId : null,
            ];
            $dashboard = app(Dashboard::class);
            $card = $dashboard->buildCharacterCard($stub);
        }
        $dataSince = cache()->remember('dashboard.killmail.min_killed_at', 86400, function (): ?string {
            $v = DB::table('killmails')->min('killed_at');
            return $v ? (string) $v : null;
        });
        return [
            'search' => $this->search,
            'character_id' => $this->characterId,
            'suggestions' => $suggestions,
            'card' => $card,
            'data_since' => $dataSince,
        ];
    }
}
