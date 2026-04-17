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

        // Anonymous / no-viewer-bloc path — kill-graph clustering.
        //
        // Strategy:
        //   1. Pick the biggest alliance by pilot count as Side A.
        //   2. Side B = alliance with the most mutual kills vs Side A
        //      (killed Side A pilots or lost pilots to Side A).
        //   3. For every other alliance, look at which of Side A / B
        //      they shot: alliances that only shoot Side B are Side A
        //      allies, alliances that only shoot Side A are Side B
        //      allies, alliances that shoot both (within a threshold)
        //      are real third parties and go to Side C.
        //
        // Falls back to the pure alliance-pilot-count split when we
        // don't have enough kill data to draw clusters from (tiny
        // fights, pre-locked theaters with no killmails indexed yet).
        $graph = $this->resolveByKillGraph($theater, $participants, $allianceToBloc);
        if ($graph !== null) {
            return $graph;
        }
        return $this->resolveByAlliance($participants, $allianceToBloc);
    }

    /**
     * Alliance-level fallback used when no viewer bloc is set AND the
     * kill graph couldn't anchor a pair. Picks the two alliances with
     * the most pilots; everything else → Side C.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     */
    private function resolveByAlliance(Collection $participants, array $allianceToBloc): BattleTheaterSideResolution
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
     * Side assignment via the kill graph. "Who shot whom" is the
     * ground truth for allegiance in a single fight — two opposing
     * fleets never shoot each other's members, and a real third
     * party (nation-state observer, loot hunter, random pirate who
     * chewed up a mag-9) shoots both sides.
     *
     * Returns null when the theater has no killmail data or no
     * alliance-attributable kills (structure kills, pure NPC, etc.),
     * letting the caller fall back to the pilot-count split.
     *
     * @param  Collection<int, BattleTheaterParticipant>  $participants
     * @param  array<int, int>  $allianceToBloc
     */
    private function resolveByKillGraph(BattleTheater $theater, Collection $participants, array $allianceToBloc): ?BattleTheaterSideResolution
    {
        $killmailIds = DB::table('battle_theater_killmails')
            ->where('theater_id', $theater->id)
            ->pluck('killmail_id')
            ->all();
        if ($killmailIds === []) {
            return null;
        }

        // kills[attacker_alliance_id][victim_alliance_id] = count.
        // Duplicated attackers on the same killmail inflate the count
        // slightly (10 pilots of alliance X on one killmail → 10
        // "kills" in the graph), which matches intent — more guns
        // pointed = stronger signal.
        $kills = [];
        DB::table('killmail_attackers as a')
            ->join('killmails as k', 'k.killmail_id', '=', 'a.killmail_id')
            ->whereIn('a.killmail_id', $killmailIds)
            ->whereNotNull('a.alliance_id')
            ->whereNotNull('k.victim_alliance_id')
            ->where('a.alliance_id', '!=', DB::raw('k.victim_alliance_id')) // drop friendly-fire self-hits
            ->select('a.alliance_id as att', 'k.victim_alliance_id as vic')
            ->get()
            ->each(function ($r) use (&$kills): void {
                $att = (int) $r->att;
                $vic = (int) $r->vic;
                $kills[$att][$vic] = ($kills[$att][$vic] ?? 0) + 1;
            });

        if ($kills === []) {
            return null;
        }

        // Anchor Side A on the biggest alliance by pilots — fights
        // never have the main defender sitting in third place.
        $pilotsPerAlliance = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            if ($aid === 0) {
                continue;
            }
            $pilotsPerAlliance[$aid] = ($pilotsPerAlliance[$aid] ?? 0) + 1;
        }
        arsort($pilotsPerAlliance);
        $sideAId = (int) (array_key_first($pilotsPerAlliance) ?? 0);
        if ($sideAId === 0) {
            return null;
        }

        // Side B candidates must both (a) have mutual kills vs Side A
        // and (b) have at least one participant on the field. Without
        // (b) you get the "gank" case: Side A hit a cluster of random
        // freighters whose alliance isn't represented in participants,
        // so the top "mutualVsA" entry maps to 0 pilots and Side B
        // ends up empty. Keeping the filter here means we fall back
        // cleanly to the alliance-pilots split below when nobody on
        // the field was shooting back.
        $participantAlliances = array_keys($pilotsPerAlliance);
        $mutualVsA = [];
        foreach ($kills[$sideAId] ?? [] as $vic => $n) {
            if (! in_array($vic, $participantAlliances, true)) {
                continue;
            }
            $mutualVsA[$vic] = ($mutualVsA[$vic] ?? 0) + $n;
        }
        foreach ($kills as $att => $vics) {
            if (! isset($vics[$sideAId])) {
                continue;
            }
            if (! in_array($att, $participantAlliances, true)) {
                continue;
            }
            $mutualVsA[$att] = ($mutualVsA[$att] ?? 0) + $vics[$sideAId];
        }
        unset($mutualVsA[$sideAId]);
        if ($mutualVsA === []) {
            // No alliance actually present in the theater exchanged
            // fire with Side A. Kick back to the alliance-pilot-count
            // split so the UI still shows the second-largest
            // alliance as an opposing side (operator can see "we
            // fought X and Y even if nobody died").
            return null;
        }
        arsort($mutualVsA);
        $sideBId = (int) array_key_first($mutualVsA);

        // Classify every other alliance by attack direction. The
        // 2× + >=3 absolute-minimum guard keeps noise out — a single
        // stray shot across the field shouldn't pull an alliance
        // into a bloc.
        $allianceSide = [$sideAId => self::SIDE_A, $sideBId => self::SIDE_B];
        $allAlliances = array_unique(array_merge(
            array_keys($kills),
            ...array_map(static fn ($v) => array_keys($v), array_values($kills)),
        ));
        foreach ($allAlliances as $aid) {
            if (isset($allianceSide[$aid])) {
                continue;
            }
            $againstA = $kills[$aid][$sideAId] ?? 0;
            $againstB = $kills[$aid][$sideBId] ?? 0;
            $losesToA = $kills[$sideAId][$aid] ?? 0;
            $losesToB = $kills[$sideBId][$aid] ?? 0;
            // Alliance X: attacks A + takes losses from B → ally of B.
            // Attacks B + loses to A → ally of A. Mostly even or
            // shoots both → genuine third party.
            $supportsA = $againstB + $losesToB; // hits B / bleeds to B
            $supportsB = $againstA + $losesToA;
            if ($supportsA >= 3 && $supportsA > 2 * $supportsB) {
                $allianceSide[$aid] = self::SIDE_A;
            } elseif ($supportsB >= 3 && $supportsB > 2 * $supportsA) {
                $allianceSide[$aid] = self::SIDE_B;
            }
            // else: leave unassigned so the per-pilot loop below
            // drops them into Side C.
        }

        $sideByChar = [];
        foreach ($participants as $p) {
            $aid = (int) ($p->alliance_id ?? 0);
            $sideByChar[(int) $p->character_id] = $allianceSide[$aid] ?? self::SIDE_C;
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
