<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| character_corporation_history — full corp membership timeline per character
|--------------------------------------------------------------------------
|
| One row per corporation membership period per character. Sourced from
| ESI GET /characters/{id}/corporationhistory/ (public, unauthed, cached
| 1 day by CCP).
|
| `start_date` is when the character joined the corp. `end_date` is
| derived: the start_date of the next record, or NULL if the character
| is still in that corp.
|
| `record_id` is CCP's incrementing sequence for canonical ordering
| when dates are ambiguous (simultaneous corp changes within the same
| second).
|
| Used by the killmail enrichment pipeline to answer "what corp was this
| character in at the time of this killmail?" for event-time affiliation
| snapshots.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_corporation_history', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('record_id');

            $table->dateTime('start_date');
            $table->dateTime('end_date')->nullable();

            $table->boolean('is_deleted')->default(false);

            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            // One record per (character, record_id).
            $table->unique(['character_id', 'record_id'], 'uniq_char_corp_history');

            // "What corp was character X in at time Y?"
            $table->index(['character_id', 'start_date', 'end_date'], 'idx_char_corp_history_timeline');

            // "Which characters were in corp X?"
            $table->index(['corporation_id', 'start_date'], 'idx_char_corp_history_corp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_corporation_history');
    }
};
