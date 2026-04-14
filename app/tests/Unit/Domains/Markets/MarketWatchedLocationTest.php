<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Markets;

use App\Domains\Markets\Models\MarketWatchedLocation;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Smoke tests for the MarketWatchedLocation model's belt-and-braces
 * guards.
 *
 * The Filament resource already protects the Jita row by hiding the
 * delete buttons; these tests verify the model-level `deleting()`
 * hook catches any code path that bypasses the UI (tinker, artisan,
 * a direct ->delete() call from a future Service).
 */
final class MarketWatchedLocationTest extends TestCase
{
    // DatabaseMigrations (not RefreshDatabase) so the seeded Jita row
    // from `2026_04_14_000009_seed_jita_market_watched_location.php`
    // lands in the DB exactly the way it would in production.
    use DatabaseMigrations;

    public function test_jita_baseline_row_is_seeded_by_migration(): void
    {
        $jita = MarketWatchedLocation::query()
            ->whereNull('owner_user_id')
            ->where('location_id', MarketWatchedLocation::JITA_LOCATION_ID)
            ->first();

        self::assertNotNull($jita, 'Jita seeder migration must populate the baseline row');
        self::assertSame(MarketWatchedLocation::LOCATION_TYPE_NPC_STATION, $jita->location_type);
        self::assertSame(MarketWatchedLocation::JITA_REGION_ID, (int) $jita->region_id);
        self::assertTrue($jita->enabled);
        self::assertTrue($jita->isJita());
        self::assertTrue($jita->isNpcStation());
        self::assertFalse($jita->isPlayerStructure());
    }

    public function test_deleting_jita_row_throws(): void
    {
        $jita = MarketWatchedLocation::query()
            ->whereNull('owner_user_id')
            ->where('location_id', MarketWatchedLocation::JITA_LOCATION_ID)
            ->sole();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Jita 4-4 is the platform baseline');

        $jita->delete();
    }

    public function test_deleting_non_jita_row_succeeds(): void
    {
        $row = MarketWatchedLocation::query()->create([
            'location_type' => MarketWatchedLocation::LOCATION_TYPE_NPC_STATION,
            'region_id' => 10_000_032,   // Sinq Laison
            'location_id' => 60_011_866, // Dodixie IX - Moon 20 - FedMart
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

    public function test_deleting_row_with_matching_ids_but_donor_owned_succeeds(): void
    {
        // Defensive: the Jita guard keys on `owner_user_id IS NULL`.
        // A donor-owned row that happens to share the Jita location_id
        // (wouldn't happen in practice — structures use 13-digit IDs
        // — but belt-and-braces) should not be protected.
        $user = \App\Models\User::query()->create([
            'name' => 'Test Donor',
            'email' => 'donor@example.test',
            'password' => 'x',
        ]);

        $row = MarketWatchedLocation::query()->create([
            'location_type' => MarketWatchedLocation::LOCATION_TYPE_NPC_STATION,
            'region_id' => MarketWatchedLocation::JITA_REGION_ID,
            'location_id' => MarketWatchedLocation::JITA_LOCATION_ID,
            'name' => 'Not really Jita',
            'owner_user_id' => $user->id,
            'enabled' => false,
        ]);

        self::assertFalse($row->isJita(), 'Donor-owned row is not the platform Jita row');
        self::assertTrue($row->delete());
    }

    public function test_casts_boolean_and_integer_columns(): void
    {
        $row = MarketWatchedLocation::query()->create([
            'location_type' => MarketWatchedLocation::LOCATION_TYPE_PLAYER_STRUCTURE,
            'region_id' => 10_000_002,
            'location_id' => 1_000_000_000_000,
            'name' => 'Some Keepstar',
            'enabled' => true,
            'consecutive_failure_count' => 0,
        ]);

        $row->refresh();

        self::assertIsBool($row->enabled);
        self::assertIsInt($row->region_id);
        self::assertIsInt($row->location_id);
        self::assertIsInt($row->consecutive_failure_count);
    }
}
