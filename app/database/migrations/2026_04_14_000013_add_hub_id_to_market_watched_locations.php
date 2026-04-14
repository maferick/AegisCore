<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| market_watched_locations.hub_id — FK into the canonical hub table
|--------------------------------------------------------------------------
|
| ADR-0005 introduces market_hubs as the canonical policy/UX layer;
| market_watched_locations stays as the poller's driver table so the
| Python runner keeps working unchanged during the transition.
|
| This migration adds a nullable FK so the two models can coexist:
|
|   - NULL during the transition gap, pre-backfill.
|   - Backfilled by 2026_04_14_000015 to point every existing
|     watched-locations row at a canonical hub (Jita + future donor
|     rows alike).
|   - Required by the application layer (the Eloquent model will
|     assert non-null on create after backfill runs) but left
|     nullable at the DB level so a future poller-side refactor can
|     drop the watched-locations table without tripping a NOT NULL
|     constraint during the drop step.
|
| ON DELETE RESTRICT (not CASCADE) because the watched-locations row
| is the physical polling lane — deleting a hub while a watched row
| still points at it would leave the poller driving against a ghost
| target. The correct sequence is: deactivate the hub → stop polling
| → delete watched row → delete hub.
|
| See docs/adr/0005-private-market-hub-overlay.md § Transition.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_watched_locations', function (Blueprint $table) {
            $table->foreignId('hub_id')
                ->nullable()
                ->after('location_id')
                ->constrained('market_hubs')
                ->restrictOnDelete();

            // Lookup probe: "which watched row(s) drive this hub".
            // Usually one, but the schema admits future many-to-one
            // if we ever split a hub across multiple regional proxies.
            $table->index('hub_id', 'idx_watched_hub');
        });
    }

    public function down(): void
    {
        Schema::table('market_watched_locations', function (Blueprint $table) {
            $table->dropIndex('idx_watched_hub');
            $table->dropConstrainedForeignId('hub_id');
        });
    }
};
