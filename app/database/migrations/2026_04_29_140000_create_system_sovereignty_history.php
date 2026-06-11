<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily sovereignty snapshot table — one row per (system, day). Built
 * from system_sovereignty by SyncSovereigntyCommand on each run, with
 * ON DUPLICATE IGNORE keyed on the (solar_system_id, captured_on)
 * composite so multiple runs in the same UTC day are idempotent.
 *
 * Powers "Sov flips between date A and date B" diffing for the war
 * report's Sov war scoreboard. Without history all we could show was
 * the current snapshot, which doesn't answer "did WC lose systems?".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_sovereignty_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedBigInteger('alliance_id')->nullable();
            $t->unsignedBigInteger('corporation_id')->nullable();
            $t->unsignedInteger('faction_id')->nullable();
            $t->date('captured_on');
            $t->timestamp('captured_at');

            $t->unique(['solar_system_id', 'captured_on'], 'uniq_sov_hist_sys_day');
            $t->index('captured_on', 'idx_sov_hist_day');
            $t->index(['alliance_id', 'captured_on'], 'idx_sov_hist_alliance_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_sovereignty_history');
    }
};
