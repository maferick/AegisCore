<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use App\Domains\KillmailsBattleTheaters\Models\BattleTheaterParticipant;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\ViewerContext;
use Illuminate\Support\Collection;

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

        $sideABlocId = $viewerBlocId;
        $sideBBlocId = $this->pickOpposingBloc($iskPerBloc, excludeBlocId: $sideABlocId);

        // Fallback for viewers with no confirmed bloc: pick the two
        // largest blocs by ISK lost. UI can swap A/B.
        if ($sideABlocId === null) {
            arsort($iskPerBloc);
            $topTwo = array_slice(array_keys($iskPerBloc), 0, 2);
            $sideABlocId = $topTwo[0] ?? null;
            $sideBBlocId = $topTwo[1] ?? null;
        }

        // Assign each participant a side.
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
