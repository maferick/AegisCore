<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| battle_theaters — one row per cluster of dense killmail activity
|--------------------------------------------------------------------------
|
| Owned by the Python theater_clustering worker. Laravel reads; never
| writes. See ADR-0006 for the full design.
|
| Rollup columns (total_*, participant_count) are denormalised for the
| index / list page — recomputing them in SQL over the pilot + killmail
| tables for every list render would make the page latency-sensitive to
| a GROUP BY across millions of attacker rows. The clustering pass
| writes them in the same transaction as the theater membership tables,
| so they're always consistent with the pivot state the reader sees.
|
| locked_at + snapshot_json: once a theater is 48h old, the clustering
| pass stops re-evaluating it and snapshots the rendered payload. This
| is the stable-publication horizon described in ADR-0006 § 3.
| snapshot_json holds the full read-side JSON (participants, systems,
| timeline points) for the locked detail page — avoids a 6-table join
| on every view of an old fight.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_theaters', function (Blueprint $table) {
            $table->id();

            // Denormalised geography — pulled from the median killmail
            // of the cluster. "Primary system" is the system that
            // contributed the most kills to the theater, used as the
            // list-page label.
            $table->unsignedBigInteger('primary_system_id');
            $table->unsignedBigInteger('region_id');

            // Time bounds cover every killmail in the theater.
            $table->timestamp('start_time')->index();
            $table->timestamp('end_time')->index();

            // Rollups — single source is the pilot table's aggregates,
            // but these are denormalised onto the theater row so the
            // index page can ORDER BY total_isk_lost without a JOIN.
            $table->unsignedBigInteger('total_kills')->default(0);
            $table->decimal('total_isk_lost', 24, 2)->default(0);
            $table->unsignedInteger('participant_count')->default(0);
            $table->unsignedInteger('system_count')->default(0);

            // Lock + snapshot. locked_at is nullable — NULL means the
            // clustering worker will still reconsider this theater on
            // the next pass. Once non-null, the row is frozen and
            // snapshot_json carries the materialised detail payload.
            $table->timestamp('locked_at')->nullable()->index();
            $table->longText('snapshot_json')->nullable();

            $table->timestamps();

            // Helpful composite for "latest theaters involving system X"
            // + "most expensive fights in region Y".
            $table->index(['region_id', 'end_time'], 'idx_theaters_region_end');
            $table->index(['primary_system_id', 'end_time'], 'idx_theaters_system_end');
            $table->index(['total_isk_lost', 'end_time'], 'idx_theaters_isk_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_theaters');
    }
};
