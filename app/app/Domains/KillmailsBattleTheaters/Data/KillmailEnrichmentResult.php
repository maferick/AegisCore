<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Data;

/**
 * Immutable result of a single killmail enrichment pass.
 *
 * Returned by {@see \App\Domains\KillmailsBattleTheaters\Actions\EnrichKillmail}.
 */
final class KillmailEnrichmentResult
{
    public function __construct(
        public readonly int $killmailId,
        public readonly int $itemsValued,
        /** Total killmail value as a DECIMAL string. */
        public readonly string $totalValue,
        public readonly int $entityNamesResolved,
        public readonly int $enrichmentVersion,
        /** True if the killmail was already enriched before this pass. */
        public readonly bool $wasAlreadyEnriched,
    ) {}
}
