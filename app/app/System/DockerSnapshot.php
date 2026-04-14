<?php

declare(strict_types=1);

namespace App\System;

/**
 * Result of a single {@see DockerStatusService::probe()} call.
 *
 * Three terminal states:
 *
 *   1. ok — the proxy answered; `containers` holds the parsed list.
 *   2. error — the proxy errored or was unreachable; `error` holds
 *      a short message for the operator. `containers` is empty.
 *   3. unconfigured — DOCKER_API_HOST is empty. This is an expected
 *      state (operators may opt out of the docker socket exposure)
 *      and the widget renders a "not configured" notice rather than
 *      a red error.
 *
 * Kept as a single value object rather than a pair of sentinel arrays so
 * the calling Widget/Page code doesn't have to reach into an untyped
 * associative array when rendering.
 */
final readonly class DockerSnapshot
{
    /**
     * @param  array<int, DockerContainer>  $containers
     */
    private function __construct(
        public array $containers,
        public ?string $error,
        public bool $configured,
    ) {}

    /**
     * @param  array<int, DockerContainer>  $containers
     */
    public static function ok(array $containers): self
    {
        return new self($containers, null, true);
    }

    public static function error(string $message): self
    {
        return new self([], $message, true);
    }

    public static function unconfigured(): self
    {
        return new self([], null, false);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Summary counts for the stats widget: running / unhealthy / stopped.
     * "unhealthy" here is the derived level — it catches both
     * `health=unhealthy` *and* lifecycle states like `restarting` /
     * `paused` that render orange.
     *
     * @return array{running: int, unhealthy: int, stopped: int, total: int}
     */
    public function summary(): array
    {
        $running = 0;
        $unhealthy = 0;
        $stopped = 0;

        foreach ($this->containers as $container) {
            $level = $container->level();
            if ($level === SystemStatusLevel::OK) {
                $running++;
            } elseif ($level === SystemStatusLevel::DEGRADED) {
                $unhealthy++;
            } elseif ($level === SystemStatusLevel::DOWN) {
                $stopped++;
            }
        }

        return [
            'running' => $running,
            'unhealthy' => $unhealthy,
            'stopped' => $stopped,
            'total' => count($this->containers),
        ];
    }
}
