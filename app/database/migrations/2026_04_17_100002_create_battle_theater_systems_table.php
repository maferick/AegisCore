<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| battle_theater_systems — per-solar-system rollup inside a theater
|--------------------------------------------------------------------------
|
| Denormalised count + ISK-lost per system in the theater. A "fleet
| fight that spanned 3 systems" produces 3 rows here for 1 theater row.
| Used by the detail page's "where did the fighting happen" section —
| could be derived at query time, but denormalising keeps the render
| path free of a JOIN against killmails + ref_solar_systems.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_theater_systems', function (Blueprint $table) {
            $table->id();

            $table->foreignId('theater_id')
                ->constrained('battle_theaters')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('solar_system_id');

            $table->unsignedInteger('kill_count')->default(0);
            $table->decimal('isk_lost', 24, 2)->default(0);

            // Bounds of activity in THIS system (subset of the theater's
            // full time window). Helps the timeline view render per-
            // system lanes if we ever want that.
            $table->timestamp('first_kill_at')->nullable();
            $table->timestamp('last_kill_at')->nullable();

            $table->timestamps();

            $table->unique(['theater_id', 'solar_system_id'], 'uniq_bts_theater_system');
            $table->index('solar_system_id', 'idx_bts_system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_theater_systems');
    }
};
