<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

/**
 * Result DTO from {@see ViewerBlocInferenceService::infer()}.
 *
 * Immutable value object — the inference service is pure (no DB writes,
 * no side effects), so the caller picks up this DTO and decides what to
 * do next (persist to viewer_contexts, surface to UI, flag for review).
 *
 * `$reason` is a short human-readable string for logs and for the
 * donor-facing "why we inferred this" blurb on the onboarding surface.
 * Not intended for machine parsing.
 */
final class ViewerBlocInferenceResult
{
    public function __construct(
        public readonly ?int $blocId,
        public readonly ?string $confidenceBand,
        public readonly bool $resolved,
        public readonly string $reason,
    ) {}

    public static function unresolved(string $reason): self
    {
        return new self(
            blocId: null,
            confidenceBand: null,
            resolved: false,
            reason: $reason,
        );
    }

    public static function resolved(int $blocId, string $confidenceBand, string $reason): self
    {
        return new self(
            blocId: $blocId,
            confidenceBand: $confidenceBand,
            resolved: true,
            reason: $reason,
        );
    }
}
