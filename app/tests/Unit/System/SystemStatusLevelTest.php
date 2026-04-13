<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\SystemStatusLevel;
use PHPUnit\Framework\TestCase;

/**
 * Guards the mapping between a status level and its presentation tokens
 * (colour, icon, label). The admin widget hard-codes the expectation that
 * OK → green, DEGRADED → orange, DOWN → red, UNKNOWN → grey — bumping any
 * of those without updating the UX docs is a breaking change.
 */
final class SystemStatusLevelTest extends TestCase
{
    public function test_colour_tokens_match_filament_palette(): void
    {
        self::assertSame('success', SystemStatusLevel::OK->color());
        self::assertSame('warning', SystemStatusLevel::DEGRADED->color());
        self::assertSame('danger', SystemStatusLevel::DOWN->color());
        self::assertSame('gray', SystemStatusLevel::UNKNOWN->color());
    }

    public function test_icon_tokens_are_heroicons(): void
    {
        foreach (SystemStatusLevel::cases() as $level) {
            self::assertStringStartsWith('heroicon-', $level->icon());
        }
    }

    public function test_labels_are_human_readable(): void
    {
        self::assertSame('Healthy', SystemStatusLevel::OK->label());
        self::assertSame('Degraded', SystemStatusLevel::DEGRADED->label());
        self::assertSame('Down', SystemStatusLevel::DOWN->label());
        self::assertSame('Unknown', SystemStatusLevel::UNKNOWN->label());
    }
}
