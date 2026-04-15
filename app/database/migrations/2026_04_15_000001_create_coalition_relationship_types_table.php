<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| coalition_relationship_types — how an entity relates to a bloc
|--------------------------------------------------------------------------
|
| The second half of the normalised label taxonomy, paired with
| coalition_blocs. A label like "wc.member" decomposes into:
|
|   bloc_code          = wc
|   relationship_code  = member
|
| Kept as a separate registry (not an ENUM, not columns on blocs) because
| both sides evolve independently and because multiple labels per entity
| are legal — an entity can be a `wc.member` AND a `wc.logistics`.
|
| Why this matters for the resolver:
|
|   - `inherits_alignment` controls whether classification flows down
|     from the bloc. `member` and `affiliate` inherit. `renter` does
|     not inherit strong alignment — renters may or may not be standing
|     with the holding alliance's contacts in-game.
|   - `default_role` gives the donor-facing UI a consistent label role
|     tag independent of whatever the bloc's default_role says.
|   - `display_order` controls how multiple relationships on one entity
|     render (most significant first: member > affiliate > logistics >
|     renter).
|
| Seeded with: member, affiliate, allied, renter, logistics. Seeder
| lives alongside this migration (coalition seeders, phase 0).
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coalition_relationship_types', function (Blueprint $table) {
            $table->id();

            // Short code paired with bloc_code in raw labels. 32 chars
            // matches coalition_blocs.bloc_code; actual codes stay under
            // 12 (`logistics` is the current longest).
            $table->string('relationship_code', 32)->unique();

            // Human-readable name for admin UI and donor-facing copy.
            $table->string('display_name', 100);

            // Default role the resolver assigns to entities carrying
            // this relationship, unless a more specific role comes from
            // an override or a manual tag. VARCHAR rather than ENUM so
            // adding a new role (e.g. `capital`, `subcap`, `wardec`)
            // later is a data change, not a schema change — this field
            // is narrower-purpose than coalition_blocs.default_role.
            $table->string('default_role', 32)->default('combat');

            // Whether an entity carrying this relationship to a bloc
            // inherits the bloc's alignment. `member` / `affiliate` /
            // `allied` → yes. `renter` → no, because rental contracts
            // don't imply diplomatic alignment. `logistics` is a
            // judgement call we surface in the resolver and default to
            // "yes" (support entities typically stand with their
            // principal).
            $table->boolean('inherits_alignment')->default(true);

            // Sort order for UI and for "which relationship wins" when
            // an entity has multiple. Lower numbers render first /
            // outrank in multi-label display. 1 = member, 99 = most
            // peripheral.
            $table->unsignedSmallInteger('display_order')->default(50);

            $table->timestamps();

            // Lookup probe: the resolver loads the full type set once
            // and indexes by relationship_code in memory; the display
            // path orders by display_order.
            $table->index(['display_order', 'relationship_code'], 'idx_coalition_rel_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coalition_relationship_types');
    }
};
