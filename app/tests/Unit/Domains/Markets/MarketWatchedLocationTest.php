<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Markets;

use App\Domains\Markets\Models\MarketHub;
use App\Domains\Markets\Models\MarketWatchedLocation;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Smoke tests for the MarketWatchedLocation model's belt-and-braces
 * guards, updated for ADR-0005 (canonical hub overlay + retired
 * `owner_user_id`). Classification now lives on
 * `market_hubs.is_public_reference`; the Jita guard keys on
 * `(region_id, location_id)` alone.
 *
 * The Filament resource already protects the Jita row by hiding the
 * delete buttons; these tests verify the model-level `deleting()`
 * hook catches any code path that bypasses the UI (tinker, artisan,
 * a direct ->delete() call from a future Service).
 */
final class MarketWatchedLocationTest extends TestCase
{
    // DatabaseMigrations (not RefreshDatabase) so the seeded Jita row
    // + hub from the migration chain land in the DB exactly the way
    // they would in production.
    use DatabaseMigrations;

    public function test_jita_baseline_row_is_seeded_by_migration(): void
    {
        $jita = MarketWatchedLocation::query()
            ->where('region_id', MarketWatchedLocation::JITA_REGION_ID)
            ->where('location_id', MarketWatchedLocation::JITA_LOCATION_ID)
            ->first();

        self::assertNotNull($jita, 'Jita seeder migration must populate the baseline row');
        self::assertSame(MarketWatchedLocation::LOCATION_TYPE_NPC_STATION, $jita->location_type);
        self::assertSame(MarketWatchedLocation::JITA_REGION_ID, (int) $jita->region_id);
        self::assertTrue($jita->enabled);
        self::assertTrue($jita->isJita());
        self::assertTrue($jita->isNpcStation());
        self::assertFalse($jita->isPlayerStructure());

        // Post-ADR-0005 the watched row is backed by a canonical hub
        // with `is_public_reference = true`. Assert the linkage so a
        // future migration that forgets to attach the hub is caught
        // here instead of at runtime.
        self::assertNotNull($jita->hub_id, 'Jita watched row must reference a canonical hub');
        $hub = $jita->hub;
        self::assertNotNull($hub);
        self::assertTrue($hub->is_public_reference);
    }

    public function test_deleting_jita_row_throws(): void
    {
        $jita = MarketWatchedLocation::query()
            ->where('region_id', MarketWatchedLocation::JITA_REGION_ID)
            ->where('location_id', MarketWatchedLocation::JITA_LOCATION_ID)
            ->sole();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Jita 4-4 is the platform baseline');

        $jita->delete();
    }

    public function test_deleting_non_jita_row_succeeds(): void
    {
        $hub = MarketHub::query()->create([
            'location_type' => MarketHub::LOCATION_TYPE_NPC_STATION,
            'location_id' => 60_011_866, // Dodixie IX - Moon 20 - FedMart
            'region_id' => 10_000_032,   // Sinq Laison
            'is_public_reference' => true,
            'is_active' => true,
        ]);

        $row = MarketWatchedLocation::query()->create([
            'location_type' => MarketWatchedLocation::LOCATION_TYPE_NPC_STATION,
            'region_id' => 10_000_032,
            'location_id' => 60_011_866,
            'hub_id' => $hub->id,
            'name' => 'Dodixie',
            'enabled' => true,
        ]);

        $deleted = $row->delete();

        self::assertTrue($deleted);
        self::assertSame(
            0,
            MarketWatchedLocation::query()->where('id', $row->id)->count(),
            'Non-Jita row should delete cleanly'
        );
    }

    public function test_casts_boolean_and_integer_columns(): void
    {
        $hub = MarketHub::query()->create([
            'location_type' => MarketHub::LOCATION_TYPE_PLAYER_STRUCTURE,
            'location_id' => 1_000_000_000_000,
            'region_id' => 10_000_002,
            'is_public_reference' => true,
            'is_active' => true,
        ]);

        $row = MarketWatchedLocation::query()->create([
            'location_type' => MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE,
            'region_id' => 10_000_002,
            'location_id' => 1_000_000_000_000,
            'hub_id' => $hub->id,
            'name' => 'Some Keepstar',
            'enabled' => true,
            'consecutive_failure_count' => 0,
        ]);

        $row->refresh();

        self::assertIsBool($row->enabled);
        self::assertIsInt($row->region_id);
        self::assertIsInt($row->location_id);
        self::assertIsInt($row->hub_id);
        self::assertIsInt($row->consecutive_failure_count);
    }
}
