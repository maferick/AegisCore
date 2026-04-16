<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| killmail_items — one row per item line on a killmail
|--------------------------------------------------------------------------
|
| Every destroyed or dropped item from a killmail. `flag` is CCP's
| inventory flag that indicates the slot position (highSlot0 = 27,
| medSlot0 = 19, etc.). The derived `slot_category` ENUM normalises
| flags into human-readable categories for grouping queries.
|
| Valuation columns (`unit_value`, `total_value`, `valuation_date`,
| `valuation_source`) are nullable because items are written during
| ingestion but valued during the enrichment pass. This two-phase
| approach keeps ingestion fast and decouples it from market data
| availability.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('killmail_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('killmail_id');

            // CCP type ID — joins to ref_item_types.
            $table->unsignedInteger('type_id');

            // CCP inventory flag (slot position).
            $table->unsignedInteger('flag')->default(0);

            $table->unsignedInteger('quantity_destroyed')->default(0);
            $table->unsignedInteger('quantity_dropped')->default(0);

            // 0 = stackable, 1 = assembled (singleton), 2 = blueprint copy.
            $table->tinyInteger('singleton')->default(0);

            // Derived from `flag` at write time for grouping queries.
            $table->enum('slot_category', [
                'high', 'mid', 'low', 'rig', 'subsystem', 'service',
                'cargo', 'drone_bay', 'fighter_bay', 'implant', 'other',
            ])->default('other');

            // -- valuation (filled during enrichment) --------------------

            // Historical Jita price per unit.
            $table->decimal('unit_value', 20, 2)->nullable();

            // unit_value * (quantity_destroyed + quantity_dropped).
            $table->decimal('total_value', 20, 2)->nullable();

            // The trade_date from market_history used for pricing.
            $table->date('valuation_date')->nullable();

            // 'jita_average', 'base_price', 'unavailable'.
            $table->string('valuation_source', 32)->nullable();

            $table->timestamps();

            // -- indexes -------------------------------------------------

            $table->index('killmail_id', 'idx_km_items_killmail');
            $table->index('type_id', 'idx_km_items_type');
            $table->index(['killmail_id', 'slot_category'], 'idx_km_items_killmail_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('killmail_items');
    }
};
