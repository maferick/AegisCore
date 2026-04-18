<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Models\BattleTheaterParticipant;
use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Models\EsiEntityName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure view-data builder for a battle theater page.
 *
 * Shared by the authed Filament Portal page (BattleTheaterDetail) and
 * the public no-login controller (PublicBattlesController). Keeping the
 * logic in one place guarantees both surfaces show the same rollups —
 * the only intentional divergence is the ``hideBlocNames`` flag that
 * the public surface sets, which suppresses the "{bloc} bloc" subtitle
 * on the VS banner and side cards. Alliance / corp / character names
 * stay visible either way; bloc membership is viewer-specific intel.
 */
final class BattleTheaterViewData
{
    public function __construct(
        private readonly BattleTheaterSideResolver $sideResolver,
        private readonly BattleRoleInferenceLoader $roleInferenceLoader = new BattleRoleInferenceLoader(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(BattleTheater $theater, ?ViewerContext $viewer, bool $hideBlocNames = false): array
    {
        $participants = $theater->participants()->orderByDesc('isk_lost')->get();
        $systems = $theater->systems()
            ->with('solarSystem:id,name,security_status')
            ->orderByDesc('kill_count')
            ->get();

        // The clustering worker sometimes writes participant rows with
        // alliance_id=0 / corporation_id=0 for pilots whose
        // affiliations weren't resolved at cluster time (NPC-killed
        // pilots, old killmails re-ingested, the victim-side join
        // racing a ref_* reload). Those zeros poison the side
        // resolver and the roster / pilot table UI. The killmails +
        // killmail_attackers rows always carry the truth, so we patch
        // the collection in memory from them — no DB write.
        $this->hydrateParticipantAffiliations($theater, $participants);

        // Spec § 5.1 + § 5.2 — fold capsule + structure kills into
        // the participants collection so the roster / ISK totals
        // reconcile with the theater's total totalValue. The
        // clustering worker materialises participant rows from
        // ship kills only, leaving capsule ISK and structure kills
        // invisible to the UI (sum of visible rows came out ~70% of
        // theater value on test fights with pods and citadel shoots
        // in the cluster).
        $synthetic = $this->hydrateCapsuleAndStructureKills($theater, $participants);
        $structureNames = $synthetic['structure_names']; // char_id → display name

        // Pass the hydrated participant collection — the resolver
        // otherwise re-queries the DB and misses the patched
        // alliance_ids (small alliances whose clustering-time
        // affiliations were null end up on the wrong side).
        $sides = $this->sideResolver->resolve($theater, $viewer, $participants);

        // Overlay operator overrides on top of the auto-resolver.
        // Precedence: character > corporation > alliance > auto. A
        // side value of 'exclude' drops the entity's pilots + kills
        // from every downstream rollup. Overrides persist across
        // recluster runs and across deploys — the same alliance can
        // be Side A in one theater and Side B in another (keyed by
        // theater_id).
        $overrides = \App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride::query()
            ->where('theater_id', $theater->id)
            ->get();
        [$sides, $participants, $excludedCharIds] = $this->applyOverrides(
            sides: $sides,
            participants: $participants,
            overrides: $overrides,
        );

        $blocIds = array_values(array_filter([
            $sides->sideABlocId,
            $sides->sideBBlocId,
            ...array_values($sides->allianceToBloc),
        ], fn ($v) => $v !== null));
        $blocs = ($hideBlocNames || $blocIds === [])
            ? collect()
            : CoalitionBloc::query()->whereIn('id', array_unique($blocIds))->get()->keyBy('id');

        $charIds = $participants->pluck('character_id')->filter()->unique()->values();
        $corpIds = $participants->pluck('corporation_id')->filter()->unique()->values();
        $allIds = $participants->pluck('alliance_id')->filter()->unique()->values();
        $names = EsiEntityName::query()
            ->whereIn('entity_id', $charIds->merge($corpIds)->merge($allIds)->unique()->values()->all())
            ->pluck('name', 'entity_id');

        // Merge structure display labels into the names map keyed by
        // the synthetic character_id ("-killmail_id"). Blade renders
        // ``$names[$p->character_id]`` unchanged; structure rows look
        // like any other participant with a descriptive label.
        foreach ($structureNames as $syntheticCid => $label) {
            $names[$syntheticCid] = $label;
        }

        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theater->id)
            ->pluck('killmail_id')
            ->all();

        // Drop killmails whose victim is an excluded character —
        // keeps the kill feed / most-valuable-kills honest when the
        // operator marked an entity as noise. Excluding by corp /
        // alliance drops many killmails; excluding by character
        // drops one at a time.
        if ($excludedCharIds !== []) {
            $killmailIds = DB::table('killmails')
                ->whereIn('killmail_id', $killmailIds)
                ->whereNotIn('victim_character_id', $excludedCharIds)
                ->pluck('killmail_id')
                ->all();
        }

        $shipsByCharacter = $this->buildShipRollup($killmailIds);

        // Per updated domain spec § 2.1 + § 8. A side's Kills and ISK
        // Killed are both distinct-killmail counts/sums where the
        // victim is NOT on the side AND the side has ≥1 attacker
        // on the mail. Blue-on-blue (C attacks C) is excluded from
        // these side-level counters even though the pilots still
        // get individual involvement credit at the per-pilot level.
        $sideKillCounts = $this->buildKillCountsBySide($killmailIds, $sides);
        $iskKilledBySide = $sideKillCounts['isk'];
        $killsBySide = $sideKillCounts['kills'];
        $sideTotals = $this->computeSideTotals($participants, $sides, $iskKilledBySide, $killsBySide);

        // Alliance-level kill involvements = COUNT(DISTINCT killmail_id)
        // where any member of the alliance is on the attacker list
        // AND the victim is NOT on this alliance's side (spec § 5.4 +
        // § 8 alliance summary). Sum of per-pilot kills inflates by
        // fleet size; this query returns the true distinct-km count.
        $allianceKillInvolvements = $this->buildAllianceKillInvolvements($killmailIds, $sides);

        $allianceRows = $this->buildAllianceRollup($participants, $names, $sides, $allianceKillInvolvements);

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
        $composition = $this->buildComposition($participants, $sides, $shipsByCharacter, $shipGroupNames);
        $mostValuableKills = $this->buildMostValuableKills($killFeed, $sides);
        $topDamage = $this->buildTopDamage($participants, $sides, $names, $shipsByCharacter, $shipNames);
        $rosterBySide = $this->buildRosterBySide($allianceRows, $sides);
        $flagshipLogos = $this->buildFlagshipLogos($allianceRows);
        $headerStats = $this->buildHeaderStats($participants);

        // Recomputed theater-level totals — spec § 9.1 requires
        // sum(side.isk_lost) = sum(totalValue). Clustering worker
        // writes theater.total_isk_lost / total_kills from ship-kill
        // participants only, missing capsule + structure values; the
        // read-time hydration above captures them. We surface both so
        // the blade can show reconciled numbers without touching the
        // clustering worker.
        $reconciledTotalIskLost = (float) array_sum(array_column($sideTotals, 'isk_lost'));
        $reconciledTotalKills = (int) (
            $sideTotals[BattleTheaterSideResolver::SIDE_A]['deaths']
            + $sideTotals[BattleTheaterSideResolver::SIDE_B]['deaths']
            + $sideTotals[BattleTheaterSideResolver::SIDE_C]['deaths']
        );

        return [
            'theater' => $theater,
            'sides' => $sides,
            'reconciled_total_isk_lost' => $reconciledTotalIskLost,
            'reconciled_total_kills' => $reconciledTotalKills,
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
            // Raw override rows so the portal view can render the
            // "this alliance was manually moved" indicator + the
            // dropdown to change / clear it.
            'overrides' => $overrides,
            'hide_bloc_names' => $hideBlocNames,
            // Spec 6: per-(alliance, sub_fleet) inferred roles from
            // Spec 5. Null-safe: empty array when no scoring has run.
            'role_inference_by_alliance' => $this->roleInferenceLoader->load(
                $theater->id,
                $participants->pluck('alliance_id')->filter()->unique()->values()->all(),
            ),
            // Flat char_id → role_key dict used by the rosters + top-damage
            // cards to render inline role badges next to pilot names.
            'role_by_character' => $this->roleInferenceLoader->charRoleMap(
                $theater->id,
                $participants->pluck('alliance_id')->filter()->unique()->values()->all(),
            ),
        ];
    }

    /**
     * Overlay operator side overrides on top of the auto-resolver
     * output. Returns ``[newSides, filteredParticipants, excludedCharIds]``.
     *
     * Precedence is character > corporation > alliance. ``exclude``
     * at any level drops the entity's pilots from the report entirely.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  \Illuminate\Support\Collection<int, \App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride>  $overrides
     * @return array{0: BattleTheaterSideResolution, 1: Collection<int, BattleTheaterParticipant>, 2: list<int>}
     */
    private function applyOverrides(
        BattleTheaterSideResolution $sides,
        Collection $participants,
        Collection $overrides,
    ): array {
        if ($overrides->isEmpty()) {
            return [$sides, $participants, []];
        }

        // Bucket overrides by entity type. Character / corp / alliance
        // are applied in that precedence order — a character-level
        // override wins over a corp-level override for the same pilot.
        $charOv = [];
        $corpOv = [];
        $allOv = [];
        foreach ($overrides as $o) {
            $map = match ($o->entity_type) {
                \App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride::ENTITY_CHARACTER => 'charOv',
                \App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride::ENTITY_CORPORATION => 'corpOv',
                \App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride::ENTITY_ALLIANCE => 'allOv',
                default => null,
            };
            if ($map === null) {
                continue;
            }
            ${$map}[(int) $o->entity_id] = $o->side;
        }

        $excludedCharIds = [];
        $newSideByChar = $sides->sideByCharacterId;
        $keptParticipants = collect();

        foreach ($participants as $p) {
            $cid = (int) $p->character_id;
            $corp = (int) ($p->corporation_id ?? 0);
            $all = (int) ($p->alliance_id ?? 0);

            // Resolve override side by precedence. Missing = auto.
            $overrideSide = $charOv[$cid]
                ?? ($corp > 0 ? ($corpOv[$corp] ?? null) : null)
                ?? ($all > 0 ? ($allOv[$all] ?? null) : null);

            if ($overrideSide === \App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride::SIDE_EXCLUDE) {
                // Dropped entirely — no row in participants, no side.
                unset($newSideByChar[$cid]);
                $excludedCharIds[] = $cid;
                continue;
            }

            if ($overrideSide !== null) {
                $newSideByChar[$cid] = $overrideSide;
            }
            $keptParticipants->push($p);
        }

        $newSides = new BattleTheaterSideResolution(
            sideByCharacterId: $newSideByChar,
            sideABlocId: $sides->sideABlocId,
            sideBBlocId: $sides->sideBBlocId,
            allianceToBloc: $sides->allianceToBloc,
        );

        return [$newSides, $keptParticipants->values(), $excludedCharIds];
    }

    /**
     * Fold capsule kills + structure kills into the participants
     * collection so the ISK totals reconcile with the theater's
     * totalValue (spec § 5.1 + § 5.2).
     *
     * Capsules — victim_character_id is set and (on normal fights)
     * matches a participant whose ship kill is already tracked. We
     * add the capsule's totalValue to that participant's isk_lost
     * and bump ``deaths``. The capsule's own row is NOT added — it
     * belongs to the same pilot.
     *
     * Structures — victim_character_id is NULL; we synthesise a
     * participant with character_id = -killmail_id, corporation_id
     * + alliance_id from the killmail, and a display label pulled
     * from ``ref_item_types`` so the roster shows something like
     * "Skyhook".
     *
     * Returns the list of synthetic-char-id → label mappings so the
     * caller can merge them into the entity-names collection the
     * blade reads.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @return array{structure_names: array<int, string>}
     */
    private function hydrateCapsuleAndStructureKills(
        BattleTheater $theater,
        Collection $participants,
    ): array {
        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theater->id)
            ->pluck('killmail_id')
            ->all();
        $out = ['structure_names' => []];
        if ($killmailIds === []) {
            return $out;
        }

        // --- Capsules ------------------------------------------------
        // ref_item_groups.id 29 = "Capsule" family. Pod and Capsule
        // (Genolution 'Auroral' 197-variant) all roll up under it.
        $capsules = DB::table('killmails as k')
            ->join('ref_item_types as t', 't.id', '=', 'k.victim_ship_type_id')
            ->whereIn('k.killmail_id', $killmailIds)
            ->where('t.group_id', 29)
            ->whereNotNull('k.victim_character_id')
            ->select(['k.killmail_id', 'k.victim_character_id', 'k.total_value'])
            ->get();

        $byChar = $participants->keyBy(fn ($p) => (int) $p->character_id);
        foreach ($capsules as $c) {
            $cid = (int) $c->victim_character_id;
            $p = $byChar->get($cid);
            if ($p === null) {
                continue; // pilot not in participants — leave for the structure path / NPC data
            }
            $p->isk_lost = (float) $p->isk_lost + (float) $c->total_value;
            $p->deaths = (int) $p->deaths + 1;
        }

        // --- Structures ---------------------------------------------
        // victim_character_id IS NULL ⇒ structure / deployable /
        // mobile depot. We project one synthetic participant row per
        // killmail so the roster + ISK totals reflect the loss.
        $structures = DB::table('killmails as k')
            ->leftJoin('ref_item_types as t', 't.id', '=', 'k.victim_ship_type_id')
            ->whereIn('k.killmail_id', $killmailIds)
            ->whereNull('k.victim_character_id')
            ->whereNotNull('k.victim_corporation_id')
            ->select([
                'k.killmail_id',
                'k.victim_corporation_id as corp',
                'k.victim_alliance_id as alliance',
                'k.victim_ship_type_id as ship_type_id',
                'k.total_value as isk',
                't.name as ship_type_name',
            ])
            ->get();

        foreach ($structures as $s) {
            $syntheticCid = -((int) $s->killmail_id); // unique, negative to avoid collision
            $row = new BattleTheaterParticipant();
            $row->theater_id = $theater->id;
            $row->character_id = $syntheticCid;
            $row->corporation_id = $s->corp ? (int) $s->corp : null;
            $row->alliance_id = $s->alliance ? (int) $s->alliance : null;
            $row->kills = 0;
            $row->final_blows = 0;
            $row->damage_done = 0;
            $row->damage_taken = 0;
            $row->deaths = 1;
            $row->isk_lost = (float) $s->isk;
            // ``exists = false`` so Eloquent never considers this a
            // persisted row and never tries to save on touch.
            $row->exists = false;
            $participants->push($row);

            $label = $s->ship_type_name ?? 'Structure';
            $out['structure_names'][$syntheticCid] = $label;
        }

        return $out;
    }

