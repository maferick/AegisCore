<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Models\BattleTheaterParticipant;
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
    public const SIDE_B = 'B';
    public const SIDE_C = 'C';

    /**
     * Result: for each participant (keyed by character_id), the side
     * they belong to for this render. Includes the bloc labels used
     * so the UI can show "Side A: WinterCo (12 pilots)".
     */
    public function resolve(BattleTheater $theater, ?ViewerContext $viewer): BattleTheaterSideResolution
    {
        /** @var Collection<int, BattleTheaterParticipant> $participants */
        $participants = $theater->participants()->get();
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

        // Bloc-based path — only used when the viewer has an explicit
        // bloc set. "Show me my coalition vs its dominant adversary,
        // everyone else is third parties" is an intentional viewer
        // choice; we respect it even if the bloc mapping is sparse.
        if ($viewerBlocId !== null) {
            $sideABlocId = $viewerBlocId;
            $sideBBlocId = $this->pickOpposingBloc($iskPerBloc, excludeBlocId: $sideABlocId);

            $sideByChar = [];
            foreach ($participants as $p) {
                $allianceBloc = $allianceToBloc[(int) $p->alliance_id] ?? null;
                if ($allianceBloc !== null && $allianceBloc === $sideABlocId) {
                    $sideByChar[(int) $p->character_id] = self::SIDE_A;
                } elseif ($allianceBloc !== null && $allianceBloc === $sideBBlocId) {
                    $sideByChar[(int) $p->character_id] = self::SIDE_B;
                } else {
                    $sideByChar[(int) $p->character_id] = self::SIDE_C;
                }
            }

            return new BattleTheaterSideResolution(
                sideByCharacterId: $sideByChar,
                sideABlocId: $sideABlocId,
                sideBBlocId: $sideBBlocId,
                allianceToBloc: $allianceToBloc,
            );
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

        // Classify every other alliance by shoot-direction.
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
