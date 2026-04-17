<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Services\BattleTheaterViewData;
use App\Domains\UsersCharacters\Actions\SyncViewerContextForCharacter;
use App\Domains\UsersCharacters\Models\ViewerContext;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Portal detail page for a single battle theater.
 *
 * Thin Filament shell — every rollup is built by
 * ``BattleTheaterViewData`` which the public controller also uses,
 * so authed + public surfaces can't drift on metric definitions.
 * The only Filament-specific responsibility left here is resolving
 * the viewer context (needed for viewer-relative side labels).
 */
class BattleTheaterDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $slug = 'battles/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.portal.pages.battle-theater-detail';

    // Livewire cannot serialise the Eloquent model or the side-
    // resolution value object across round-trips; persist only the
    // scalar id and reload in getViewData().
    public ?int $recordId = null;

    /**
     * Mount accepts either a numeric id (legacy share URLs) or a
     * stable public_slug (preferred — survives re-clusters of the
     * same fight). Slugs resolve to the newest row with that slug
     * in case clustering split one fight into two with identical
     * system+minute buckets.
     */
    public function mount(BattleTheater|int|string $record): void
    {
        if ($record instanceof BattleTheater) {
            $this->recordId = (int) $record->id;
            return;
        }
        if (is_int($record) || ctype_digit((string) $record)) {
            $this->recordId = (int) $record;
            return;
        }
        $theater = BattleTheater::query()
            ->where('public_slug', (string) $record)
            ->orderByDesc('id')
            ->firstOrFail();
        $this->recordId = (int) $theater->id;
    }

    private function loadRecord(): BattleTheater
    {
        return BattleTheater::query()
            ->with(['primarySystem:id,name,security_status', 'region:id,name'])
            ->findOrFail($this->recordId);
    }

    private function loadViewer(): ?ViewerContext
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }
        $character = $user->characters()->orderBy('id')->first();
        if ($character === null) {
            return null;
        }

        return app(SyncViewerContextForCharacter::class)->handle($character);
    }

    public function getTitle(): string
    {
        $theater = $this->loadRecord();
        $system = $theater->primarySystem?->name ?? '#'.$theater->primary_system_id;

        return "Battle in {$system}";
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return app(BattleTheaterViewData::class)
            ->build($this->loadRecord(), $this->loadViewer(), hideBlocNames: false);
    }
}
