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
 * Layout modelled after eve-kill.com / zkillboard related-kills pages:
 *   1. Header              — system, region, time span, top-line counts.
 *   2. VS banner + cards   — big logos per side, efficiency split bar,
 *                            kills / losses / ISK destroyed / ISK lost.
 *   3. Most valuable kills — top 3 per side showcased with ship art.
 *   4. Ship composition    — grouped by ship-class bucket per side.
 *   5. Top damage dealers  — top 5 per side by damage_done.
 *   6. Alliance roster     — per-alliance rollup by side (logos + stats).
 *   7. Pilot table         — sorted by ISK_lost desc.
 *   8. Kill feed           — time-ordered kill list, colored by victim side.
 *   9. Systems             — where the fighting happened.
 *
 * Side assignment is viewer-relative (ADR-0006 § 2) — resolved once
 * on each render from the authed user's ViewerContext.
 */
class BattleTheaterDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $slug = 'battles/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.portal.pages.battle-theater-detail';

    // Livewire cannot serialise the Eloquent model or the side-resolution
    // value object across round-trips; persist only the scalar id and
    // reload in getViewData().
    public ?int $recordId = null;

    public function mount(BattleTheater|int $record): void
    {
        $this->recordId = $record instanceof BattleTheater ? (int) $record->id : (int) $record;
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
        $viewer = $this->loadViewer();
        $sides = app(BattleTheaterSideResolver::class)->resolve($theater, $viewer);

        $participants = $theater->participants()->orderByDesc('isk_lost')->get();
        $systems = $theater->systems()
            ->with('solarSystem:id,name,security_status')
            ->orderByDesc('kill_count')
            ->get();

        $blocIds = array_values(array_filter([
            $sides->sideABlocId,
            $sides->sideBBlocId,
            ...array_values($sides->allianceToBloc),
        ], fn ($v) => $v !== null));
        $blocs = $blocIds !== []
            ? CoalitionBloc::query()->whereIn('id', array_unique($blocIds))->get()->keyBy('id')
            : collect();

        $charIds = $participants->pluck('character_id')->filter()->unique()->values();
        $corpIds = $participants->pluck('corporation_id')->filter()->unique()->values();
        $allIds = $participants->pluck('alliance_id')->filter()->unique()->values();
        $names = EsiEntityName::query()
            ->whereIn('entity_id', $charIds->merge($corpIds)->merge($allIds)->unique()->values()->all())
            ->pluck('name', 'entity_id');

        $sideTotals = $this->computeSideTotals($participants, $sides);

        $allianceRows = $this->buildAllianceRollup($participants, $names, $sides);

        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theater->id)
            ->pluck('killmail_id')
            ->all();

        $shipsByCharacter = $this->buildShipRollup($killmailIds);

        // Gather every ship type id referenced across pilot hulls,
        // composition, kill feed, etc., and hydrate name + group in
        // one query.
        $shipTypeIds = [];
        foreach ($shipsByCharacter as $rows) {
            foreach (array_keys($rows) as $tid) {
                $shipTypeIds[$tid] = true;
            }
        }
        $shipTypes = $shipTypeIds !== []
            ? DB::table('ref_item_types as t')
                ->leftJoin('ref_item_groups as g', 'g.id', '=', 't.group_id')
                ->whereIn('t.id', array_keys($shipTypeIds))
                ->select('t.id', 't.name', 't.group_id', 'g.name as group_name')
                ->get()
                ->keyBy('id')
            : collect();
        $shipNames = $shipTypes->map(fn ($r) => $r->name);
        $shipGroupNames = $shipTypes->map(fn ($r) => $r->group_name);

        $killFeed = $this->buildKillFeed($killmailIds, $names, $shipNames);

        // Derived read-models the blade consumes directly — builds each
        // in one pass so the view stays loop-free.
        $composition = $this->buildComposition($participants, $sides, $shipsByCharacter, $shipGroupNames);
        $mostValuableKills = $this->buildMostValuableKills($killFeed, $sides);
        $topDamage = $this->buildTopDamage($participants, $sides, $names, $shipsByCharacter, $shipNames);
        $rosterBySide = $this->buildRosterBySide($allianceRows, $sides);
        $flagshipLogos = $this->buildFlagshipLogos($allianceRows);
        $headerStats = $this->buildHeaderStats($participants);

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
            'ships_by_character' => $shipsByCharacter,
            'ship_names' => $shipNames,
            'ship_group_names' => $shipGroupNames,
            'kill_feed' => $killFeed,
            'composition' => $composition,
            'most_valuable_kills' => $mostValuableKills,
            'top_damage' => $topDamage,
            'roster_by_side' => $rosterBySide,
            'flagship_logos' => $flagshipLogos,
            'header_stats' => $headerStats,
        ];
    }

    /**
     * @param  list<int>  $killmailIds
     * @return array<int, array<int, int>>
     */
    private function buildShipRollup(array $killmailIds): array
    {
        if ($killmailIds === []) {
            return [];
        }

        $out = [];

        DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('character_id')
            ->where('ship_type_id', '>', 0)
            ->select(['character_id', 'ship_type_id'])
            ->orderBy('killmail_id')
            ->get()
            ->each(function ($row) use (&$out): void {
                $cid = (int) $row->character_id;
                $tid = (int) $row->ship_type_id;
                $out[$cid][$tid] = ($out[$cid][$tid] ?? 0) + 1;
            });

        DB::table('killmails')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('victim_character_id')
            ->where('victim_ship_type_id', '>', 0)
            ->select(['victim_character_id', 'victim_ship_type_id'])
            ->get()
            ->each(function ($row) use (&$out): void {
                $cid = (int) $row->victim_character_id;
                $tid = (int) $row->victim_ship_type_id;
                $out[$cid][$tid] = ($out[$cid][$tid] ?? 0) + 1;
            });

        return $out;
    }

    /**
     * @param  list<int>  $killmailIds
     * @return array<int, array<string, mixed>>
     */
    private function buildKillFeed(array $killmailIds, Collection $names, Collection $shipNames): array
    {
        if ($killmailIds === []) {
            return [];
        }

        $rows = DB::table('killmails')
            ->whereIn('killmail_id', $killmailIds)
            ->select([
                'killmail_id', 'killed_at', 'solar_system_id',
                'victim_character_id', 'victim_corporation_id', 'victim_alliance_id',
                'victim_ship_type_id', 'total_value', 'attacker_count',
            ])
            ->orderBy('killed_at')
            ->get();

        $finalBlows = DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->where('is_final_blow', true)
            ->select(['killmail_id', 'character_id', 'alliance_id', 'corporation_id', 'ship_type_id'])
            ->get()
            ->keyBy('killmail_id');

        $out = [];
        foreach ($rows as $r) {
            $fb = $finalBlows->get($r->killmail_id);
            $out[] = [
                'killmail_id' => (int) $r->killmail_id,
                'killed_at' => $r->killed_at,
                'system_id' => (int) $r->solar_system_id,
                'victim_id' => $r->victim_character_id ? (int) $r->victim_character_id : null,
                'victim_name' => $r->victim_character_id ? ($names[(int) $r->victim_character_id] ?? '#'.$r->victim_character_id) : '(NPC)',
                'victim_corp_id' => $r->victim_corporation_id ? (int) $r->victim_corporation_id : null,
                'victim_alliance_id' => $r->victim_alliance_id ? (int) $r->victim_alliance_id : null,
                'ship_type_id' => (int) $r->victim_ship_type_id,
                'ship_name' => $shipNames[(int) $r->victim_ship_type_id] ?? '#'.$r->victim_ship_type_id,
                'total_value' => (float) $r->total_value,
                'attacker_count' => (int) $r->attacker_count,
                'final_blow_char_id' => $fb && $fb->character_id ? (int) $fb->character_id : null,
                'final_blow_name' => $fb && $fb->character_id ? ($names[(int) $fb->character_id] ?? '#'.$fb->character_id) : null,
                'final_blow_alliance_id' => $fb && $fb->alliance_id ? (int) $fb->alliance_id : null,
                'final_blow_ship_id' => $fb ? (int) $fb->ship_type_id : null,
            ];
        }

        return $out;
    }

    /**
     * Per-side rollup: pilot_count, kills, deaths, damage_done,
     * damage_taken, isk_lost. ISK killed mirrored from the opposing
     * side so both sides reconcile (ADR-0006 § 1).
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

        $totals[BattleTheaterSideResolver::SIDE_A]['isk_killed'] = $totals[BattleTheaterSideResolver::SIDE_B]['isk_lost'];
        $totals[BattleTheaterSideResolver::SIDE_B]['isk_killed'] = $totals[BattleTheaterSideResolver::SIDE_A]['isk_lost'];
        $totals[BattleTheaterSideResolver::SIDE_C]['isk_killed'] = 0.0;

        return $totals;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildAllianceRollup(Collection $participants, Collection $names, BattleTheaterSideResolution $sides): Collection
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

    /**
     * Ship composition per side — bucket each appearance by ship
     * class (`ref_item_groups.name`) and count. Returns, per side, a
     * list of [class, count, sample_type_id] sorted by count desc.
     *
     * "Ship class" is the SDE group name: "Frigate", "Heavy Assault
     * Cruiser", "Dreadnought", etc. Good enough for a roster-style
     * composition table without needing a hand-maintained taxonomy.
     *
     * @param  array<int, array<int, int>>  $shipsByCharacter
     * @return array<string, list<array{class: string, count: int, sample_type_id: int}>>
     */
    private function buildComposition(
        Collection $participants,
        BattleTheaterSideResolution $sides,
        array $shipsByCharacter,
        Collection $shipGroupNames,
    ): array {
        $bySide = [
            BattleTheaterSideResolver::SIDE_A => [],
            BattleTheaterSideResolver::SIDE_B => [],
            BattleTheaterSideResolver::SIDE_C => [],
        ];

        foreach ($participants as $p) {
            $cid = (int) $p->character_id;
            $side = $sides->sideByCharacterId[$cid] ?? BattleTheaterSideResolver::SIDE_C;
            $hulls = $shipsByCharacter[$cid] ?? [];
            if ($hulls === []) {
                continue;
            }
            // Attribute the pilot to their primary hull — the one they
            // appeared in most — to avoid double-counting when a pilot
            // reshipped mid-fight.
            arsort($hulls);
            $primaryTid = (int) array_key_first($hulls);
            $group = (string) ($shipGroupNames[$primaryTid] ?? 'Unknown');
            $row = $bySide[$side][$group] ?? ['class' => $group, 'count' => 0, 'sample_type_id' => $primaryTid];
            $row['count']++;
            $bySide[$side][$group] = $row;
        }

        $out = [];
        foreach ($bySide as $side => $rows) {
            $list = array_values($rows);
            usort($list, fn ($a, $b) => $b['count'] <=> $a['count']);
            $out[$side] = $list;
        }

        return $out;
    }

    /**
     * For each side, the top 3 kills (biggest ISK victim) where the
     * victim belongs to the OPPOSING side — i.e. the flashy wins this
     * side put on the board.
     *
     * @param  array<int, array<string, mixed>>  $killFeed
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildMostValuableKills(array $killFeed, BattleTheaterSideResolution $sides): array
    {
        // Group by victim side, then top 3 by value.
        $buckets = [
            BattleTheaterSideResolver::SIDE_A => [],
            BattleTheaterSideResolver::SIDE_B => [],
            BattleTheaterSideResolver::SIDE_C => [],
        ];
        foreach ($killFeed as $km) {
            $victimSide = $km['victim_id'] ? ($sides->sideByCharacterId[(int) $km['victim_id']] ?? BattleTheaterSideResolver::SIDE_C) : BattleTheaterSideResolver::SIDE_C;
            $buckets[$victimSide][] = $km;
        }
        foreach ($buckets as $k => $rows) {
            usort($rows, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);
            $buckets[$k] = array_slice($rows, 0, 3);
        }

        // Side A's "best kills" = victims from Side B (and third parties
        // they happened to kill). Side B's best kills = victims from A.
        return [
            BattleTheaterSideResolver::SIDE_A => $buckets[BattleTheaterSideResolver::SIDE_B],
            BattleTheaterSideResolver::SIDE_B => $buckets[BattleTheaterSideResolver::SIDE_A],
        ];
    }

    /**
     * Top-5 damage dealers per side, with primary hull lookup for the
     * cards.
     *
     * @param  array<int, array<int, int>>  $shipsByCharacter
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildTopDamage(
        Collection $participants,
        BattleTheaterSideResolution $sides,
        Collection $names,
        array $shipsByCharacter,
        Collection $shipNames,
    ): array {
        $bySide = [
            BattleTheaterSideResolver::SIDE_A => [],
            BattleTheaterSideResolver::SIDE_B => [],
        ];
        foreach ($participants as $p) {
            $cid = (int) $p->character_id;
            $side = $sides->sideByCharacterId[$cid] ?? BattleTheaterSideResolver::SIDE_C;
            if (! isset($bySide[$side])) {
                continue;
            }
            $hulls = $shipsByCharacter[$cid] ?? [];
            $primaryTid = null;
            if ($hulls !== []) {
                arsort($hulls);
                $primaryTid = (int) array_key_first($hulls);
            }
            $bySide[$side][] = [
                'character_id' => $cid,
                'character_name' => $names[$cid] ?? 'Character #'.$cid,
                'alliance_id' => $p->alliance_id ? (int) $p->alliance_id : null,
                'alliance_name' => $p->alliance_id ? ($names[(int) $p->alliance_id] ?? null) : null,
                'damage_done' => (int) $p->damage_done,
                'kills' => (int) $p->kills,
                'final_blows' => (int) $p->final_blows,
                'ship_type_id' => $primaryTid,
                'ship_name' => $primaryTid ? ($shipNames[$primaryTid] ?? '#'.$primaryTid) : null,
            ];
        }
        foreach ($bySide as $k => $rows) {
            usort($rows, fn ($a, $b) => $b['damage_done'] <=> $a['damage_done']);
            $bySide[$k] = array_slice($rows, 0, 5);
        }

        return $bySide;
    }

    /**
     * Split the alliance rollup by side so the roster panel can render
     * Side A | Side B | Other as three parallel lists.
     *
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    private function buildRosterBySide(Collection $allianceRows, BattleTheaterSideResolution $sides): array
    {
        return [
            BattleTheaterSideResolver::SIDE_A => $allianceRows->where('side', BattleTheaterSideResolver::SIDE_A)->values(),
            BattleTheaterSideResolver::SIDE_B => $allianceRows->where('side', BattleTheaterSideResolver::SIDE_B)->values(),
            BattleTheaterSideResolver::SIDE_C => $allianceRows->where('side', BattleTheaterSideResolver::SIDE_C)->values(),
        ];
    }

    /**
     * Biggest-alliance-per-side — drives the big alliance logo shown
     * in the VS banner at the top of the page. "Biggest" = most
     * pilots committed.
     *
     * @return array<string, array{alliance_id: int, alliance_name: string, pilots: int}|null>
     */
    private function buildFlagshipLogos(Collection $allianceRows): array
    {
        $pick = function (string $side) use ($allianceRows): ?array {
            $rows = $allianceRows->where('side', $side)->where('alliance_id', '>', 0)->sortByDesc('pilots')->values();
            if ($rows->isEmpty()) {
                return null;
            }
            $r = $rows->first();

            return [
                'alliance_id' => (int) $r['alliance_id'],
                'alliance_name' => (string) $r['alliance_name'],
                'pilots' => (int) $r['pilots'],
            ];
        };

        return [
            BattleTheaterSideResolver::SIDE_A => $pick(BattleTheaterSideResolver::SIDE_A),
            BattleTheaterSideResolver::SIDE_B => $pick(BattleTheaterSideResolver::SIDE_B),
        ];
    }

    /**
     * Header meta — distinct corps + alliances, total damage across
     * the whole fight, NPC-final-blow count.
     *
     * @return array<string, int>
     */
    private function buildHeaderStats(Collection $participants): array
    {
        $corps = [];
        $alliances = [];
        $damage = 0;
        foreach ($participants as $p) {
            if ($p->corporation_id) {
                $corps[(int) $p->corporation_id] = true;
            }
            if ($p->alliance_id) {
                $alliances[(int) $p->alliance_id] = true;
            }
            $damage += (int) $p->damage_done;
        }

        return [
            'corps' => count($corps),
            'alliances' => count($alliances),
            'damage' => $damage,
        ];
    }
}
