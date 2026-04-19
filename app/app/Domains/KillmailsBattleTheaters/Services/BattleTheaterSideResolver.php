<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\BlocIntel\Services\BlocRelationshipService;
use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Models\BattleTheaterParticipant;
use App\Domains\KillmailsBattleTheaters\Services\AllegianceGraphService;
use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\ViewerContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Viewer-relative side resolver for a battle theater.
 *
 * See ADR-0006 § 2. The battle theater itself is agnostic — pilots have
 * alliances, period. Sides are computed at read time based on who the
 * viewer is:
 *
 *   Side A — pilots whose alliance maps to the VIEWER's bloc.
 *   Side B — pilots whose alliance maps to the dominant OPPOSING bloc
 *            (the non-viewer bloc that absorbed the most ISK loss in
 *            this theater — "who they were fighting").
 *   Side C — everyone else. Third parties, unaligned, unknown blocs.
 *
 * A viewer with no confirmed bloc gets the two largest blocs in the
 * theater by ISK_lost, with the UI free to let them swap which is A
 * vs B (the swap is ephemeral UI state, not stored).
 *
 * The resolver never mutates anything — pure function of (participants,
 * labels, viewer). Called once per detail-page render; the cost is
 * dominated by a single WHERE IN query against coalition_entity_labels
 * scoped to this theater's alliance_ids.
 */
final class BattleTheaterSideResolver
{
    public const SIDE_A = 'A';

    /**
     * Minimum number of pilots from the viewer's bloc on field before
     * the viewer-affinity framing kicks in. Below this, the report
     * falls through to neutral kill-data resolution so 1-2 strays
     * don't label a 200-pilot fight as "ours".
     */
    private const VIEWER_BLOC_MIN_PILOTS = 5;
    public const SIDE_B = 'B';
    public const SIDE_C = 'C';

    public function __construct(
        private readonly ?AllegianceGraphService $allegiance = null,
        private readonly ?BlocRelationshipService $blocIntel = null,
    ) {}

    /**
     * Result: for each participant (keyed by character_id), the side
     * they belong to for this render. Includes the bloc labels used
     * so the UI can show "Side A: WinterCo (12 pilots)".
     *
     * Pass ``$participants`` when the caller has already hydrated the
     * collection (patched alliance_id from killmail truth, pushed
     * synthetic structure rows, etc.) — otherwise the resolver
     * re-queries the DB and sees pre-hydration zeros, which placed
     * correctly-hydrated pilots on the wrong side because
     * buildAllianceRollup pins an alliance's side to the first-
     * encountered pilot (2026-04-17 incident).
     *
     * @param  Collection<int, BattleTheaterParticipant>|null  $participants
     */
    public function resolve(
        BattleTheater $theater,
        ?ViewerContext $viewer,
        ?Collection $participants = null,
    ): BattleTheaterSideResolution {
        if ($participants === null) {
            /** @var Collection<int, BattleTheaterParticipant> $participants */
            $participants = $theater->participants()->get();
        }
        if ($participants->isEmpty()) {
            return BattleTheaterSideResolution::empty();
        }

        // Collect every distinct alliance_id that appeared.
        $allianceIds = $participants
            ->pluck('alliance_id')
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        // alliance_id -> bloc_id (via coalition_entity_labels, alliance
        // type, active only). Non-aliased alliances are implicitly
        // "unmapped" and fall to Side C unless they're the viewer's
        // own alliance (handled below via ViewerContext).
        $allianceToBloc = [];
        if ($allianceIds !== []) {
            CoalitionEntityLabel::query()
                ->where('entity_type', CoalitionEntityLabel::ENTITY_ALLIANCE)
                ->where('is_active', true)
                ->whereIn('entity_id', $allianceIds)
                ->get(['entity_id', 'bloc_id'])
                ->each(function (CoalitionEntityLabel $row) use (&$allianceToBloc): void {
                    // First-label-wins. A later pass could honour
                    // relationship precedence (member > affiliate > …),
                    // but for side assignment we only need bloc
                    // membership, not role — any mapped bloc is enough.
                    $allianceToBloc[(int) $row->entity_id] ??= (int) $row->bloc_id;
                });
        }

        // Per-bloc ISK-lost totals. Drives the "dominant opposing bloc"
        // pick when the viewer has no bloc set, and the "which bloc
        // labels to surface" step below.
        $iskPerBloc = [];
        foreach ($participants as $p) {
            $blocId = $allianceToBloc[(int) $p->alliance_id] ?? null;
            if ($blocId === null) {
                continue;
            }
            $iskPerBloc[$blocId] = ($iskPerBloc[$blocId] ?? 0) + (float) $p->isk_lost;
        }

        $viewerBlocId = $viewer?->bloc_id !== null && ! $viewer->bloc_unresolved
            ? (int) $viewer->bloc_id
            : null;

        // Viewer-with-bloc path. Corp + alliance standings are the
        // operator's own curated "who's blue/red" answer, so they
        // decide first. Everything unlabelled by standings (or with
        // a neutral ±5 window) falls through to kill graph + Neo4j
        // so the resolver can still place blobby third parties even
        // when the operator hasn't explicitly tagged them.
        if ($viewerBlocId !== null) {
            // Only apply viewer-affinity framing if the viewer's bloc
            // actually fielded a fleet. Random 1-2 pilots who happened
            // to warp through shouldn't make the battle "ours" and
            // shouldn't stamp "Side A: WinterCo / 2 pilots / 0 kills"
            // on a 200-pilot fight the viewer's bloc never engaged.
            // Fall through to neutral kill-data resolution below the
            // threshold.
            $viewerBlocPilots = 0;
            foreach ($participants as $p) {
                $aid = (int) ($p->alliance_id ?? 0);
                if ($aid > 0 && ($allianceToBloc[$aid] ?? null) === $viewerBlocId) {
                    $viewerBlocPilots++;
                }
            }
            if ($viewerBlocPilots >= self::VIEWER_BLOC_MIN_PILOTS) {
                return $this->resolveByViewerAffinity(
                    theater: $theater,
                    participants: $participants,
                    allianceToBloc: $allianceToBloc,
                    iskPerBloc: $iskPerBloc,
                    viewer: $viewer,
                    viewerBlocId: $viewerBlocId,
                );
            }
        }

        // Anonymous / no-viewer-bloc path — everything below treats
        // the killmail table as the single source of truth for who's
        // on the field (victim or attacker). The persisted
        // ``battle_theater_participants`` rows are consulted only for
        // per-pilot metrics downstream — alliance / corp affiliation
        // comes from killmails because those are always filled.
        return $this->resolveFromKillData($theater, $participants, $allianceToBloc);
    }

