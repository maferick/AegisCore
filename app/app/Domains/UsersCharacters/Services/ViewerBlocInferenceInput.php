<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use Illuminate\Support\Collection;

/**
 * Immutable input bundle for {@see ViewerBlocInferenceService::infer()}.
 *
 * The inference service is pure — it does not touch the DB itself, so
 * the caller preloads the viewer's alliance/corp ids and the relevant
 * coalition labels and hands them in here. This keeps the service
 * trivially unit-testable.
 *
 * `$labels` MUST be pre-filtered to labels for the viewer's alliance
 * AND corporation (both entity types) and pre-ordered by the paired
 * CoalitionRelationshipType.display_order so the service's
 * first-label-per-bloc-wins logic produces the "most significant"
 * relationship for each bloc.
 */
final class ViewerBlocInferenceInput
{
    /**
     * @param  Collection<int, CoalitionEntityLabel>  $labels
     */
    public function __construct(
        public readonly ?int $viewerAllianceId,
        public readonly ?int $viewerCorporationId,
        public readonly Collection $labels,
    ) {}
}
