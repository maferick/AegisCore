<?php

declare(strict_types=1);

namespace App\System;

/**
 * Lifecycle state of a single container as reported by the Docker API
 * (`GET /containers/json` → `State` field).
 *
 * Docker's own vocabulary is a little broader than what we surface on
 * the admin page — we fold {@link https://docs.docker.com/reference/api/engine/version/v1.47/#tag/Container}'s
 * seven states into a coarser traffic-light model the operator can read
 * at a glance:
 *
 *   RUNNING   → green   (healthy master process)
 *   RESTARTING → orange (Docker restart policy kicked in — usually a crash loop)
 *   PAUSED    → orange (deliberately frozen, rare outside debugging)
 *   EXITED    → red    (stopped on its own; ok for one-shots, red for long-lived)
 *   DEAD      → red    (filesystem detached — needs manual recovery)
 *   CREATED   → gray   (created but never started)
 *   REMOVING  → gray   (tearing down)
 *
 * `UNKNOWN` is our escape hatch — we fall back to it if the proxy
 * returns a value Docker adds in a future API version.
 */
enum DockerContainerState: string
{
    case RUNNING = 'running';
    case RESTARTING = 'restarting';
    case PAUSED = 'paused';
    case EXITED = 'exited';
    case DEAD = 'dead';
    case CREATED = 'created';
    case REMOVING = 'removing';
    case UNKNOWN = 'unknown';

    /**
     * Parse the raw state string the Docker API returns. Case-insensitive
     * and forgiving — unknown values fall back to {@see self::UNKNOWN}
     * rather than throwing, so an upstream API version bump can't 500
     * the admin page.
     */
    public static function fromRaw(?string $raw): self
    {
        if ($raw === null || $raw === '') {
            return self::UNKNOWN;
        }

        return self::tryFrom(strtolower($raw)) ?? self::UNKNOWN;
    }

    /**
     * Map the container state onto the three-colour traffic-light the
     * admin widget renders. Kept loosely aligned with
     * {@see SystemStatusLevel} so operators don't need to learn a second
     * colour vocabulary.
     */
    public function level(): SystemStatusLevel
    {
        return match ($this) {
            self::RUNNING => SystemStatusLevel::OK,
            self::RESTARTING, self::PAUSED => SystemStatusLevel::DEGRADED,
            self::EXITED, self::DEAD => SystemStatusLevel::DOWN,
            self::CREATED, self::REMOVING, self::UNKNOWN => SystemStatusLevel::UNKNOWN,
        };
    }

    /**
     * Short human label — renders as the table state column.
     */
    public function label(): string
    {
        return match ($this) {
            self::RUNNING => 'Running',
            self::RESTARTING => 'Restarting',
            self::PAUSED => 'Paused',
            self::EXITED => 'Exited',
            self::DEAD => 'Dead',
            self::CREATED => 'Created',
            self::REMOVING => 'Removing',
            self::UNKNOWN => 'Unknown',
        };
    }
}
