<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| market_hubs — canonical market source (the policy/UX layer)
|--------------------------------------------------------------------------
|
| One row per unique (location_type, location_id). Serves as the stable
| conceptual identity for a market source that the rest of the platform
| references:
|
|   - Jita 4-4 (and any future NPC hubs) → one row, `is_public_reference
|     = true`, no collectors, no entitlements (publicly visible to all
|     users, polled by the platform service token).
|   - Donor / admin player structure    → one row, `is_public_reference
|     = false`, one-or-more collectors (tokens), one-or-more
|     entitlements (viewers).
|
| Why canonical (not owner-centric): ADR-0005. A hub is not "Donor A's
| Keepstar" — it is "Keepstar X, currently collected by whichever
| donor(s) have docking rights". This avoids the upgrade trap where a
| hub becomes unreadable the moment the seeding donor churns, and lets
| a fresh donor attach as a replacement collector without the viewers
| noticing.
|
| UNIQUE(location_type, location_id) enforces dedup-on-register: a
| second donor attaching the same structure finds the existing row
| and becomes an additional collector + viewer, rather than forking a
| parallel polling lane. This satisfies the "reuse on dedup" rule and
| keeps ESI cost / InfluxDB cardinality linear in structures, not
| donors.
|
| `is_active` is the operator / automation kill switch. Distinct from
| "all collectors failing", which is represented implicitly by every
| `market_hub_collectors.is_active = false` — the poller freezes a hub
| when no active collectors remain, but does not flip `is_active`
| because an admin may rescue it with a token.
|
| `active_collector_character_id` is a denormalised convenience pointer
| maintained by the Python poller when it picks or switches collectors.
| Reading it avoids a JOIN + ORDER BY on every poll tick. Always
| resolvable back to a live `market_hub_collectors` row while the hub
| is being polled; NULL while the hub is dormant.
|
| See docs/adr/0005-private-market-hub-overlay.md.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_hubs', function (Blueprint $table) {
            $table->id();

            // Mirrors market_watched_locations.location_type — same two
            // ENUM values so callers iterating either table don't have
            // to translate.
            $table->enum('location_type', ['npc_station', 'player_structure']);

            // 64-bit for the same reason as market_watched_locations:
            // Upwell structure IDs cross the INT max.
            $table->unsignedBigInteger('location_id');

            // Always populated so region-level analytics don't have to
            // resolve the hub's system through ref_*.
            $table->unsignedInteger('region_id');

            // Resolved once at register time via ref_solar_systems
            // (NPC station) or /universe/structures/ (player structure).
            // Nullable because structure SSO returns system_id in the
            // body but NPC-station creation may not always have it hot
            // at the moment of the migration-era seed.
            $table->unsignedInteger('solar_system_id')->nullable();

            // Display name. NPC: constant. Player structure: cached
            // from /universe/structures/ and refreshed on a slow cadence
            // per ADR-0004 (weekly), never per poll tick.
            $table->string('structure_name', 200)->nullable();

            // TRUE for Jita and any other NPC hub the platform
            // considers public reference data. The access policy uses
            // this flag as an early-exit: public-reference hubs bypass
            // the donor + entitlement intersection check entirely.
            // FALSE for every donor-registered private structure.
            $table->boolean('is_public_reference')->default(false);

            // Operator / automation master switch. When false, the
            // poller skips the hub and the UI hides it even from
            // entitled viewers (admin audit view excepted).
            $table->boolean('is_active')->default(true);

            // The user who first registered this hub. Purely audit
            // metadata — does NOT confer ownership. A canonical hub
            // survives this user's churn; see ADR-0005 § Ownership.
            // NULL for platform-seeded hubs (Jita).
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Denormalised "which character is the poller currently
            // using". Maintained by the poller when it picks a primary
            // or fails over to a backup. NULL when no active collector
            // remains (hub is dormant pending operator / donor rescue).
            // This is NOT an FK — character_id is a bigint SDE
            // identifier from CCP, not an AegisCore users row.
            $table->unsignedBigInteger('active_collector_character_id')->nullable();

            // Wallclock of the most recent successful poll against
            // this hub, regardless of which collector did it. Used by
            // the UI ("last updated X ago") and by the scheduler's
            // priority probe ("which hubs are staleest first").
            $table->timestamp('last_sync_at')->nullable();

            // Wallclock of the most recent successful structure-access
            // verification. Not the same as last_sync_at — a hub can
            // be actively polled but the poller may not re-verify
            // docking rights on every tick. Populated by the ESI
            // structure resolver when it's exercised (poll tick, admin
            // audit, donor re-auth).
            $table->timestamp('last_access_verified_at')->nullable();

            // Short, human-readable reason the hub was (auto- or
            // manually-) marked inactive. NULL when is_active = true
            // or when it was deactivated without a recorded reason
            // (operator toggle without a note).
            $table->string('disabled_reason', 255)->nullable();

            $table->timestamps();

            // Dedup contract: one canonical hub per physical market.
            // A second donor attaching the same location_id hits this
            // unique and is routed through the "attach as collector"
            // path instead of insert.
            $table->unique(['location_type', 'location_id'], 'uniq_hub_location');

            // Scheduler / UI probes: "active hubs that haven't been
            // polled recently".
            $table->index(['is_active', 'last_sync_at'], 'idx_hub_active_sync');

            // Audit probe: "show all public-reference hubs", "show
            // everything a given user created".
            $table->index('is_public_reference', 'idx_hub_public_reference');
            $table->index('created_by_user_id', 'idx_hub_creator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_hubs');
    }
};