    /**
     * End-to-end resolution from killmail data, used when no viewer
     * bloc is set.
     *
     * Steps:
     *   1. Read ``charToAlliance`` from killmails (victim side) +
     *      killmail_attackers. That's everyone who was on the field.
     *   2. Try the kill-graph pairing (``resolveByKillGraph``). When
     *      that succeeds, we have a Side A / B with third parties.
     *   3. Kill-graph can't pair when attackers are all NPCs or
     *      unenriched. Fall back to two-largest-alliances by pilot
     *      count over the same ``charToAlliance`` map. If only one
     *      alliance is present, everyone lands on Side A and Side B
     *      legitimately stays empty (the UI will show "no opposing
     *      side"). If zero alliances are known → Side C only.
     *
     * Either way, participant rows with broken alliance_id still get
     * the correct side assigned because assignment is keyed by
     * character_id, not by alliance_id pulled from the participant row.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     */
    private function resolveFromKillData(BattleTheater $theater, Collection $participants, array $allianceToBloc): BattleTheaterSideResolution
    {
        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theater->id)
            ->pluck('killmail_id')
            ->all();

        $charToAlliance = $killmailIds === [] ? [] : $this->buildCharToAlliance($killmailIds);

        // Kill-graph pairing first — it knows which alliances shot
        // which, so it can cluster real coalitions + detect third
        // parties that shot both sides.
        $graph = $this->resolveByKillGraph($theater, $killmailIds, $charToAlliance, $participants, $allianceToBloc);
        if ($graph !== null) {
            return $graph;
        }