    /**
     * Patch participant rows whose affiliation fields are zero/null
     * by looking up the character's alliance + corp from the
     * killmail data (which always carries the truth for on-field
     * pilots). Pure in-memory mutation; the DB is never written.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     */
    private function hydrateParticipantAffiliations(BattleTheater $theater, Collection $participants): void
    {
        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theater->id)
            ->pluck('killmail_id')
            ->all();
        if ($killmailIds === []) {
            return;
        }

        // Char -> [corp, alliance]. Victim side is authoritative
        // because victim rows on killmails carry both fields; the
        // attackers side is a fallback that contributes attacker
        // pilots whose corp/alliance is known.
        $truth = [];
        DB::table('killmails')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('victim_character_id')
            ->select([
                'victim_character_id as cid',
                'victim_corporation_id as corp',
                'victim_alliance_id as all',
            ])
            ->get()
            ->each(function ($r) use (&$truth): void {
                $cid = (int) $r->cid;
                $truth[$cid] = [
                    'corp' => $r->corp !== null ? (int) $r->corp : null,
                    'alliance' => $r->all !== null ? (int) $r->all : null,
                ];
            });
        DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('character_id')
            ->select(['character_id as cid', 'corporation_id as corp', 'alliance_id as all'])
            ->get()
            ->each(function ($r) use (&$truth): void {
                $cid = (int) $r->cid;
                // Only fill in when we don't already have a victim
                // record for this char — victim rows are richer on
                // ordinary killmails.
                if (isset($truth[$cid])) {
                    return;
                }
                $truth[$cid] = [
                    'corp' => $r->corp !== null ? (int) $r->corp : null,
                    'alliance' => $r->all !== null ? (int) $r->all : null,
                ];
            });

