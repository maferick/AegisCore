<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| outbox — Laravel ↔ Python plane boundary
|--------------------------------------------------------------------------
|
| Schema follows docs/CONTRACTS.md § Plane boundary verbatim. Column names
| and sizes are the stable interface that the Python relay consumes —
| don't drift without bumping the contract.
|
| Two columns are additive to the contract (both nullable/defaulted, so
| existing consumers keep working):
|   - producer  : "laravel" today; future producers must claim a distinct
|                 name so we can filter + replay per producer.
|   - version   : payload shape version; bump on non-additive payload
|                 changes and gate consumer behavior on it.
|
*/
return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            // Monotonic per-node sequence. Consumers use this as the cursor.
            $table->bigIncrements('id');

            // Deduplication key. ULIDs are 26-char Crockford base32, sortable
            // by time, and collision-resistant across producers.
            $table->char('event_id', 26)->unique();

            // `<aggregate_type>.<verb-past-tense>`, e.g. "killmail.ingested".
            $table->string('event_type', 128);

            // Aggregate reference — supports per-entity replay / audit.
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);

            // Full event body. JSON (not TEXT) so MariaDB validates
            // structure + allows JSON functions on replay.
            $table->json('payload');

            // Additive to the contract — producer identity + payload
            // schema version.
            $table->string('producer', 64)->default('laravel');
            $table->unsignedInteger('version')->default(1);

            // Consumer state.
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('processed_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // Hot index for the consumer loop:
            //   SELECT * FROM outbox
            //    WHERE processed_at IS NULL
            //    ORDER BY id
            //    FOR UPDATE SKIP LOCKED
            //    LIMIT N;
            $table->index(['processed_at', 'id'], 'idx_unprocessed');

            // Per-aggregate replay / audit.
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_aggregate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
