<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| battle_theater_participants — per-pilot rollup inside a theater
|--------------------------------------------------------------------------
|
| The single source of truth for pilot-level metrics per ADR-0006 § 1.
| Every number on the detail page for a given pilot in a given theater
| comes from this row; no downstream aggregation derives from the raw
| killmail tables at render time.
|
| The metric contract (locked; do not mutate without an ADR amend):
|
|   - kills           — COUNT(DISTINCT killmail_id WHERE pilot ∈ attackers)
|                       Includes zero-damage EWAR involvement.
|   - final_blows     — SUBSET of kills: COUNT(killmails WHERE pilot.final_blow=1)
|   - damage_done     — SUM(attacker.damage_done) across participated mails
|   - damage_taken    — SUM(victim.damage_taken) where pilot is victim
|   - deaths          — COUNT(killmails WHERE pilot is victim)
|   - isk_lost        — SUM(killmails.total_value) where pilot is victim
|
| Side ISK Lost and Side ISK Killed are computed at render time from
| `isk_lost` grouped by the viewer-relative side — no columns here
| represent side membership (see ADR-0006 § 2).
|
| alliance_id + corporation_id are denormalised from the killmail rows
| at clustering time. These can change between the killmail and "now"
| (pilot jumped corp); the theater reports what the pilot's affiliation
| was at fight time, consistent with the killmails themselves.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_theater_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('theater_id')
                ->constrained('battle_theaters')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedBigInteger('alliance_id')->nullable();

            // Metric columns. unsignedInteger for counts, unsignedBigInteger
            // for raw HP (killmails with capital hulls can top 1e9 HP on
            // a single row; a 32-bit int caps around 2e9).
            $table->unsignedInteger('kills')->default(0);
            $table->unsignedInteger('final_blows')->default(0);
            $table->unsignedBigInteger('damage_done')->default(0);
            $table->unsignedBigInteger('damage_taken')->default(0);
            $table->unsignedInteger('deaths')->default(0);
            $table->decimal('isk_lost', 24, 2)->default(0);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // A pilot appears at most once per theater.
            $table->unique(['theater_id', 'character_id'], 'uniq_btp_theater_char');

            // "Which theaters was this pilot in?" — cross-theater pilot
            // feed (ADR-0006 § Follow-ups #5).
            $table->index('character_id', 'idx_btp_char');

            // "Which alliances showed up in this theater?" — render-time
            // side grouping (ADR-0006 § 2).
            $table->index(['theater_id', 'alliance_id'], 'idx_btp_theater_alliance');

            // "Biggest losers/killers in this theater" — detail page sort.
            $table->index(['theater_id', 'isk_lost'], 'idx_btp_theater_isk');
            $table->index(['theater_id', 'kills'], 'idx_btp_theater_kills');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_theater_participants');
    }
};
