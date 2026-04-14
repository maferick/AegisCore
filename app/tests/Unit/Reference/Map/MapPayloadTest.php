<?php

declare(strict_types=1);

namespace Tests\Unit\Reference\Map;

use App\Reference\Map\Data\MapPayload;
use Tests\TestCase;

/**
 * Unit coverage for the bbox + projection helpers on MapPayload.
 *
 * Bbox math is small but the renderer's first-paint fit depends on it
 * being padded; if we ever drop the 5% padding or break the empty-input
 * sentinel, every map on the page silently clips its outermost nodes.
 */
final class MapPayloadTest extends TestCase
{
    public function test_compute_bbox_returns_zero_tuple_for_empty_input(): void
    {
        self::assertSame(
            [0.0, 0.0, 0.0, 0.0],
            MapPayload::computeBbox([]),
        );
    }

    public function test_compute_bbox_returns_padded_extent_for_single_point(): void
    {
        // Single point — extents are zero, so padding is zero too. The
        // returned bbox is degenerate (a single point) but valid.
        self::assertSame(
            [10.0, 20.0, 10.0, 20.0],
            MapPayload::computeBbox([[10.0, 20.0]]),
        );
    }

    public function test_compute_bbox_pads_extents_by_five_percent(): void
    {
        $points = [
            [0.0, 0.0],
            [100.0, 50.0],
        ];
        // dx = 100, dy = 50 → padX = 5, padY = 2.5
        self::assertSame(
            [-5.0, -2.5, 105.0, 52.5],
            MapPayload::computeBbox($points),
        );
    }

    public function test_compute_bbox_handles_negative_coordinates(): void
    {
        $points = [
            [-50.0, -200.0],
            [-10.0, 0.0],
            [-100.0, 300.0],
        ];
        // dx = 90, dy = 500 → padX = 4.5, padY = 25
        self::assertSame(
            [-104.5, -225.0, -5.5, 325.0],
            MapPayload::computeBbox($points),
        );
    }
}
