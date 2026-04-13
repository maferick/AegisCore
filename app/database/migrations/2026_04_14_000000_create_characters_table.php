<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| characters — EVE character mirrors
|--------------------------------------------------------------------------
|
| One row per EVE character AegisCore has seen. Phase 1 only populates the
| row on SSO login (`App\Services\Eve\Sso\EveSsoClient` → upsert via
| `character_id`); phase 2 fills in corp + alliance from ESI polling and
| adds rows for characters seen in killmails / spy reports without ever
| logging in themselves.
|
| `user_id` is nullable — not every character maps to a User (most will
| never log in), and a User can have multiple characters later.
|
| No FK to a future ref_characters table: `character_id` is CCP's
| permanent ID and self-documenting. We do FK to `users.id` so cascading
| user deletes don't orphan character rows.
|
| See docs/adr/0002-eve-sso-and-esi-client.md for the SSO + admin-gate
| integration that drives this table's phase-1 writes.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();

            // CCP's EVE character ID. Unique across all of New Eden, never
            // re-used, never re-assigned. This is the stable join key —
            // `name` can change, `character_id` cannot. Bigint because
            // future-proof: current IDs fit in int32 but CCP has allocated
            // into the high-int range on Serenity / test universes.
            $table->unsignedBigInteger('character_id')->unique();

            // Current EVE character name. Mutable (biomass + rename, or
            // CCP-admin rename). We refresh on each SSO login.
            $table->string('name', 100);

            // Filled in phase 2 from ESI `/characters/{id}/`. Phase 1
            // login only gets `publicData` scope, which includes corp but
            // we don't call ESI on login (keeps the callback synchronous).
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedBigInteger('alliance_id')->nullable();

            // Owning user, if this character ever logged in via SSO.
            // Cascade on delete because if the User row is gone, the
            // SSO-linked character row is meaningless (phase 2 service
            // characters get a different `user_id` convention — null
            // with a separate `owned_by_user_id` column if we need it).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();

            // Filters on "characters owned by this user" — tiny today
            // (one row each) but the query shape stays the same at
            // phase-2 scale.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
