<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

/**
 * Immutable result of a side-resolution pass. Carries enough detail
 * for the render layer to:
 *
 *   - answer "which side is this pilot on" (sideByCharacterId)
 *   - label sides with their bloc display name (side*BlocId → look up
 *     CoalitionBloc in the view)
 *   - classify every alliance present in the fight via allianceToBloc
 *     for the alliance-summary table
 *
 * No methods beyond constructor + empty() factory — the consumer is
 * the blade view, which reaches into the public read-only props
 * directly.
 */
final class BattleTheaterSideResolution
{
    /**
     * @param  array<int, string>  $sideByCharacterId  character_id → 'A'|'B'|'C'
     * @param  int|null  $sideABlocId  null when no viewer bloc + no candidate
     * @param  int|null  $sideBBlocId  null on one-sided fights
     * @param  array<int, int>  $allianceToBloc  alliance_id → bloc_id (mapped only)
     */
    public function __construct(
        public readonly array $sideByCharacterId,
        public readonly ?int $sideABlocId,
        public readonly ?int $sideBBlocId,
        public readonly array $allianceToBloc,
    ) {}

    public static function empty(): self
    {
        return new self(
            sideByCharacterId: [],
            sideABlocId: null,
            sideBBlocId: null,
            allianceToBloc: [],
        );
    }
}
