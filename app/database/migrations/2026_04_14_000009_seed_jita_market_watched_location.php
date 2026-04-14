<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Seed the Jita 4-4 market_watched_locations row (platform baseline)
|--------------------------------------------------------------------------
|
| ADR-0004 § Jita always-on: Jita is the platform's permanent baseline
| for market data. One NPC row, owner_user_id = NULL (platform default),
| enabled = true. The Python market poller's "walk enabled rows" loop
| picks this up immediately on first run.
|
| Constants pinned here so the numeric IDs only appear once in the repo:
|
|   region_id   = 10000002  (The Forge)
|   location_id = 60003760  (Jita IV - Moon 4 - Caldari Navy Assembly Plant)
|
| Re-runnable: the insert is guarded by a lookup, so rolling back and
| rolling forward doesn't double-insert. The unique index on
| (owner_user_id, location_id) doesn't help here because MySQL treats
| NULL as distinct in unique keys — two `owner_user_id = NULL, location_id
| = 60003760` rows would both satisfy the unique. The guard below makes
| the migration idempotent without relying on that.
|
| `name` is pre-populated so the poller can log meaningfully before the
| weekly name-refresh cadence has had its first tick. Structure rows
| resolve their names from /universe/structures/ on insert and refresh
| weekly; NPC rows could resolve from ref_npc_stations join, but a
| constant is plenty here.
|
| See docs/adr/0004-market-data-ingest.md § Live polling.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('market_watched_locations')
            ->whereNull('owner_user_id')
            ->where('location_id', 60003760)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('market_watched_locations')->insert([
            'location_type' => 'npc_station',
            'region_id' => 10000002,
            'location_id' => 60003760,
            'name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'owner_user_id' => null,
            'enabled' => true,
            'last_polled_at' => null,
            'consecutive_failure_count' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'disabled_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep the row if it's been polled — removing a row with poll
        // history is an operator's call, not a rollback's. A fresh
        // seed (last_polled_at IS NULL, no failure history) is safe
        // to drop because that's exactly what `up()` re-creates.
        DB::table('market_watched_locations')
            ->whereNull('owner_user_id')
            ->where('location_id', 60003760)
            ->whereNull('last_polled_at')
            ->where('consecutive_failure_count', 0)
            ->delete();
    }
};
