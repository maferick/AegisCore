<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\ViewerContext;
use Illuminate\Support\Collection;

/**
 * Infers a viewer's coalition bloc from the labels on their alliance
 * and corporation. Pure — no DB writes, no side effects. The caller
 * loads labels from MariaDB and hands them to {@see self::infer()},
 * which returns a {@see ViewerBlocInferenceResult} describing the
 * decision.
 *
 * Precedence (tightest signal wins):
 *
 *   1. Active labels on the viewer's ALLIANCE that name a single bloc →
 *      high confidence; reason names the matching label.
 *   2. Active labels on the viewer's CORPORATION that name a single
 *      bloc → high confidence; reason names the matching label.
 *   3. Labels on alliance + corp that point at different blocs →
 *      medium confidence; reason flags the conflict. Alliance label
 *      wins tiebreaking because alliance diplomacy outranks corp
 *      diplomacy in EVE.
 *   4. No usable labels (labels without a bloc_id, or no labels at
 *      all) → unresolved. The onboarding UI then prompts the donor
 *      to pick manually.
 *
 * Within a single entity, multiple labels are collapsed by relationship
 * display_order (lowest = most significant). `member` outranks
 * `affiliate` outranks `logistics` etc. That's {@see CoalitionRelationshipType}
 * responsibility — this service just honours the ordering the caller
 * provides via the label collection's already-applied ordering.
 *
 * The service doesn't care if standing-based evidence would disagree
 * with the inferred bloc — that's the resolver's job, running after
 * viewer bloc is established. This service just answers "which side
 * is the viewer looking at the world from".
 */
final class ViewerBlocInferenceService
{
    public function infer(ViewerBlocInferenceInput $input): ViewerBlocInferenceResult
    {
        $allianceBlocs = $this->blocsFor(
            CoalitionEntityLabel::ENTITY_ALLIANCE,
            $input->viewerAllianceId,
            $input->labels,
        );
        $corpBlocs = $this->blocsFor(
            CoalitionEntityLabel::ENTITY_CORPORATION,
            $input->viewerCorporationId,
            $input->labels,
        );

        // Rule 1: alliance labels resolve to a single bloc.
        if (count($allianceBlocs) === 1) {
            $blocId = array_key_first($allianceBlocs);

            // Rule 3: corp labels disagree — downgrade to medium and flag.
            if (count($corpBlocs) === 1 && array_key_first($corpBlocs) !== $blocId) {
                return ViewerBlocInferenceResult::resolved(
                    blocId: $blocId,
                    confidenceBand: ViewerContext::CONFIDENCE_MEDIUM,
                    reason: 'Alliance and corporation labels disagree; using alliance.',
                );
            }

            return ViewerBlocInferenceResult::resolved(
                blocId: $blocId,
                confidenceBand: ViewerContext::CONFIDENCE_HIGH,
                reason: "Inferred from alliance label: {$allianceBlocs[$blocId]}",
            );
        }

        // Alliance ambiguous (multiple blocs) — still better than nothing.
        if (count($allianceBlocs) > 1) {
            return ViewerBlocInferenceResult::unresolved(
                'Alliance carries labels for multiple blocs; pick one manually.',
            );
        }

        // Rule 2: corp labels resolve to a single bloc.
        if (count($corpBlocs) === 1) {
            $blocId = array_key_first($corpBlocs);

            return ViewerBlocInferenceResult::resolved(
                blocId: $blocId,
                confidenceBand: ViewerContext::CONFIDENCE_HIGH,
                reason: "Inferred from corporation label: {$corpBlocs[$blocId]}",
            );
        }

        if (count($corpBlocs) > 1) {
            return ViewerBlocInferenceResult::unresolved(
                'Corporation carries labels for multiple blocs; pick one manually.',
            );
        }

        // Rule 4: no usable labels.
        return ViewerBlocInferenceResult::unresolved(
            'No coalition labels on this viewer\'s alliance or corporation.',
        );
    }

    /**
     * Filter labels down to those on a specific entity, then group by
     * bloc_id. Returns [bloc_id => raw_label] so the calling precedence
     * logic can inspect count and grab names for the reason string.
     *
     * Labels without a bloc_id are dropped — an un-parsed raw_label
     * carries no inference signal.
     *
     * @param  Collection<int, CoalitionEntityLabel>  $labels
     * @return array<int, string>
     */
    private function blocsFor(string $entityType, ?int $entityId, Collection $labels): array
    {
        if ($entityId === null) {
            return [];
        }

        $result = [];
        foreach ($labels as $label) {
            if ($label->entity_type !== $entityType) {
                continue;
            }
            if ($label->entity_id !== $entityId) {
                continue;
            }
            if (! $label->is_active) {
                continue;
            }
            if ($label->bloc_id === null) {
                continue;
            }
            // First label per bloc wins — the caller pre-orders by
            // display_order, so the "most significant" label for each
            // bloc is what we keep.
            if (! array_key_exists($label->bloc_id, $result)) {
                $result[$label->bloc_id] = $label->raw_label;
            }
        }

        return $result;
    }
}
