<?php

declare(strict_types=1);

namespace App\System;

/**
 * Immutable snapshot of a single container's state, as returned by a
 * read of {@link https://docs.docker.com/reference/api/engine/version/v1.47/#tag/Container}
 * `GET /containers/json?all=true` through the
 * {@see \App\System\DockerStatusService}.
 *
 * Kept intentionally narrow — the admin table needs name, state, health,
 * image, uptime, and a quick status line. Anything richer (CPU / memory
 * / logs) belongs in `docker stats` / `docker logs` proper, not a
 * five-second-polling admin widget.
 */
final readonly class DockerContainer
{
    /**
     * @param  string  $name  Container name without the leading slash.
     *                        Docker's API prefixes names with '/' for
     *                        historical reasons; we strip it so the
     *                        table matches the names an operator
     *                        types into `docker compose ps`.
     * @param  string  $image  Image reference — `repository:tag` or a
     *                         sha256 digest for scratch builds.
     * @param  DockerContainerState  $state  Coarse lifecycle state; see enum.
     * @param  string|null  $healthStatus  One of Docker's health states
     *                                     (`healthy` / `unhealthy` /
     *                                     `starting`), or null for
     *                                     containers without a
     *                                     healthcheck declared.
     * @param  int|null  $startedAtUnix  Epoch seconds of the current
     *                                   start. Null for containers
     *                                   that never started. Derived
     *                                   from the `Created` field when
     *                                   `State.StartedAt` isn't in the
     *                                   list response (it's only on the
     *                                   per-container detail endpoint,
     *                                   which we don't call).
     * @param  string  $statusLine  Raw `Status` line from Docker (e.g.
     *                              "Up 3 hours (healthy)"). Useful as a
     *                              fallback when our coarse enum + health
     *                              doesn't tell the full story.
     */
    public function __construct(
        public string $name,
        public string $image,
        public DockerContainerState $state,
        public ?string $healthStatus,
        public ?int $startedAtUnix,
        public string $statusLine,
    ) {}

    /**
     * Combined traffic-light for the row: a container whose lifecycle
     * state is RUNNING but whose healthcheck reports `unhealthy` should
     * still render orange, not green. Containers without a healthcheck
     * just inherit their lifecycle state's level.
     */
    public function level(): SystemStatusLevel
    {
        $stateLevel = $this->state->level();

        if ($this->state !== DockerContainerState::RUNNING) {
            return $stateLevel;
        }

        return match ($this->healthStatus) {
            'healthy' => SystemStatusLevel::OK,
            'unhealthy' => SystemStatusLevel::DOWN,
            'starting' => SystemStatusLevel::DEGRADED,
            default => $stateLevel,
        };
    }
}
