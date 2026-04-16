<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| killmails — canonical killmail header + victim + valuation aggregates
|--------------------------------------------------------------------------
|
| One row per killmail. The victim is inlined (not a separate table)
| because there is exactly one victim per killmail — a join would add
| complexity without normalisation benefit.
|
| `killmail_id` is CCP's globally unique killmail ID, used as the
| natural PK (no auto-increment). This aligns with the ESI contract
| where killmail_id is the stable identifier for fetch + dedup.
|
| Location is denormalised from ref_solar_systems at write time:
| `constellation_id` and `region_id` are stored directly to avoid
| joins on every filtered query (battle theaters, regional reports).
|
| Valuation aggregates are denormalised from killmail_items for fast
| reporting. The per-item breakdown lives in killmail_items; these
| columns are the pre-computed rollups updated during enrichment.
|
| `enriched_at` is the enrichment queue cursor: NULL means the
| killmail was ingested but not yet valued/enriched. A simple
| `WHERE enriched_at IS NULL` drives the enrichment backlog.
|
| `enrichment_version` supports re-enrichment sweeps: when valuation
| logic changes, bump the version and re-enrich rows with older
| versions.
|
| See docs/adr/0003-data-placement-freeze.md — killmails are MariaDB
| canonical.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('killmails', function (Blueprint $table) {
            // CCP's killmail ID — natural PK, globally unique.
            $table->unsignedBigInteger('killmail_id')->primary();

            // CCP hash used to fetch the killmail from ESI.
            $table->char('killmail_hash', 40);

            // Location — denormalised for query speed.
            $table->unsignedInteger('solar_system_id');
            $table->unsignedInteger('constellation_id');
            $table->unsignedInteger('region_id');

            // When the kill happened (from ESI, UTC).
            $table->dateTime('killed_at');

            // -- victim (inlined, exactly 1 per killmail) ----------------

            // Nullable: structure kills and NPC losses have no victim
            // character. Corp/alliance may also be null for NPC entities.
            $table->unsignedBigInteger('victim_character_id')->nullable();
            $table->unsignedBigInteger('victim_corporation_id')->nullable();
            $table->unsignedBigInteger('victim_alliance_id')->nullable();
            $table->unsignedInteger('victim_ship_type_id');
            $table->unsignedInteger('victim_damage_taken')->default(0);

            // -- valuation aggregates (filled during enrichment) ----------

            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('hull_value', 20, 2)->default(0);
            $table->decimal('fitted_value', 20, 2)->default(0);
            $table->decimal('cargo_value', 20, 2)->default(0);
            $table->decimal('drone_value', 20, 2)->default(0);

            // -- metadata ------------------------------------------------

            $table->unsignedInteger('attacker_count')->default(0);
            $table->boolean('is_npc_kill')->default(false);
            $table->boolean('is_solo_kill')->default(false);
            $table->unsignedInteger('war_id')->nullable();

            // -- enrichment tracking -------------------------------------

            $table->unsignedInteger('enrichment_version')->default(1);
            $table->dateTime('enriched_at')->nullable();

            // When we first ingested this killmail.
            $table->dateTime('ingested_at');

            $table->timestamps();

            // -- indexes -------------------------------------------------

            // Time-range scans (recent kills, daily reports).
            $table->index('killed_at', 'idx_killmails_killed_at');

            // Regional / theater queries.
            $table->index('region_id', 'idx_killmails_region');

            // Victim lookups (pilot dossier, corp/alliance profiles).
            $table->index('victim_character_id', 'idx_killmails_victim_char');
            $table->index('victim_corporation_id', 'idx_killmails_victim_corp');
            $table->index('victim_alliance_id', 'idx_killmails_victim_alliance');

            // Hull-type analytics (most-lost ships, doctrine losses).
            $table->index('victim_ship_type_id', 'idx_killmails_victim_ship');

            // Enrichment queue: WHERE enriched_at IS NULL.
            $table->index('enriched_at', 'idx_killmails_enriched');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('killmails');
    }
};
