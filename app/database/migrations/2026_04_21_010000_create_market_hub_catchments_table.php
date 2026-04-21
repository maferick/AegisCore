<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voronoi catchment mapping: solar system → nearest market hub by
 * stargate jumps. Populated by markets:rebuild-hub-catchments via
 * Neo4j BFS. Used by /portal/my-doctrines/market to scope burn
 * (weekly hull/module losses) to kills geographically serviced by
 * the selected stock hub.
 *
 * One row per system; ties broken by lower hub_id (stable). Systems
 * outside every hub's max-jump radius are absent, so catchment joins
 * naturally drop deep-roam kills that wouldn't reasonably refill
 * from any registered hub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_hub_catchments', function (Blueprint $table) {
            $table->unsignedInteger('solar_system_id')->primary();
            $table->unsignedBigInteger('hub_id');
            $table->unsignedSmallInteger('jumps');
            $table->timestamp('computed_at')->useCurrent();

            $table->index('hub_id');
            $table->foreign('hub_id')->references('id')->on('market_hubs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_hub_catchments');
    }
};
