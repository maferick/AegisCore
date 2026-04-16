<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| esi_entity_names — shared name cache for CCP entity IDs
|--------------------------------------------------------------------------
|
| Every ESI entity (character, corporation, alliance, faction) that the
| platform has ever resolved via POST /universe/names/ gets a row here.
| This consolidates the 3+ independent name-resolution call sites into a
| single DB-backed cache that:
|
|   - Avoids duplicate ESI calls across standings sync, donation polling,
|     and admin label creation.
|   - Serves stale names when ESI is unreachable.
|   - Provides a browseable audit of known entities.
|
| The table is append-mostly with periodic upserts. No FK constraints —
| entity IDs are bare CCP bigints and consumers join by convention.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esi_entity_names', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_id')->primary();
            $table->string('name', 150);
            $table->string('category', 32);  // character, corporation, alliance, faction, etc.
            $table->timestamp('cached_at')->useCurrent();

            $table->index(['category', 'name'], 'idx_esi_entity_names_cat_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esi_entity_names');
    }
};
