<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Services\BattleTheaterSideResolution;
use App\Domains\KillmailsBattleTheaters\Services\BattleTheaterSideResolver;
use App\Domains\UsersCharacters\Actions\SyncViewerContextForCharacter;
use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Models\EsiEntityName;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Portal detail page for a single battle theater.
 *
 * Render sections (ADR-0006 § 5):
 *
 *   1. Header           — label, primary system, region, time span,
 *                         lock state, top-line rollup numbers.
 *   2. Side summary     — three columns (A / B / other). Each shows
 *                         the bloc label (when mapped), pilot count,
 *                         ISK lost. "ISK killed" per side is the
 *                         opposing side's ISK lost — one number, two
 *                         perspectives (per the locked metric spec).
 *   3. Alliance table   — alliance → side, pilots, kills, deaths,
 *                         ISK lost, damage done / taken.
 *   4. Pilot table      — per-character row sorted by ISK_lost DESC.
 *                         Columns: side, pilot, alliance/corp, kills,
 *                         final blows, damage done, damage taken,
 *                         ISK lost.
 *   5. Systems          — where the fighting happened (from
 *                         battle_theater_systems).
 *
 * Side assignment is viewer-relative. Resolved once on mount from the
 * authed user's ViewerContext; kept on $this->sides through the
 * render. Viewers without a confirmed bloc see the two largest blocs
 * in the fight as A/B.
 */
class BattleTheaterDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $slug = 'battles/{record}';

    // Hidden from the portal sidebar — the list page at
    // /portal/battles drills into this page via recordUrl().
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.portal.pages.battle-theater-detail';

    // Only the scalar id is a Livewire public property. Resolved model
    // + side resolution + viewer context are recomputed each request
    // from that id — Livewire cannot serialise a custom value object
    // (BattleTheaterSideResolution) or an Eloquent model across
    // component round-trips, and keeping them public throws
    // "Property type not supported in Livewire" on every request.
    public ?int $recordId = null;

    public function mount(BattleTheater|int $record): void
    {
        // Filament's page router gives us either the raw path segment
        // (int) or a resolved model — accept both. We only keep the
        // id here; getViewData() reloads the model + relations every
        // render.
        $this->recordId = $record instanceof BattleTheater ? (int) $record->id : (int) $record;
    }

    /** Load the theater + eager relations. Called lazily. */
    private function loadRecord(): BattleTheater
    {
        return BattleTheater::query()
            ->with(['primarySystem:id,name', 'region:id,name'])
            ->findOrFail($this->recordId);
    }

    /** Resolve the viewer's ViewerContext, or null if unauth / no chars. */
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
     * Payload assembled once per render and passed to the blade view
     * as a single array. Keeps the blade side free of controller logic
     * and ensures every section gets its inputs from the same set of
     * eager-loaded collections.
     *
     * Nothing here is stored on the component — the value object
     * (BattleTheaterSideResolution) is non-serialisable for Livewire
     * so it lives only for the duration of this method call. The
     * blade receives scalars / collections / the resolved model.
     *
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $theater = $this->loadRecord();
        $viewer = $this->loadViewer();
        $sides = app(BattleTheaterSideResolver::class)->resolve($theater, $viewer);

        $participants = $theater->participants()->orderByDesc('isk_lost')->get();
        $systems = $theater->systems()
            ->with('solarSystem:id,name')
            ->orderByDesc('kill_count')
            ->get();

        // Bloc display names for the side headers + alliance rows.
        $blocIds = array_values(array_filter([
            $sides->sideABlocId,
            $sides->sideBBlocId,
            ...array_values($sides->allianceToBloc),
        ], fn ($v) => $v !== null));
        $blocs = $blocIds !== []
            ? CoalitionBloc::query()->whereIn('id', array_unique($blocIds))->get()->keyBy('id')
            : collect();

        // Entity names for characters + corps + alliances.
        $charIds = $participants->pluck('character_id')->filter()->unique()->values();
        $corpIds = $participants->pluck('corporation_id')->filter()->unique()->values();
        $allIds = $participants->pluck('alliance_id')->filter()->unique()->values();
        $names = EsiEntityName::query()
            ->whereIn('entity_id', $charIds->merge($corpIds)->merge($allIds)->unique()->values()->all())
            ->pluck('name', 'entity_id');

        // Side totals. ISK Lost per side is summed from participants;
        // ISK Killed is the opposing side's ISK Lost (one number, two
        // perspectives — ADR-0006 § 1).
        $sideTotals = $this->computeSideTotals($participants, $sides);

        // Alliance rollup (GROUP BY alliance_id in memory — fast at
        // theater-sized row counts; keeps the DB schema minimal).
        $allianceRows = $this->buildAllianceRollup($participants, $names, $sides);

        return [
            'theater' => $theater,
            'sides' => $sides,
            'blocs' => $blocs,
            'names' => $names,
            'participants' => $participants,
            'systems' => $systems,
            'side_totals' => $sideTotals,
            'alliance_rows' => $allianceRows,
            'viewer' => $viewer,
        ];
    }

    /**
     * Per-side rollup: pilot_count, kills, deaths, damage_done,
     * damage_taken, isk_lost. ISK killed mirrored from the opposing
     * side so both Side A and Side B always reconcile.
     *
     * @param  Collection<int, \App\Domains\KillmailsBattleTheaters\Models\BattleTheaterParticipant>  $participants
     * @return array<string, array<string, int|float>>
     */
    private function computeSideTotals(Collection $participants, BattleTheaterSideResolution $sides): array
    {
        $zero = [
            'pilots' => 0,
            'kills' => 0,
            'final_blows' => 0,
            'deaths' => 0,
            'damage_done' => 0,
            'damage_taken' => 0,
            'isk_lost' => 0.0,
        ];
        $totals = [
            BattleTheaterSideResolver::SIDE_A => $zero,
            BattleTheaterSideResolver::SIDE_B => $zero,
            BattleTheaterSideResolver::SIDE_C => $zero,
        ];

        foreach ($participants as $p) {
            $side = $sides->sideByCharacterId[(int) $p->character_id] ?? BattleTheaterSideResolver::SIDE_C;
            $totals[$side]['pilots']++;
            $totals[$side]['kills'] += (int) $p->kills;
            $totals[$side]['final_blows'] += (int) $p->final_blows;
            $totals[$side]['deaths'] += (int) $p->deaths;
            $totals[$side]['damage_done'] += (int) $p->damage_done;
            $totals[$side]['damage_taken'] += (int) $p->damage_taken;
            $totals[$side]['isk_lost'] += (float) $p->isk_lost;
        }

        // ISK killed = opposing side's ISK lost. The metric contract
        // in ADR-0006 § 1 forbids independently computing ISK killed
        // — it's exactly the mirror. Side C's "killed" is left as 0
        // (third parties are collapsed; their kills don't attribute
        // to either main side at the rollup level).
        $totals[BattleTheaterSideResolver::SIDE_A]['isk_killed'] = $totals[BattleTheaterSideResolver::SIDE_B]['isk_lost'];
        $totals[BattleTheaterSideResolver::SIDE_B]['isk_killed'] = $totals[BattleTheaterSideResolver::SIDE_A]['isk_lost'];
        $totals[BattleTheaterSideResolver::SIDE_C]['isk_killed'] = 0.0;

        return $totals;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildAllianceRollup(Collection $participants, $names, BattleTheaterSideResolution $sides): Collection
    {
        $byAlliance = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            $side = $sides->sideByCharacterId[(int) $p->character_id] ?? BattleTheaterSideResolver::SIDE_C;
            $row = $byAlliance[$aid] ?? [
                'alliance_id' => $aid,
                'alliance_name' => $aid > 0 ? ($names[$aid] ?? "Alliance #{$aid}") : '(no alliance)',
                'side' => $side,
                'pilots' => 0,
                'kills' => 0,
                'deaths' => 0,
                'isk_lost' => 0.0,
                'damage_done' => 0,
                'damage_taken' => 0,
            ];
            $row['pilots']++;
            $row['kills'] += (int) $p->kills;
            $row['deaths'] += (int) $p->deaths;
            $row['damage_done'] += (int) $p->damage_done;
            $row['damage_taken'] += (int) $p->damage_taken;
            $row['isk_lost'] += (float) $p->isk_lost;
            $byAlliance[$aid] = $row;
        }

        return collect(array_values($byAlliance))
            ->sortByDesc('isk_lost')
            ->values();
    }
}
