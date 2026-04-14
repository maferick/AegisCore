<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\DockerContainerState;
use App\System\SystemStatusLevel;
use PHPUnit\Framework\TestCase;

/**
 * Guards the mapping between Docker's lifecycle states and our coarse
 * traffic-light model. Bumping any of these is a breaking change for
 * the admin Container Status page.
 */
final class DockerContainerStateTest extends TestCase
{
    public function test_parses_known_states_case_insensitively(): void
    {
        self::assertSame(DockerContainerState::RUNNING, DockerContainerState::fromRaw('running'));
        self::assertSame(DockerContainerState::RUNNING, DockerContainerState::fromRaw('RUNNING'));
        self::assertSame(DockerContainerState::EXITED, DockerContainerState::fromRaw('Exited'));
        self::assertSame(DockerContainerState::PAUSED, DockerContainerState::fromRaw('paused'));
    }

    public function test_unknown_or_missing_values_fall_back_to_unknown(): void
    {
        self::assertSame(DockerContainerState::UNKNOWN, DockerContainerState::fromRaw(null));
        self::assertSame(DockerContainerState::UNKNOWN, DockerContainerState::fromRaw(''));
        self::assertSame(DockerContainerState::UNKNOWN, DockerContainerState::fromRaw('ceasing-to-be'));
    }

    public function test_lifecycle_states_map_to_traffic_light_levels(): void
    {
        self::assertSame(SystemStatusLevel::OK, DockerContainerState::RUNNING->level());
        self::assertSame(SystemStatusLevel::DEGRADED, DockerContainerState::RESTARTING->level());
        self::assertSame(SystemStatusLevel::DEGRADED, DockerContainerState::PAUSED->level());
        self::assertSame(SystemStatusLevel::DOWN, DockerContainerState::EXITED->level());
        self::assertSame(SystemStatusLevel::DOWN, DockerContainerState::DEAD->level());
        self::assertSame(SystemStatusLevel::UNKNOWN, DockerContainerState::CREATED->level());
        self::assertSame(SystemStatusLevel::UNKNOWN, DockerContainerState::REMOVING->level());
        self::assertSame(SystemStatusLevel::UNKNOWN, DockerContainerState::UNKNOWN->level());
    }

    public function test_labels_are_human_readable(): void
    {
        foreach (DockerContainerState::cases() as $state) {
            self::assertNotSame($state->value, $state->label());
            self::assertStringStartsWith(strtoupper($state->label()[0]), $state->label());
        }
    }
}
