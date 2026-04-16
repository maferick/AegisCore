<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| battle_theater_killmails — which killmails belong to which theater
|--------------------------------------------------------------------------
|
| Many-to-many pivot between battle_theaters and killmails. Logically
| it's 1-to-many: a killmail belongs to at most ONE theater at a time —
| the UNIQUE(killmail_id) index enforces that invariant.
|
| CASCADE on both sides: a theater deletion drops its membership rows
| (the clustering worker re-creates theaters freely and expects this),
| and a killmail being dropped from the parent table (unlikely but not
| impossible during a ref-data reset) cleans up its theater membership.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_theater_killmails', function (Blueprint $table) {
            $table->foreignId('theater_id')
                ->constrained('battle_theaters')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('killmail_id');
            $table->foreign('killmail_id')
                ->references('killmail_id')
                ->on('killmails')
                ->cascadeOnDelete();

            $table->primary(['theater_id', 'killmail_id']);

            // A killmail lives in at most one theater. This is the
            // invariant the clustering worker relies on — see
            // theater_clustering/clusterer.py for the re-assignment path.
            $table->unique('killmail_id', 'uniq_bt_killmails_km');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_theater_killmails');
    }
};