        // Fallback: top-2-alliances-by-pilots over the killmail-
        // derived char→alliance map. Handles theaters where the
        // attackers were NPC / unenriched, or where one side simply
        // didn't shoot back.
        return $this->resolveByAlliance($charToAlliance, $participants, $allianceToBloc);
    }

    /**
     * Union of every character → their alliance, pulled from both
     * sides of every killmail in the theater. The returned map
     * excludes characters whose affiliation is null in the killmail.
     *
     * @param  list<int>  $killmailIds
     * @return array<int, int>
     */
    private function buildCharToAlliance(array $killmailIds): array
    {
        $charToAlliance = [];
        DB::table('killmails')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('victim_character_id')
            ->whereNotNull('victim_alliance_id')
            ->select(['victim_character_id as cid', 'victim_alliance_id as aid'])
            ->get()
            ->each(function ($r) use (&$charToAlliance): void {
                $charToAlliance[(int) $r->cid] = (int) $r->aid;
            });
        DB::table('killmail_attackers')
            ->whereIn('killmail_id', $killmailIds)
            ->whereNotNull('character_id')
            ->whereNotNull('alliance_id')
            ->select(['character_id as cid', 'alliance_id as aid'])
            ->get()
            ->each(function ($r) use (&$charToAlliance): void {
                $charToAlliance[(int) $r->cid] = (int) $r->aid;
            });

        return $charToAlliance;
    }

    /**
     * Alliance-level fallback used when the kill graph couldn't draw
     * a clean Side A / Side B pair. Picks up to the two alliances
     * with the most pilots over ``$charToAlliance``; everything else
     * → Side C.
     *
     * @param  array<int, int>  $charToAlliance  char → alliance from killmails
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     */
    private function resolveByAlliance(array $charToAlliance, Collection $participants, array $allianceToBloc): BattleTheaterSideResolution
    {
        $pilotsPerAlliance = [];
        foreach ($charToAlliance as $aid) {
            $pilotsPerAlliance[$aid] = ($pilotsPerAlliance[$aid] ?? 0) + 1;
        }
        arsort($pilotsPerAlliance);
        $topTwo = array_slice(array_keys($pilotsPerAlliance), 0, 2);
        $sideAId = $topTwo[0] ?? null;
        $sideBId = $topTwo[1] ?? null;

        $sideByChar = [];
        // Start with every participant — they still need a side
        // assigned even if they don't appear in charToAlliance.
        foreach ($participants as $p) {
            $cid = (int) $p->character_id;
            $aid = $charToAlliance[$cid] ?? (int) ($p->alliance_id ?? 0);
            $sideByChar[$cid] = $this->assignSide($aid, $sideAId, $sideBId);
        }
        // Add any on-field character that isn't in participants (e.g.
        // attackers with no participant row of their own).
        foreach ($charToAlliance as $cid => $aid) {
            if (! isset($sideByChar[$cid])) {
                $sideByChar[$cid] = $this->assignSide($aid, $sideAId, $sideBId);
            }
        }

        return new BattleTheaterSideResolution(
            sideByCharacterId: $sideByChar,
            sideABlocId: null,
            sideBBlocId: null,
            allianceToBloc: $allianceToBloc,
        );
    }

    private function assignSide(int $allianceId, ?int $sideAId, ?int $sideBId): string
    {
        if ($allianceId === 0) {
            return self::SIDE_C;
        }
        if ($sideAId !== null && $allianceId === $sideAId) {
            return self::SIDE_A;
        }
        if ($sideBId !== null && $allianceId === $sideBId) {
            return self::SIDE_B;
        }
        return self::SIDE_C;
    }

    /**
     * @deprecated kept to avoid a diff spike; resolveFromKillData is
     * the canonical entry point. Callers outside the resolver should
     * not rely on this signature.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     */
    private function resolveByAllianceLegacyUnused(Collection $participants, array $allianceToBloc): BattleTheaterSideResolution
    {
        $pilotsPerAlliance = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            if ($aid === 0) {
                continue; // no-alliance pilots can't anchor a side
            }
            $pilotsPerAlliance[$aid] = ($pilotsPerAlliance[$aid] ?? 0) + 1;
        }
        arsort($pilotsPerAlliance);
        $topTwo = array_slice(array_keys($pilotsPerAlliance), 0, 2);
        $sideAAllianceId = $topTwo[0] ?? null;
        $sideBAllianceId = $topTwo[1] ?? null;

        $sideByChar = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            if ($aid !== 0 && $aid === $sideAAllianceId) {
                $sideByChar[(int) $p->character_id] = self::SIDE_A;
            } elseif ($aid !== 0 && $aid === $sideBAllianceId) {
                $sideByChar[(int) $p->character_id] = self::SIDE_B;
            } else {
                $sideByChar[(int) $p->character_id] = self::SIDE_C;
            }
        }

        return new BattleTheaterSideResolution(
            sideByCharacterId: $sideByChar,
            sideABlocId: null,
            sideBBlocId: null,
            allianceToBloc: $allianceToBloc,
        );
    }

    /**
     * Side assignment via the kill graph. Ground truth = "who shot
     * whom". Anyone who appeared as an attacker OR as a victim on a
     * killmail in this theater is on the field — we read both sides
     * of every killmail directly rather than trusting the
     * persisted ``battle_theater_participants`` rows, which on small
     * fights can be missing alliance / corp data or the
     * attacker-side pilots entirely.
     *
     * The clustering path:
     *
     *   1. Build ``charToAlliance`` from killmails (victim side) +
     *      killmail_attackers (attacker side). This is every
     *      character that was on field, with their alliance.
     *   2. Pilots-per-alliance = count distinct characters per
     *      alliance. Side A = biggest.
     *   3. Side B = alliance with the most mutual kills vs Side A,
     *      restricted to alliances that have at least one on-field
     *      character (so we never pick an absent alliance whose name
     *      only appears as a victim's corp-to-alliance lookup).
     *   4. Classify every other alliance by shoot-direction against
     *      A and B (same 2× threshold as before). Neutral or "shot
     *      both" → Side C.
     *
     * Returns null on a genuinely empty / NPC-only theater so the
     * caller can fall back to the participant-table alliance split.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     */
    /**
     * @param  list<int>  $killmailIds
     * @param  array<int, int>  $charToAlliance  char → alliance from killmails
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     */
    private function resolveByKillGraph(
        BattleTheater $theater,
        array $killmailIds,
        array $charToAlliance,
        Collection $participants,
        array $allianceToBloc,
    ): ?BattleTheaterSideResolution {
        if ($killmailIds === [] || $charToAlliance === []) {
            return null;
        }

        // kills[attacker_alliance][victim_alliance] = count.
        $kills = [];
        DB::table('killmail_attackers as a')
            ->join('killmails as k', 'k.killmail_id', '=', 'a.killmail_id')
            ->whereIn('a.killmail_id', $killmailIds)
            ->whereNotNull('a.alliance_id')
            ->whereNotNull('k.victim_alliance_id')
            ->where('a.alliance_id', '!=', DB::raw('k.victim_alliance_id'))
            ->select('a.alliance_id as att', 'k.victim_alliance_id as vic')
            ->get()
            ->each(function ($r) use (&$kills): void {
                $att = (int) $r->att;
                $vic = (int) $r->vic;
                $kills[$att][$vic] = ($kills[$att][$vic] ?? 0) + 1;
            });

        // Pilots per alliance derived from WHO WAS ON FIELD (kill
        // data), not from the persisted participants table — the
        // latter is sometimes empty of affiliations.
        $pilotsPerAlliance = [];
        foreach ($charToAlliance as $aid) {
            $pilotsPerAlliance[$aid] = ($pilotsPerAlliance[$aid] ?? 0) + 1;
        }
        arsort($pilotsPerAlliance);
        $sideAId = (int) (array_key_first($pilotsPerAlliance) ?? 0);
        if ($sideAId === 0) {
            return null;
        }

        // Side B must be an alliance that (a) exchanged fire with
        // Side A and (b) had on-field characters. Dropping (b)
        // causes the "gank" case where Side A killed random
        // freighters whose alliance had no one returning fire.
        $onFieldAlliances = array_keys($pilotsPerAlliance);
        $mutualVsA = [];
        foreach ($kills[$sideAId] ?? [] as $vic => $n) {
            if (in_array($vic, $onFieldAlliances, true)) {
                $mutualVsA[$vic] = ($mutualVsA[$vic] ?? 0) + $n;
            }
        }
        foreach ($kills as $att => $vics) {
            if (! isset($vics[$sideAId])) {
                continue;
            }
            if (in_array($att, $onFieldAlliances, true)) {
                $mutualVsA[$att] = ($mutualVsA[$att] ?? 0) + $vics[$sideAId];
            }
        }
        unset($mutualVsA[$sideAId]);
        if ($mutualVsA === []) {
            return null;
        }
        arsort($mutualVsA);
        $sideBId = (int) array_key_first($mutualVsA);

        // Classify every other alliance by shoot-direction. Kill
        // graph first (highest-confidence signal in THIS fight);
        // anything it can't decide falls through to the historical
        // allegiance tiebreaker below.
        $allianceSide = [$sideAId => self::SIDE_A, $sideBId => self::SIDE_B];
        $allAlliances = array_merge(
            array_keys($kills),
            $onFieldAlliances,
        );
        foreach ($kills as $vics) {
            $allAlliances = array_merge($allAlliances, array_keys($vics));
        }
        $allAlliances = array_unique($allAlliances);
        foreach ($allAlliances as $aid) {
            if (isset($allianceSide[$aid])) {
                continue;
            }
            $againstA = $kills[$aid][$sideAId] ?? 0;
            $againstB = $kills[$aid][$sideBId] ?? 0;
            $losesToA = $kills[$sideAId][$aid] ?? 0;
            $losesToB = $kills[$sideBId][$aid] ?? 0;
            $supportsA = $againstB + $losesToB;
            $supportsB = $againstA + $losesToA;
            if ($supportsA >= 3 && $supportsA > 2 * $supportsB) {
                $allianceSide[$aid] = self::SIDE_A;
            } elseif ($supportsB >= 3 && $supportsB > 2 * $supportsA) {
                $allianceSide[$aid] = self::SIDE_B;
            }
        }

        // Bloc-intel rolling-window tiebreaker (MariaDB pair behavior).
        // Cheap, 90d decay-weighted. Catches alliances that didn't
        // cross fire in THIS theater but have a stable inferred
        // relationship with anchor A or B. Runs before Neo4j because
        // MariaDB answers faster when the signal exists.
        if ($this->blocIntel !== null) {
            foreach ($onFieldAlliances as $aid) {
                if (isset($allianceSide[$aid])) {
                    continue;
                }
                $side = $this->classifyByBlocIntel($aid, $sideAId, $sideBId);
                if ($side !== null) {
                    $allianceSide[$aid] = $side;
                }
            }
        }

        // Historical-allegiance tiebreaker. For every alliance still
        // unclassified AND on-field, ask the graph: "how often has X
        // been allied with / opposed to anchor A vs anchor B across
        // prior theaters?" If the historical signal is clear
        // (>=3 supporting events, 2x weight spread) we fold X into
        // that side. Neo4j down / no prior signal → alliance stays
        // Side C, same as before.
        if ($this->allegiance !== null) {
            foreach ($onFieldAlliances as $aid) {
                if (isset($allianceSide[$aid])) {
                    continue;
                }
                $score = $this->allegiance->scoreFor($aid, $sideAId, $sideBId);
                if ($score === null) {
                    continue;
                }
                $supportsA = $score['a_ally'] + $score['b_oppose'];
                $supportsB = $score['b_ally'] + $score['a_oppose'];
                if ($supportsA >= 3 && $supportsA > 2 * $supportsB) {
                    $allianceSide[$aid] = self::SIDE_A;
                } elseif ($supportsB >= 3 && $supportsB > 2 * $supportsA) {
                    $allianceSide[$aid] = self::SIDE_B;
                }
            }
        }

        // Character → side via killmail-derived alliance. Participant
        // rows that carried a zero alliance_id but whose character
        // appeared in a killmail still get a correct side here.
        // Characters in participants but missing from charToAlliance
        // (no alliance in either side of any kill — i.e. NPC / genuine
        // unaffiliated) fall to Side C, which is accurate.
        $sideByChar = [];
        foreach ($participants as $p) {
            $cid = (int) $p->character_id;
            $aid = $charToAlliance[$cid] ?? (int) ($p->alliance_id ?? 0);
            $sideByChar[$cid] = $allianceSide[$aid] ?? self::SIDE_C;
        }
        // Also include anyone who appeared in kills but isn't in
        // participants (attackers-without-participant-row case), so
        // downstream consumers that iterate sideByCharacterId don't
        // miss on-field pilots.
        foreach ($charToAlliance as $cid => $aid) {
            if (! isset($sideByChar[$cid])) {
                $sideByChar[$cid] = $allianceSide[$aid] ?? self::SIDE_C;
            }
        }

        return new BattleTheaterSideResolution(
            sideByCharacterId: $sideByChar,
            sideABlocId: null,
            sideBBlocId: null,
            allianceToBloc: $allianceToBloc,
        );
    }

    /**
     * Viewer-with-bloc side resolution.
     *
     * Ordering — standings are gospel, everything else is fill-in:
     *
     *   1. alliance_id === viewer_alliance_id → Side A   (self-match)
     *   2. character_standings owner ∈ {viewer alliance, viewer corp},
     *      contact_type = alliance:
     *         standing >= +5 → Side A
     *         standing <= -5 → Side B
     *         neutral        → skip (falls through to kill graph + Neo4j)
     *   3. Alliances still unassigned: kill-graph classification
     *      anchored to (viewer_alliance_id, largest standings-enemy
     *      alliance on the field). Same "≥3 events, 2× spread"
     *      thresholds as the anon path.
     *   4. Still unassigned: Neo4j allegiance scoreFor against the
     *      same anchors, same thresholds. Neo4j down / no prior
     *      signal → stays Side C.
     *
     * Bloc labels (coalition_entity_labels) deliberately don't enter
     * the scoring. Operators explicitly maintain standings for the
     * blue/red call and don't want a stale bloc tag outvoting their
     * contacts. Bloc IDs are still returned on the resolution for
     * the report UI's Side-A / Side-B titles.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     * @param  array<int, float>  $iskPerBloc
     */
    private function resolveByViewerAffinity(
        BattleTheater $theater,
        Collection $participants,
        array $allianceToBloc,
        array $iskPerBloc,
        ViewerContext $viewer,
        int $viewerBlocId,
    ): BattleTheaterSideResolution {
        $allianceIds = $participants
            ->pluck('alliance_id')
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        $viewerAllianceId = (int) ($viewer->viewer_alliance_id ?? 0);
        $viewerCorpId = (int) ($viewer->viewer_corporation_id ?? 0);

        $standings = $this->loadViewerAllianceStandings(
            viewerAllianceId: $viewerAllianceId,
            viewerCorpId: $viewerCorpId,
            allianceIds: $allianceIds,
        );

        // Step 1 + 2: standings + self-match fix assignments.
        $allianceSide = [];
        foreach ($allianceIds as $aid) {
            if ($aid === $viewerAllianceId && $aid > 0) {
                $allianceSide[$aid] = self::SIDE_A;
                continue;
            }
            $s = $standings[$aid] ?? null;
            if ($s === null) {
                continue;
            }
            if ($s >= 5.0) {
                $allianceSide[$aid] = self::SIDE_A;
            } elseif ($s <= -5.0) {
                $allianceSide[$aid] = self::SIDE_B;
            }
            // neutral standing (|s|<5): fall through
        }

        // Pick anchors for kill-graph / Neo4j classification of the
        // remainder. Anchor A = viewer's alliance when on field;
        // otherwise largest A-assigned alliance by pilot count.
        // Anchor B = largest B-assigned alliance (i.e. biggest
        // standings-hostile presence on the field).
        $anchorA = ($viewerAllianceId > 0 && in_array($viewerAllianceId, $allianceIds, true))
            ? $viewerAllianceId
            : $this->largestByPilots(
                $this->alliancesWithSide($allianceSide, self::SIDE_A),
                $participants,
            );
        $anchorB = $this->largestByPilots(
            $this->alliancesWithSide($allianceSide, self::SIDE_B),
            $participants,
        );

        // Step 3: kill-graph classification for the remaining
        // alliances. Runs only when both anchors exist — otherwise
        // there's no "this side" to compare against.
        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theater->id)
            ->pluck('killmail_id')
            ->all();

        $kills = [];
        if ($killmailIds !== [] && $anchorA !== null && $anchorB !== null) {
            $kills = $this->loadAllianceKillGraph($killmailIds);

            foreach ($allianceIds as $aid) {
                if (isset($allianceSide[$aid])) {
                    continue;
                }
                $supportsA = ($kills[$aid][$anchorB] ?? 0) + ($kills[$anchorB][$aid] ?? 0);
                $supportsB = ($kills[$aid][$anchorA] ?? 0) + ($kills[$anchorA][$aid] ?? 0);
                if ($supportsA >= 3 && $supportsA > 2 * $supportsB) {
                    $allianceSide[$aid] = self::SIDE_A;
                } elseif ($supportsB >= 3 && $supportsB > 2 * $supportsA) {
                    $allianceSide[$aid] = self::SIDE_B;
                }
            }
        }

        // Step 3.5: bloc-intel 90d pair behavior (MariaDB
        // alliance_pair_behavior_rolling). Between kill-graph
        // (fresh in-battle evidence) and Neo4j allegiance (long-term,
        // slower). Fills alliances that didn't cross fire in this
        // theater but have clear inferred affinity vs either anchor.
        if ($this->blocIntel !== null && $anchorA !== null && $anchorB !== null && $anchorA !== $anchorB) {
            foreach ($allianceIds as $aid) {
                if (isset($allianceSide[$aid])) {
                    continue;
                }
                $side = $this->classifyByBlocIntel($aid, $anchorA, $anchorB);
                if ($side !== null) {
                    $allianceSide[$aid] = $side;
                }
            }
        }

        // Step 4: Neo4j historical allegiance as final tiebreaker.
        if ($this->allegiance !== null
            && $anchorA !== null && $anchorB !== null
            && $anchorA !== $anchorB
        ) {
            foreach ($allianceIds as $aid) {
                if (isset($allianceSide[$aid])) {
                    continue;
                }
                $score = $this->allegiance->scoreFor($aid, $anchorA, $anchorB);
                if ($score === null) {
                    continue;
                }
                $supportsA = $score['a_ally'] + $score['b_oppose'];
                $supportsB = $score['b_ally'] + $score['a_oppose'];
                if ($supportsA >= 3 && $supportsA > 2 * $supportsB) {
                    $allianceSide[$aid] = self::SIDE_A;
                } elseif ($supportsB >= 3 && $supportsB > 2 * $supportsA) {
                    $allianceSide[$aid] = self::SIDE_B;
                }
            }
        }

        $sideByChar = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            $sideByChar[(int) $p->character_id] = $allianceSide[$aid] ?? self::SIDE_C;
        }

        // Keep the largest-opposing-bloc label for the Side B
        // headline in the UI (operators expect "SideB: Goons bloc"
        // even when a standings-hostile alliance outside Goons is
        // leading the fight).
        $opposingBlocId = $this->pickOpposingBloc($iskPerBloc, excludeBlocId: $viewerBlocId);

        return new BattleTheaterSideResolution(
            sideByCharacterId: $sideByChar,
            sideABlocId: $viewerBlocId,
            sideBBlocId: $opposingBlocId,
            allianceToBloc: $allianceToBloc,
        );
    }

    /**
     * Classify one alliance against the (anchorA, anchorB) pair via
     * alliance_pair_behavior_rolling. Returns SIDE_A / SIDE_B, or null
     * when the signal is too thin.
     *
     * Evidence model: for each anchor we score "supports A" and
     * "supports B" by summing confidence when the inferred label
     * points the right way. A confident "aligned to A" adds to A;
     * a confident "hostile to B" also adds to A, etc. Decision needs
     * one side to clear 0.4 AND be at least 2× the other.
     */
    private function classifyByBlocIntel(int $aid, int $anchorA, int $anchorB): ?string
    {
        if ($this->blocIntel === null || $aid === 0) {
            return null;
        }
        $vsA = $this->blocIntel->relate($aid, $anchorA);
        $vsB = $this->blocIntel->relate($aid, $anchorB);
        if ($vsA === null && $vsB === null) {
            return null;
        }
        $allyA = $this->blocIntelAlly($vsA);
        $allyB = $this->blocIntelAlly($vsB);
        $foeA = $this->blocIntelFoe($vsA);
        $foeB = $this->blocIntelFoe($vsB);
        $supportsA = $allyA + $foeB;
        $supportsB = $allyB + $foeA;
        if ($supportsA >= 0.4 && $supportsA > 2 * $supportsB) {
            return self::SIDE_A;
        }
        if ($supportsB >= 0.4 && $supportsB > 2 * $supportsA) {
            return self::SIDE_B;
        }
        return null;
    }

    /** @param array{affinity: float, hostility: float, confidence: float, n_obs: int, label: string}|null $m */
    private function blocIntelAlly(?array $m): float
    {
        if ($m === null || $m['confidence'] < 0.4 || $m['n_obs'] < 10) return 0.0;
        return in_array($m['label'], ['aligned', 'loosely coordinated'], true) ? (float) $m['confidence'] : 0.0;
    }

    /** @param array{affinity: float, hostility: float, confidence: float, n_obs: int, label: string}|null $m */
    private function blocIntelFoe(?array $m): float
    {
        if ($m === null || $m['confidence'] < 0.4 || $m['n_obs'] < 10) return 0.0;
        return $m['label'] === 'hostile' ? (float) $m['confidence'] : 0.0;
    }

    /**
     * @param  array<int, string>  $allianceSide  alliance_id → side
     * @return list<int>
     */
    private function alliancesWithSide(array $allianceSide, string $side): array
    {
        return array_values(array_keys(array_filter($allianceSide, fn ($s) => $s === $side)));
    }

    /**
     * Largest alliance from $candidates by on-field pilot count.
     * Returns null when the candidate list is empty or no
     * candidate has on-field pilots.
     *
     * @param  list<int>  $candidates
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     */
    private function largestByPilots(array $candidates, Collection $participants): ?int
    {
        if ($candidates === []) {
            return null;
        }
        $pilotsByAlliance = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            if (! in_array($aid, $candidates, true)) {
                continue;
            }
            $pilotsByAlliance[$aid] = ($pilotsByAlliance[$aid] ?? 0) + 1;
        }
        if ($pilotsByAlliance === []) {
            return null;
        }
        arsort($pilotsByAlliance);
        return (int) array_key_first($pilotsByAlliance);
    }

    /**
     * kills[attacker_alliance_id][victim_alliance_id] = count.
     *
     * @param  list<int>  $killmailIds
     * @return array<int, array<int, int>>
     */
    private function loadAllianceKillGraph(array $killmailIds): array
    {
        if ($killmailIds === []) {
            return [];
        }
        $kills = [];
        DB::table('killmail_attackers as a')
            ->join('killmails as k', 'k.killmail_id', '=', 'a.killmail_id')
            ->whereIn('a.killmail_id', $killmailIds)
            ->whereNotNull('a.alliance_id')
            ->whereNotNull('k.victim_alliance_id')
            ->where('a.alliance_id', '!=', DB::raw('k.victim_alliance_id'))
            ->select('a.alliance_id as att', 'k.victim_alliance_id as vic')
            ->get()
            ->each(function ($r) use (&$kills): void {
                $att = (int) $r->att;
                $vic = (int) $r->vic;
                $kills[$att][$vic] = ($kills[$att][$vic] ?? 0) + 1;
            });
        return $kills;
    }

    /**
     * Load the viewer's alliance-scoped standings for the alliance
     * IDs on the field. Alliance-owner rows are preferred; corp-
     * owner rows fill gaps when the viewer's alliance doesn't have a
     * standing toward a given alliance.
     *
     * @param  list<int>  $allianceIds
     * @return array<int, float>  alliance_id → standing value (-10.0 … +10.0)
     */
    private function loadViewerAllianceStandings(int $viewerAllianceId, int $viewerCorpId, array $allianceIds): array
    {
        if ($allianceIds === []) {
            return [];
        }
        $out = [];

        if ($viewerAllianceId > 0) {
            CharacterStanding::query()
                ->where('owner_type', CharacterStanding::OWNER_ALLIANCE)
                ->where('owner_id', $viewerAllianceId)
                ->where('contact_type', CharacterStanding::CONTACT_ALLIANCE)
                ->whereIn('contact_id', $allianceIds)
                ->get(['contact_id', 'standing'])
                ->each(function (CharacterStanding $row) use (&$out): void {
                    $out[(int) $row->contact_id] = (float) $row->standing;
                });
        }

        if ($viewerCorpId > 0) {
            CharacterStanding::query()
                ->where('owner_type', CharacterStanding::OWNER_CORPORATION)
                ->where('owner_id', $viewerCorpId)
                ->where('contact_type', CharacterStanding::CONTACT_ALLIANCE)
                ->whereIn('contact_id', $allianceIds)
                ->get(['contact_id', 'standing'])
                ->each(function (CharacterStanding $row) use (&$out): void {
                    // Alliance-owner row wins when both exist.
                    $out[(int) $row->contact_id] ??= (float) $row->standing;
                });
        }

        return $out;
    }

    /**
     * Pick the non-viewer bloc with the highest ISK_lost in this
     * theater. Returns null if no opposing bloc exists (either a
     * one-sided fight or one where only the viewer's bloc is labeled).
     *
     * @param  array<int, float>  $iskPerBloc
     */
    private function pickOpposingBloc(array $iskPerBloc, ?int $excludeBlocId): ?int
    {
        $best = null;
        $bestIsk = -1.0;
        foreach ($iskPerBloc as $blocId => $isk) {
            if ($blocId === $excludeBlocId) {
                continue;
            }
            if ($isk > $bestIsk) {
                $best = (int) $blocId;
                $bestIsk = $isk;
            }
        }

        return $best;
    }
}
