<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| corporation_alliance_history — full alliance membership timeline per corp
|--------------------------------------------------------------------------
|
| One row per alliance membership period per corporation. Sourced from
| ESI GET /corporations/{id}/alliancehistory/ (public, unauthed, cached
| 1 day by CCP).
|
| Mirrors character_corporation_history one level up: "what alliance was
| this corp in at time Y?" answers the event-time rendering question on
| killmail detail pages where a corp's alliance on the killmail differs
| from its current alliance (common in post-war migrations).
|
| `alliance_id` NULL means the corporation was in no alliance at that
| point — CCP's endpoint returns entries without `alliance_id` for
| independent-corp periods, and we preserve the distinction.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_alliance_history', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->unsignedBigInteger('record_id');

            $table->dateTime('start_date');
            $table->dateTime('end_date')->nullable();

            $table->boolean('is_deleted')->default(false);

            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['corporation_id', 'record_id'], 'uniq_corp_ally_history');

            // "What alliance was corp X in at time Y?"
            $table->index(['corporation_id', 'start_date', 'end_date'], 'idx_corp_ally_history_timeline');

            // "Which corps were in alliance X?"
            $table->index(['alliance_id', 'start_date'], 'idx_corp_ally_history_alliance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_alliance_history');
    }
};
