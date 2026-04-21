<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character market order snapshot + history.
 *
 * Populated by SyncPersonalMarketOrders from two ESI endpoints:
 *   - /characters/{id}/orders/          → currently-open orders
 *   - /characters/{id}/orders/history/  → last 90d closed/expired
 *                                          /cancelled (CCP cap)
 *
 * Once a row lands we keep it forever, so history accumulates
 * beyond the 90d ESI window over time. `state` mirrors the ESI
 * history enum plus 'open' for the live-orders endpoint so a
 * single table serves both lists.
 *
 * user_id is denormalised from the character at write time so the
 * portal's "my orders" page can scope in one query without joining
 * through characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_market_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('order_id')->primary();
            $t->unsignedBigInteger('character_id');
            $t->unsignedBigInteger('user_id')->nullable();

            $t->unsignedInteger('type_id');
            $t->unsignedBigInteger('location_id');
            $t->unsignedInteger('region_id');

            $t->boolean('is_buy')->default(false);
            $t->decimal('price', 20, 2);
            $t->unsignedBigInteger('volume_total');
            $t->unsignedBigInteger('volume_remain');
            $t->unsignedBigInteger('min_volume')->default(1);
            $t->unsignedSmallInteger('duration');
            $t->dateTime('issued');
            // 'open' (from /orders/) + CCP's history state enum
            // (expired / cancelled / closed / pending). Unknown values
            // store as 'unknown' so a CCP schema change doesn't break
            // ingest.
            $t->enum('state', ['open', 'expired', 'cancelled', 'closed', 'pending', 'unknown'])->default('unknown');
            $t->boolean('is_corporation')->default(false);
            $t->string('order_range', 16)->nullable();

            $t->timestamp('first_observed_at')->useCurrent();
            $t->timestamp('last_observed_at')->useCurrent();
            $t->timestamp('observed_at')->useCurrent();

            $t->index(['user_id', 'state', 'issued'], 'idx_pmo_user_state_issued');
            $t->index(['character_id', 'state', 'issued'], 'idx_pmo_char_state_issued');
            $t->index(['type_id', 'is_buy'], 'idx_pmo_type_buy');
            $t->index('location_id', 'idx_pmo_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_market_orders');
    }
};
