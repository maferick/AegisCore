<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\CharacterStanding;

/**
 * Result DTO for one donor's standings sync.
 *
 * Carries enough detail for /account/settings to show the donor a
 * precise outcome per owner (corp / alliance) — "synced 142 rows"
 * vs "skipped: missing role" vs "skipped: not in an alliance" — and
 * for structured logs to capture the same shape. The fetcher keeps
 * no side-channel state on itself; everything about the sync lives
 * here.
 */
final class StandingsSyncResult
{
    /** @var array<string, array{status: 'synced'|'skipped', count: int, message: ?string}> */
    private array $byOwner = [];

    public function __construct(
        public readonly int $characterId,
        public readonly int $corporationId,
        public readonly ?int $allianceId,
    ) {}

    public function markSynced(string $ownerType, int $count): void
    {
        $this->byOwner[$ownerType] = [
            'status' => 'synced',
            'count' => $count,
            'message' => null,
        ];
    }

    public function markSkipped(string $ownerType, string $message): void
    {
        $this->byOwner[$ownerType] = [
            'status' => 'skipped',
            'count' => 0,
            'message' => $message,
        ];
    }

    /** @return array<string, array{status: 'synced'|'skipped', count: int, message: ?string}> */
    public function byOwner(): array
    {
        return $this->byOwner;
    }

    /**
     * Human-readable one-line summary for flashing in the UI. Renders
     * each owner's outcome. When both corp and alliance succeeded:
     *   "Standings synced — corp: 42 contacts, alliance: 142 contacts."
     * When one was skipped:
     *   "Standings synced — corp: 42; alliance skipped (not in an alliance)."
     */
    public function toFlashMessage(): string
    {
        $parts = [];
        foreach ([CharacterStanding::OWNER_CORPORATION, CharacterStanding::OWNER_ALLIANCE] as $owner) {
            $entry = $this->byOwner[$owner] ?? null;
            if ($entry === null) {
                continue;
            }
            if ($entry['status'] === 'synced') {
                $parts[] = "{$owner}: {$entry['count']} contact".($entry['count'] === 1 ? '' : 's');
            } else {
                $parts[] = "{$owner} skipped ({$entry['message']})";
            }
        }

        if ($parts === []) {
            return 'Standings sync completed with no changes.';
        }

        return 'Standings synced — '.implode('; ', $parts).'.';
    }
}
