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
use Illuminate\Support\Facades\DB;
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
        $theater = $this->loadRecord();
        $data = app(BattleTheaterViewData::class)
            ->build($theater, $this->loadViewer(), hideBlocNames: false);

        // Auto-refresh — only while the battle is "live": newest
        // killmail within last 6 hours OR theater itself ended
        // < 30 min ago. Older battles are historical → no point
        // polling. Operator opt-out via ?autorefresh=off.
        $newestKm = DB::table('battle_theater_killmails AS btk')
            ->join('killmails AS k', 'k.killmail_id', '=', 'btk.killmail_id')
            ->where('btk.theater_id', $theater->id)
            ->max('k.killed_at');
        $endedAt = $theater->end_time ?? null;
        $optOut = (string) request()->query('autorefresh', '') === 'off';
        $isLive = false;
        $reason = null;
        if (! $optOut) {
            if ($newestKm !== null && \Carbon\Carbon::parse($newestKm)->gt(now()->subHours(6))) {
                $isLive = true;
                $reason = 'newest killmail < 6h ago';
            } elseif ($endedAt !== null && \Carbon\Carbon::parse($endedAt)->gt(now()->subMinutes(30))) {
                $isLive = true;
                $reason = 'battle ended < 30m ago';
            }
        }
        $data['auto_refresh'] = [
            'enabled' => $isLive,
            'interval_seconds' => 60,
            'reason' => $reason,
            'opt_out_url' => '?autorefresh=off',
            'opt_in_url' => '?autorefresh=on',
            'opt_out_active' => $optOut,
            'newest_km_at' => $newestKm,
        ];
        return $data;
    }
}
