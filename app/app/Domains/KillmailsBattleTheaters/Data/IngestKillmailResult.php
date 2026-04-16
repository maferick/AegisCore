<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Data;

/**
 * Immutable result of a single killmail ingestion.
 *
 * Returned by {@see \App\Domains\KillmailsBattleTheaters\Actions\IngestKillmail}.
 */
final class IngestKillmailResult
{
    public function __construct(
        public readonly int $killmailId,
        /** True if this was a first-time insert, false if updated. */
        public readonly bool $wasNew,
        public readonly int $attackerCount,
        public readonly int $itemCount,
    ) {}
}
