<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\KillmailsBattleTheaters;

use App\Domains\KillmailsBattleTheaters\Models\KillmailItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the CCP inventory flag → slot category mapping.
 * Must stay in sync with the Python equivalent in
 * killmail_ingest/parse.py::slot_category_from_flag().
 */
final class SlotCategoryFromFlagTest extends TestCase
{
    #[DataProvider('flagProvider')]
    public function test_maps_flag_to_slot(int $flag, string $expected): void
    {
        self::assertSame($expected, KillmailItem::slotCategoryFromFlag($flag));
    }

    public static function flagProvider(): array
    {
        return [
            // High slots (27–34)
            'HiSlot0' => [27, 'high'],
            'HiSlot4' => [31, 'high'],
            'HiSlot7' => [34, 'high'],

            // Mid slots (19–26)
            'MedSlot0' => [19, 'mid'],
            'MedSlot7' => [26, 'mid'],

            // Low slots (11–18)
            'LoSlot0' => [11, 'low'],
            'LoSlot7' => [18, 'low'],

            // Rigs (92–99)
            'RigSlot0' => [92, 'rig'],
            'RigSlot2' => [94, 'rig'],
            'RigSlot extended' => [99, 'rig'],

            // Subsystems (125–132)
            'SubSystem0' => [125, 'subsystem'],
            'SubSystem7' => [132, 'subsystem'],

            // Service slots (164–171)
            'ServiceSlot0' => [164, 'service'],
            'ServiceSlot7' => [171, 'service'],

            // Drone bay
            'DroneBay' => [87, 'drone_bay'],

            // Fighter bay
            'FighterBay' => [158, 'fighter_bay'],

            // Implant (pod kills)
            'Implant' => [89, 'implant'],

            // Cargo variants
            'None/unlisted' => [0, 'cargo'],
            'Cargo' => [5, 'cargo'],
            'CorpDeliveries' => [62, 'cargo'],
            'ShipHangar' => [90, 'cargo'],
            'SpecializedAmmoHold' => [154, 'cargo'],
            'FleetHangar' => [155, 'cargo'],
            'MidRange specialized' => [140, 'cargo'],

            // Other (unmapped)
            'Unknown flag 200' => [200, 'other'],
            'Unknown flag 999' => [999, 'other'],

            // Boundary: flag 10 is NOT low (low starts at 11)
            'Below low range' => [10, 'other'],
        ];
    }
}
