<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\CounterIntel\Services\CounterIntelDossierService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /admin/counter-intel/{character} — individual pilot dossier.
 *
 * Text is defensibility-first: fixed sentence templates from the
 * service; no freeform generation; no words like "spy" or "infiltrator".
 * Surface is triage, not automation — every row invites human review.
 */
class CounterIntelDossier extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $slug = 'counter-intel/{character}';

    protected static ?string $title = 'Counter-Intel · Dossier';

    protected string $view = 'filament.pages.counter-intel-dossier';

    public int $characterIdParam;

    public function mount(int $character): void
    {
        $this->characterIdParam = $character;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $viewerBlocId = $this->resolveViewerBloc();
        if ($viewerBlocId === null) {
            return ['no_bloc' => true, 'character_id' => $this->characterIdParam];
        }
        $svc = app(CounterIntelDossierService::class);
        $dossier = $svc->dossier($this->characterIdParam, $viewerBlocId);
        $blocName = DB::table('coalition_blocs')->where('id', $viewerBlocId)->value('display_name') ?? "Bloc #{$viewerBlocId}";
        $userId = Auth::id();
        $watch = null;
        if ($userId !== null) {
            $watch = DB::table('ci_review_watchlist')
                ->where('user_id', $userId)
                ->where('character_id', $this->characterIdParam)
                ->first(['note', 'added_at']);
        }
        return [
            'no_bloc' => false,
            'viewer_bloc_id' => $viewerBlocId,
            'viewer_bloc_name' => $blocName,
            'dossier' => $dossier,
            'watchlist_entry' => $watch,
        ];
    }

    /** Livewire action wired from the blade: toggle this character on the viewer's watchlist. */
    public function toggleWatch(): void
    {
        $userId = Auth::id();
        if ($userId === null) return;
        $existing = DB::table('ci_review_watchlist')
            ->where('user_id', $userId)
            ->where('character_id', $this->characterIdParam)
            ->first();
        if ($existing) {
            DB::table('ci_review_watchlist')
                ->where('user_id', $userId)
                ->where('character_id', $this->characterIdParam)
                ->delete();
        } else {
            DB::table('ci_review_watchlist')->insert([
                'user_id' => $userId,
                'character_id' => $this->characterIdParam,
                'note' => null,
                'added_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function saveWatchNote(string $note): void
    {
        $userId = Auth::id();
        if ($userId === null) return;
        DB::table('ci_review_watchlist')
            ->where('user_id', $userId)
            ->where('character_id', $this->characterIdParam)
            ->update(['note' => $note === '' ? null : $note, 'updated_at' => now()]);
    }

    public function getTitle(): string
    {
        $name = DB::table('esi_entity_names')
            ->where('entity_id', $this->characterIdParam)
            ->where('category', 'character')
            ->value('name');
        return $name ? "Counter-Intel · {$name}" : 'Counter-Intel dossier';
    }

    private function resolveViewerBloc(): ?int
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