        foreach ($participants as $p) {
            $cid = (int) $p->character_id;
            $t = $truth[$cid] ?? null;
            if ($t === null) {
                continue;
            }
            if ((int) ($p->alliance_id ?? 0) === 0 && $t['alliance'] !== null) {
                $p->alliance_id = $t['alliance'];
            }
            if ((int) ($p->corporation_id ?? 0) === 0 && $t['corp'] !== null) {
                $p->corporation_id = $t['corp'];
            }
        }
    }

    // ------------------------------------------------------------------ //
    // Everything below is copy of the former private methods on
    // BattleTheaterDetail. Moved here so the public controller doesn't
    // have to duplicate them. The method signatures + behaviour are
    // unchanged — tests in tests/Feature/Domains/KillmailsBattleTheaters
    // still cover them end-to-end via the Filament page.
    // ------------------------------------------------------------------ //

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
        $rows = DB::table('killmails as k')
            ->leftJoin('ref_item_types as t', 't.id', '=', 'k.victim_ship_type_id')
            ->whereIn('k.killmail_id', $killmailIds)
            ->select([
                'k.killmail_id', 'k.killed_at', 'k.solar_system_id',
                'k.victim_character_id', 'k.victim_corporation_id', 'k.victim_alliance_id',
                'k.victim_ship_type_id', 'k.total_value', 'k.attacker_count',
                't.group_id as ship_group_id',
            ])
            ->orderBy('k.killed_at')
            ->get();
        $finalBlows = DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->where('is_final_blow', true)
            ->select(['killmail_id', 'character_id', 'alliance_id', 'corporation_id', 'ship_type_id'])
            ->get()
            ->keyBy('killmail_id');

        // Collapse capsules into their parent ship kill. For each
        // capsule km (ref_item_groups.id=29), find the same pilot's
        // most recent non-capsule kill in the same theater within
        // 120s; attach the capsule's totalValue to that ship's feed
        // row and skip emitting the capsule as a separate row.
        // Standalone pods (no matching ship kill in window) stay
        // visible.
        $capsuleSkip = [];                 // km_id => true
        $podAttachment = [];               // parent_km_id => ['ship_type_id' => X, 'total_value' => Y]
        $rowsByKm = $rows->keyBy('killmail_id');
        foreach ($rows as $r) {
            if ((int) ($r->ship_group_id ?? 0) !== 29) {
                continue;
            }
            if (! $r->victim_character_id) {
                continue;
            }
            $capsuleAt = strtotime((string) $r->killed_at);
            $parent = null;
            foreach ($rows as $other) {
                if ((int) $other->killmail_id === (int) $r->killmail_id) {
                    continue;
                }
                if ((int) ($other->ship_group_id ?? 0) === 29) {
                    continue;
                }
                if ((int) ($other->victim_character_id ?? 0) !== (int) $r->victim_character_id) {
                    continue;
                }
                $delta = $capsuleAt - strtotime((string) $other->killed_at);
                if ($delta < 0 || $delta > 120) {
                    continue;
                }
                $parent = $other;
                break;
            }
            if ($parent !== null) {
                $capsuleSkip[(int) $r->killmail_id] = true;
                $pid = (int) $parent->killmail_id;
                $existing = $podAttachment[$pid] ?? ['ship_type_id' => (int) $r->victim_ship_type_id, 'total_value' => 0.0];
                $existing['total_value'] += (float) $r->total_value;
                $podAttachment[$pid] = $existing;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $kmId = (int) $r->killmail_id;
            if (isset($capsuleSkip[$kmId])) {
                continue;
            }
            $fb = $finalBlows->get($r->killmail_id);
            $pod = $podAttachment[$kmId] ?? null;
            $out[] = [
                'killmail_id' => $kmId,
                'killed_at' => $r->killed_at,
                'system_id' => (int) $r->solar_system_id,
                'victim_id' => $r->victim_character_id ? (int) $r->victim_character_id : null,
                'victim_name' => $r->victim_character_id ? ($names[(int) $r->victim_character_id] ?? '#'.$r->victim_character_id) : '(NPC)',
                'victim_corp_id' => $r->victim_corporation_id ? (int) $r->victim_corporation_id : null,
                'victim_alliance_id' => $r->victim_alliance_id ? (int) $r->victim_alliance_id : null,
                'ship_type_id' => (int) $r->victim_ship_type_id,
                'ship_name' => $shipNames[(int) $r->victim_ship_type_id] ?? '#'.$r->victim_ship_type_id,
                // total_value rolls in the linked pod so the kill
                // feed row shows the combined ship+pod ISK.
                'total_value' => (float) $r->total_value + ($pod['total_value'] ?? 0.0),
                'attacker_count' => (int) $r->attacker_count,
                'final_blow_char_id' => $fb && $fb->character_id ? (int) $fb->character_id : null,
                'final_blow_name' => $fb && $fb->character_id ? ($names[(int) $fb->character_id] ?? '#'.$fb->character_id) : null,
                'final_blow_alliance_id' => $fb && $fb->alliance_id ? (int) $fb->alliance_id : null,
                'final_blow_ship_id' => $fb ? (int) $fb->ship_type_id : null,
                'pod_ship_type_id' => $pod['ship_type_id'] ?? null,
                'pod_value' => $pod['total_value'] ?? 0.0,
            ];
        }
        return $out;
    }

    /**
     * Side-level totals. Per domain spec § 2.1 (N-side
     * attacker-participation model):
     *
     *   isk_lost(S)   = sum(totalValue) over killmails where victim ∈ S
     *   isk_killed(S) = sum(totalValue) over killmails where victim ∉ S
     *                   AND at least one attacker ∈ S
     *                   (precomputed by buildIskKilledBySide)
     *   kills         = sum of per-pilot kill involvements on the side
     *   final_blows   = sum of per-pilot final_blows
     *                   (FB is unique per km, so this equals the
     *                    distinct-km count for the side)
     *
     * Balancing identities (spec § 9):
     *   sum(isk_lost across sides) = theater_total
     *   sum(isk_killed across sides) ≥ theater_total  (equality iff
     *     every km has attackers from exactly one side)
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<string, float>  $iskKilledBySide  side → ISK killed
     * @return array<string, array<string, int|float>>
     */
    private function computeSideTotals(
        Collection $participants,
        BattleTheaterSideResolution $sides,
        array $iskKilledBySide,
        array $killsBySide = [],
    ): array {
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
            // NB: we do NOT sum pilot.kills here — that would count
            // blue-on-blue involvements. Side-level kills is the
            // distinct-km count computed below and passed in via
            // $killsBySide (spec § 8).
            $totals[$side]['final_blows'] += (int) $p->final_blows;
            $totals[$side]['deaths'] += (int) $p->deaths;
            $totals[$side]['damage_done'] += (int) $p->damage_done;
            $totals[$side]['damage_taken'] += (int) $p->damage_taken;
            $totals[$side]['isk_lost'] += (float) $p->isk_lost;
        }

        foreach ([BattleTheaterSideResolver::SIDE_A, BattleTheaterSideResolver::SIDE_B, BattleTheaterSideResolver::SIDE_C] as $s) {
            $totals[$s]['isk_killed'] = (float) ($iskKilledBySide[$s] ?? 0.0);
            $totals[$s]['kills'] = (int) ($killsBySide[$s] ?? 0);
        }

        return $totals;
    }

    /**
     * Spec § 2.1 (final-blow attribution) + § 8 — side-level ISK
     * Killed and Kills counts.
     *
     * Rule: exactly one pilot lands the final blow on each killmail.
     * That pilot's side gets the full totalValue as ISK Killed and
     * +1 on the side's distinct-km Kills counter. No double-
     * counting, no shared credit, no blue-on-blue filter — if a
     * Side C pilot lands FB on another Side C ship, Side C gets
     * the ISK (self-destruction still destroyed value).
     *
     * Balancing identity (spec § 9.1 + 9.2):
     *
     *   sum(isk_killed across sides) + orphan_isk = total_theater_value
     *   orphan_isk = sum(totalValue) for kms whose FB attacker has
     *                no character_id (NPC / unenriched)
     *
     * When every km has a character-id FB, sum(isk_killed) =
     * sum(isk_lost) exactly.
     *
     * Per-pilot kill involvements (§ 3) are independent of this —
     * every attacker still gets +1 in their pilot row regardless
     * of which side earned the side-level ISK credit.
     *
     * @param  list<int>  $killmailIds
     * @return array{isk: array<string, float>, kills: array<string, int>}
     */
    private function buildKillCountsBySide(array $killmailIds, BattleTheaterSideResolution $sides): array
    {
        $out = [
            'isk' => [
                BattleTheaterSideResolver::SIDE_A => 0.0,
                BattleTheaterSideResolver::SIDE_B => 0.0,
                BattleTheaterSideResolver::SIDE_C => 0.0,
            ],
            'kills' => [
                BattleTheaterSideResolver::SIDE_A => 0,
                BattleTheaterSideResolver::SIDE_B => 0,
                BattleTheaterSideResolver::SIDE_C => 0,
            ],
        ];
        if ($killmailIds === []) {
            return $out;
        }

        // Load killmail meta (victim, time, ship group) + totalValue
        // so we can both credit by FB and rescue capsule kills that
        // have no character FB by linking them back to the parent
        // ship kill.
        $kmMeta = DB::table('killmails as k')
            ->leftJoin('ref_item_types as t', 't.id', '=', 'k.victim_ship_type_id')
            ->whereIn('k.killmail_id', $killmailIds)
            ->select([
                'k.killmail_id',
                'k.killed_at',
                'k.victim_character_id',
                'k.total_value',
                't.group_id as ship_group_id',
            ])
            ->get()
            ->keyBy('killmail_id');

        // Map km → FB character id (only rows with a named FB).
        $fbCharByKm = [];
        DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->where('is_final_blow', true)
            ->whereNotNull('character_id')
            ->select(['killmail_id', 'character_id'])
            ->get()
            ->each(function ($r) use (&$fbCharByKm): void {
                $fbCharByKm[(int) $r->killmail_id] = (int) $r->character_id;
            });

        // For capsule kills that lack a character-id FB (NPC /
        // unenriched), link back to the same pilot's ship kill in
        // this theater within 120s and inherit that ship's FB side
        // — spec § 5.1 capsule-follows-ship. ref_item_groups.id 29
        // is the Capsule family.
        $capsuleLinked = [];
        foreach ($kmMeta as $kmId => $row) {
            $kmId = (int) $kmId;
            if (isset($fbCharByKm[$kmId])) {
                continue;
            }
            if ((int) ($row->ship_group_id ?? 0) !== 29) {
                continue;
            }
            if (! $row->victim_character_id) {
                continue;
            }
            $pilotId = (int) $row->victim_character_id;
            $capsuleAt = strtotime((string) $row->killed_at);
            // Find the most-recent ship kill (ship_group_id != 29)
            // for the same pilot within 120s before the capsule.
            foreach ($kmMeta as $otherId => $other) {
                $otherId = (int) $otherId;
                if ($otherId === $kmId) {
                    continue;
                }
                if ((int) ($other->ship_group_id ?? 0) === 29) {
                    continue;
                }
                if ((int) ($other->victim_character_id ?? 0) !== $pilotId) {
                    continue;
                }
                $delta = $capsuleAt - strtotime((string) $other->killed_at);
                if ($delta < 0 || $delta > 120) {
                    continue;
                }
                $parentFb = $fbCharByKm[$otherId] ?? null;
                if ($parentFb !== null) {
                    $capsuleLinked[$kmId] = $parentFb;
                    break;
                }
            }
        }

        // Credit each side. FB character first; capsule-linked
        // parent FB as fallback for the pod kms.
        foreach ($kmMeta as $kmId => $row) {
            $kmId = (int) $kmId;
            $charId = $fbCharByKm[$kmId] ?? $capsuleLinked[$kmId] ?? null;
            if ($charId === null) {
                continue; // orphan — no named attacker / no linked parent
            }
            $side = $sides->sideByCharacterId[$charId] ?? BattleTheaterSideResolver::SIDE_C;
            $out['isk'][$side] += (float) $row->total_value;
            $out['kills'][$side] += 1;
        }

        return $out;
    }

    /**
     * @param  list<int>  $killmailIds
     * @return array<string, float>
     */
    /**
     * Alliance-level kill involvements per spec § 5.4 + § 8:
     *
     *   Kill_Involvements(Alliance) = COUNT(DISTINCT killmail_id)
     *                                 WHERE any member of Alliance is
     *                                 on the attacker list
     *                                 AND victim is NOT on the
     *                                 alliance's side
     *
     * The "not on this alliance's side" clause drops blue-on-blue
     * (same-side friendly fire) from the kill-involvement count so
     * the number reflects adversarial hits only.
     *
     * @param  list<int>  $killmailIds
     * @return array<int, int>  alliance_id → distinct killmail count
     */
    private function buildAllianceKillInvolvements(array $killmailIds, BattleTheaterSideResolution $sides): array
    {
        if ($killmailIds === []) {
            return [];
        }

        // km → victim side (for blue-on-blue filter below).
        $victimSidePerKm = [];
        DB::table('killmails')
            ->whereIn('killmail_id', $killmailIds)
            ->select(['killmail_id', 'victim_character_id'])
            ->get()
            ->each(function ($r) use (&$victimSidePerKm, $sides): void {
                $vid = $r->victim_character_id ? (int) $r->victim_character_id : null;
                $victimSidePerKm[(int) $r->killmail_id] = $vid !== null
                    ? ($sides->sideByCharacterId[$vid] ?? BattleTheaterSideResolver::SIDE_C)
                    : BattleTheaterSideResolver::SIDE_C;
            });

        // Alliance → its side. An alliance's side is the side its
        // pilots were assigned to by the resolver; pull from the
        // first-seen pilot per alliance (resolver puts every pilot
        // of a given alliance on the same side via either bloc or
        // alliance-anchor clustering).
        $allianceSide = [];
        // seed from sideByCharacterId + killmail_attackers join is
        // cheap here — scan once.
        DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('alliance_id')
            ->whereNotNull('character_id')
            ->select(['alliance_id', 'character_id'])
            ->get()
            ->each(function ($r) use (&$allianceSide, $sides): void {
                $aid = (int) $r->alliance_id;
                if (isset($allianceSide[$aid])) {
                    return;
                }
                $allianceSide[$aid] = $sides->sideByCharacterId[(int) $r->character_id] ?? BattleTheaterSideResolver::SIDE_C;
            });

        $seen = [];   // alliance_id → [killmail_id => true]
        DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('alliance_id')
            ->select(['alliance_id', 'killmail_id'])
            ->get()
            ->each(function ($row) use (&$seen, $victimSidePerKm, $allianceSide): void {
                $aid = (int) $row->alliance_id;
                $kmId = (int) $row->killmail_id;
                $victimSide = $victimSidePerKm[$kmId] ?? BattleTheaterSideResolver::SIDE_C;
                $allySide = $allianceSide[$aid] ?? null;
                // Filter same-side: if the alliance is on Side A and
                // the victim is also on Side A, don't count.
                if ($allySide !== null && $allySide === $victimSide) {
                    return;
                }
                $seen[$aid][$kmId] = true;
            });

        $out = [];
        foreach ($seen as $aid => $kmMap) {
            $out[$aid] = count($kmMap);
        }
        return $out;
    }

    /**
     * Alliance roster rollup.
     *
     *   pilots      — distinct character_ids per alliance on the side
     *   kills       — COUNT(DISTINCT killmail_id) where any member of
     *                 alliance is on the attacker list (passed in as
     *                 ``$allianceKillInvolvements``; computed by the
     *                 caller from killmail_attackers so fleet-size
     *                 inflation is eliminated — spec § 5.4).
     *   deaths      — sum of per-pilot deaths on the side
     *   isk_lost    — sum of per-pilot isk_lost
     *   damage_done / damage_taken — raw HP sums (display only)
     *
     * @param  array<int, int>  $allianceKillInvolvements  alliance_id → distinct km count
     * @return Collection<int, array<string, mixed>>
     */
    private function buildAllianceRollup(
        Collection $participants,
        Collection $names,
        BattleTheaterSideResolution $sides,
        array $allianceKillInvolvements = [],
    ): Collection {
        $byAlliance = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            $side = $sides->sideByCharacterId[(int) $p->character_id] ?? BattleTheaterSideResolver::SIDE_C;
            $row = $byAlliance[$aid] ?? [
                'alliance_id' => $aid,
                'alliance_name' => $aid > 0 ? ($names[$aid] ?? "Alliance #{$aid}") : 'No alliance (individuals)',
                'is_individuals' => $aid === 0,
                'side' => $side,
                'pilots' => 0,
                'kills' => 0,
                'deaths' => 0,
                'isk_lost' => 0.0,
                'damage_done' => 0,
                'damage_taken' => 0,
            ];
            $row['pilots']++;
            $row['deaths'] += (int) $p->deaths;
            $row['damage_done'] += (int) $p->damage_done;
            $row['damage_taken'] += (int) $p->damage_taken;
            $row['isk_lost'] += (float) $p->isk_lost;
            $byAlliance[$aid] = $row;
        }
        // Overlay the distinct-killmail count from the kill-graph
        // aggregation rather than the (inflated) per-pilot sum.
        foreach ($byAlliance as $aid => &$row) {
            $row['kills'] = $allianceKillInvolvements[$aid] ?? 0;
        }
        unset($row);

        // Sort: named alliances first (by isk_lost desc), then the
        // "No alliance (individuals)" catch-all last. This keeps
        // the interesting movers at the top and buries the
        // 24-pilot-1.28B-mostly-noobs bucket that otherwise
        // visually dominates the roster.
        return collect(array_values($byAlliance))
            ->sortBy([
                ['is_individuals', 'asc'],   // false (0) before true (1)
                ['isk_lost', 'desc'],
            ])
            ->values();
    }

    /**
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
     * @param  array<int, array<string, mixed>>  $killFeed
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildMostValuableKills(array $killFeed, BattleTheaterSideResolution $sides): array
    {
        // Credit the kill to the final-blower's side (§ 2.1 spec
        // revision). Fall back to "whoever wasn't the victim's
        // side" for kills with no FB character (NPC final blows) so
        // the card isn't empty in fights where NPCs cleaned up.
        $buckets = [
            BattleTheaterSideResolver::SIDE_A => [],
            BattleTheaterSideResolver::SIDE_B => [],
            BattleTheaterSideResolver::SIDE_C => [],
        ];
        foreach ($killFeed as $km) {
            $fbCid = (int) ($km['final_blow_char_id'] ?? 0);
            $creditSide = $fbCid > 0 ? ($sides->sideByCharacterId[$fbCid] ?? null) : null;
            if ($creditSide === null) {
                $victimSide = $km['victim_id'] ? ($sides->sideByCharacterId[(int) $km['victim_id']] ?? null) : null;
                if ($victimSide === BattleTheaterSideResolver::SIDE_A) {
                    $creditSide = BattleTheaterSideResolver::SIDE_B;
                } elseif ($victimSide === BattleTheaterSideResolver::SIDE_B) {
                    $creditSide = BattleTheaterSideResolver::SIDE_A;
                } else {
                    continue;
                }
            }
            $buckets[$creditSide][] = $km;
        }
        foreach ($buckets as $k => $rows) {
            usort($rows, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);
            $buckets[$k] = array_slice($rows, 0, 3);
        }
        return $buckets;
    }

    /**
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
            BattleTheaterSideResolver::SIDE_C => [],
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
     * Flagship alliance per side — used for the big logo + name on
     * the VS banner. Picks the alliance that contributed the most
     * to the side's outcome:
     *
     *   1. biggest ISK lost (the side's biggest victim is the side's
     *      real story — "who got blapped here"),
     *   2. fall back to biggest kills (attack volume) when nobody
     *      on the side lost anything,
     *   3. fall back to biggest pilot count when neither metric
     *      differentiates.
     *
     * Previous version picked by pilot count, which made a 4-Ibis
     * Goonswarm contingent the "headline" on a side where Brave
     * Collective actually lost 2.4B.
     *
     * @return array<string, array{alliance_id: int, alliance_name: string, pilots: int, isk_lost: float, kills: int}|null>
     */
    private function buildFlagshipLogos(Collection $allianceRows): array
    {
        $pick = function (string $side) use ($allianceRows): ?array {
            $rows = $allianceRows
                ->where('side', $side)
                ->where('alliance_id', '>', 0)
                ->values();
            if ($rows->isEmpty()) {
                return null;
            }
            // Primary signal: ISK lost. Whoever bled the most on
            // this side is the side's lead story.
            $byLoss = $rows->where('isk_lost', '>', 0)->sortByDesc('isk_lost');
            if ($byLoss->isNotEmpty()) {
                $r = $byLoss->first();
            } else {
                // Nobody on this side lost anything → pick the
                // alliance that landed the most kills. If nobody
                // killed anything either, fall back to pilot count.
                $r = $rows->sortByDesc('kills')->first();
                if ((int) $r['kills'] === 0) {
                    $r = $rows->sortByDesc('pilots')->first();
                }
            }
            return [
                'alliance_id' => (int) $r['alliance_id'],
                'alliance_name' => (string) $r['alliance_name'],
                'pilots' => (int) $r['pilots'],
                'isk_lost' => (float) $r['isk_lost'],
                'kills' => (int) $r['kills'],
            ];
        };
        return [
            BattleTheaterSideResolver::SIDE_A => $pick(BattleTheaterSideResolver::SIDE_A),
            BattleTheaterSideResolver::SIDE_B => $pick(BattleTheaterSideResolver::SIDE_B),
            BattleTheaterSideResolver::SIDE_C => $pick(BattleTheaterSideResolver::SIDE_C),
        ];
    }

    /**
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
