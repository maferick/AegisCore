<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

/**
 * Result DTO for one corporation's affiliation sync.
 *
 * Captures per-corp outcomes for operator logging and for the job-level
 * summary. Mirrors {@see StandingsSyncResult}'s shape at a smaller
 * scale — one corp per result, no per-owner bucketing.
 *
 * `$status` values:
 *   - 'synced'   — both ESI calls succeeded and the profile row was
 *                  upserted.
 *   - 'skipped'  — preflight check decided there was nothing to do
 *                  (fresh profile, corp not in any relevant set, etc.).
 *   - 'failed'   — a hard error that the job-level loop swallows so
 *                  one bad corp doesn't take out the sweep.
 */
final class CorporationAffiliationSyncResult
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly int $corporationId,
        public readonly string $status,
        public readonly ?int $currentAllianceId = null,
        public readonly ?int $previousAllianceId = null,
        public readonly ?string $message = null,
    ) {}

    public static function synced(int $corpId, ?int $currentAllianceId, ?int $previousAllianceId): self
    {
        return new self(
            corporationId: $corpId,
            status: self::STATUS_SYNCED,
            currentAllianceId: $currentAllianceId,
            previousAllianceId: $previousAllianceId,
        );
    }

    public static function skipped(int $corpId, string $message): self
    {
        return new self(
            corporationId: $corpId,
            status: self::STATUS_SKIPPED,
            message: $message,
        );
    }

    public static function failed(int $corpId, string $message): self
    {
        return new self(
            corporationId: $corpId,
            status: self::STATUS_FAILED,
            message: $message,
        );
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }
}
