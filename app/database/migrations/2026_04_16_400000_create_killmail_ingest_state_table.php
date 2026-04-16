<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| killmail_ingest_state — cursor/progress tracking for ingestion sources
|--------------------------------------------------------------------------
|
| Simple key-value state store for the Python killmail ingestion workers.
| Each source (everef, r2z2) tracks its own progress independently.
|
| EVE Ref stores one row per processed day: source='everef',
| state_key='2026-04-15', state_value='23456' (killmail count).
|
| R2Z2 stores one cursor row: source='r2z2', state_key='last_sequence',
| state_value='96088891'.
|
| Laravel creates the table; Python reads/writes it.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('killmail_ingest_state', function (Blueprint $table) {
            $table->string('source', 32);
            $table->string('state_key', 128);
            $table->text('state_value');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['source', 'state_key'], 'pk_killmail_ingest_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('killmail_ingest_state');
    }
};
