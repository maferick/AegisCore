<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| market_watched_locations — the poller's driver table
|--------------------------------------------------------------------------
|
| One row per (owner, location) pair the market poller should pull. Two
| flavours of owner:
|
|   - `owner_user_id = NULL`  → platform default (admin-managed from
|                                /admin/market-watched-locations). Jita
|                                seeds here and is not removable by
|                                policy; other NPC hubs + admin-reachable
|                                structures are operator-added.
|   - `owner_user_id = <id>`  → donor-owned (managed from
|                                /account/settings). Only exists when
|                                the donor has authorised their own
|                                eve_market_tokens row, and the picker
|                                that produced the location_id only
|                                surfaces IDs the donor's own token can
|                                resolve via /characters/{id}/search/.
|
| `location_type` + explicit `region_id` keep the record model clean:
| the poller derives "region endpoint + location filter" (NPC) vs
| "structure endpoint" (player) from `location_type`, and `region_id` is
| always populated so a future region-wide analytics query doesn't have
| to join back through ref_* tables.
|
| Failure discipline (per ADR-0004 § Failure handling):
|
|   - Routine failures (403 "no access", 5xx, timeout) increment
|     `consecutive_failure_count` and record `last_error` /
|     `last_error_at`. Auto-disable after 3 consecutive 403s or 5
|     consecutive 5xx/timeouts; a single success resets the counter.
|   - Security-boundary failures (token ↔ location ownership mismatch,
|     required scope missing) set `enabled = false` immediately with
|     `disabled_reason` — no grace.
|
| Uniqueness is `(owner_user_id, location_id)`. MySQL treats NULL as
| distinct in unique keys, which means two admins could theoretically
| both add the same NPC hub with `owner_user_id = NULL`. That's an
| operator-error class, not a concurrency hazard — the admin UI
| surfaces existing rows before the "add" button, so duplicates require
| someone to actively ignore the UI. Not worth a trigger to enforce.
|
| See docs/adr/0004-market-data-ingest.md.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_watched_locations', function (Blueprint $table) {
            $table->id();

            // npc_station → region endpoint + location_id filter
            // player_structure → structure endpoint (auth'd)
            // Stored as an enum so the poller can branch on it without
            // inferring intent from ID ranges.
            $table->enum('location_type', ['npc_station', 'player_structure']);

            // Always populated. For NPC stations we resolve it via
            // ref_npc_stations → ref_solar_systems at insert time; for
            // player structures we resolve it via /universe/structures/
            // at insert time (using the authorising character's token).
            $table->unsignedInteger('region_id');

            // 64-bit because Upwell structure IDs cross INT max. NPC
            // station IDs fit in 32 bits but we use one column for both
            // so the poller loop doesn't have to branch on width.
            $table->unsignedBigInteger('location_id');

            // Cached display name from the last resolution. Refreshed
            // on a slow cadence (see ADR-0004 — weekly), never per poll
            // tick, so players renaming structures between refreshes
            // don't trip the ESI rate limiter.
            $table->string('name', 200)->nullable();

            // NULL = platform default (admin-managed, one of Jita's row
            // siblings). Non-NULL = donor-owned, bound to that donor's
            // eve_market_tokens row. The poller asserts
            // `token.user_id == owner_user_id` before every ESI call
            // against a non-NULL row — enforcing trust at read/use
            // rather than just at callback time.
            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            // Master switch. The poller skips rows where this is false;
            // failure-based auto-disables flip it to false and populate
            // disabled_reason. Operators can also flip it manually
            // from the admin UI (e.g. maintenance).
            $table->boolean('enabled')->default(true);

            // Wallclock of the most recent successful poll. Used by the
            // scheduler to priority-pick stale rows and by the UI to
            // show "last updated X ago".
            $table->timestamp('last_polled_at')->nullable();

            // Consecutive failure counter — resets to 0 on any success.
            // Thresholds (3 for 403, 5 for 5xx/timeout) live in the
            // poller config, not here, so operators can tune without a
            // migration.
            $table->unsignedInteger('consecutive_failure_count')->default(0);

            // Free-text for the operator UI. Captures the most recent
            // error (HTTP status code + message excerpt). Not the full
            // error stream — that lives in structured logs.
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();

            // Set alongside `enabled = false` when auto-disable trips.
            // Values are short, human-readable: "revoked_access",
            // "token_ownership_mismatch", "scope_missing", etc. NULL
            // when the row was disabled manually by an operator
            // (the audit log carries "who + when" in that case).
            $table->string('disabled_reason', 255)->nullable();

            $table->timestamps();

            // Per-owner uniqueness. See header on NULL-distinct caveat.
            $table->unique(['owner_user_id', 'location_id'], 'uniq_watched_owner_location');

            // Scheduler's primary probe — "what do I poll next?"
            $table->index(['enabled', 'last_polled_at'], 'idx_watched_enabled_polled');

            // UI probes: "show me this donor's rows", "show platform
            // defaults only". Separated by location_type so the admin
            // UI's "add structure" vs "add NPC station" flows query
            // narrowly.
            $table->index('owner_user_id', 'idx_watched_owner');
            $table->index('location_type', 'idx_watched_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_watched_locations');
    }
};
