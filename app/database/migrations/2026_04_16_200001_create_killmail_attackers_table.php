<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| killmail_attackers — one row per attacker per killmail
|--------------------------------------------------------------------------
|
| Stores every participant on the attacker side of a killmail. NPC
| attackers have a null `character_id` but may carry a `faction_id`
| (e.g. CONCORD, Serpentis). Exactly one attacker per killmail has
| `is_final_blow = true`.
|
| No FK constraint on `killmail_id` — the same pattern used for
| market_history. Bulk ingestion performance matters more than
| referential integrity enforcement at the DB level; the application
| layer guarantees consistency.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('killmail_attackers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('killmail_id');

            // Nullable for NPC attackers.
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedBigInteger('alliance_id')->nullable();

            // NPC faction (CONCORD, pirate factions, etc.).
            $table->unsignedInteger('faction_id')->nullable();

            // Ship and weapon used.
            $table->unsignedInteger('ship_type_id')->nullable();
            $table->unsignedInteger('weapon_type_id')->nullable();

            $table->unsignedInteger('damage_done')->default(0);
            $table->boolean('is_final_blow')->default(false);

            // EVE security status at time of kill (-10.0 to 5.0).
            $table->decimal('security_status', 4, 1)->nullable();

            $table->timestamps();

            // -- indexes -------------------------------------------------

            $table->index('killmail_id', 'idx_km_attackers_killmail');
            $table->index('character_id', 'idx_km_attackers_char');
            $table->index('corporation_id', 'idx_km_attackers_corp');
            $table->index('alliance_id', 'idx_km_attackers_alliance');
            $table->index('ship_type_id', 'idx_km_attackers_ship');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('killmail_attackers');
    }
};
