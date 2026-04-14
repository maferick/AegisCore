<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| market_hub_collectors — tokens authorised to poll a given hub
|--------------------------------------------------------------------------
|
| Zero-or-many per hub. A collector is a (hub, user, character, token)
| tuple: the user the access is attributed to for accounting, the
| character whose ESI ACLs let the token read the structure market,
| and the eve_market_tokens row the poller actually refreshes and
| sends to ESI.
|
| ADR-0005 motivates the split from hub itself:
|
|   - Multiple donors can seed the same hub. First one through the
|     registration flow becomes the primary collector; subsequent
|     donors attaching the same structure are appended as additional
|     collectors and the UX routes them through "attach viewer" rather
|     than "register hub".
|   - Token loss or docking-right revocation is an operational event,
|     not a hub-deletion event. The poller fails over to another
|     active collector; only when zero active collectors remain does
|     the hub freeze.
|   - Donor churn is recoverable. A canonical hub with a dead primary
|     collector + a fresh donor re-auth = live hub again, without the
|     viewers noticing.
|
| `is_primary` marks the preferred collector to try first. Exactly one
| row per hub may carry is_primary = true at any given moment — not
| enforced by a DB constraint (a partial unique index would work, but
| MySQL's partial-index support is awkward), enforced instead by the
| promotion service that flips the bit inside a transaction.
|
| `is_active` is per-collector. The poller disables a collector
| (without deleting the row) when its token returns a structure-ACL
| failure, so the row remains visible in admin audit. A donor re-auth
| reactivates it.
|
| Failure bookkeeping lives on this row, NOT on market_hubs, because
| it's about the specific token — a hub-wide retry count would lie
| when the poller is cycling between a dead collector and a live one.
|
| UNIQUE(hub_id, character_id): the same character authorising twice
| against the same hub upserts rather than forks. The (user_id,
| character_id) pair is sourced from eve_market_tokens where character
| is already UNIQUE globally, so practically a character can only ever
| belong to one user at a time.
|
| token_id points at eve_market_tokens.id. ON DELETE CASCADE there
| because a token deletion means the collector can no longer do its
| job — the row is deadweight. On the hub side, CASCADE the collector
| if the hub is deleted.
|
| See docs/adr/0005-private-market-hub-overlay.md § Failover.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_hub_collectors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hub_id')
                ->constrained('market_hubs')
                ->cascadeOnDelete();

            // The AegisCore user whose donation window underwrites
            // this collector. Note: this is accounting + audit — it
            // does NOT confer viewer access. Viewer access goes
            // through market_hub_entitlements (the user typically
            // gets a matching entitlement row auto-created at
            // register time, but the two can diverge over time).
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The EVE character whose ACLs the token relies on. Not
            // an FK because character rows are authoritative in the
            // SDE sense, not the users sense.
            $table->unsignedBigInteger('character_id');

            // Points at eve_market_tokens.id. CASCADE because a
            // token delete means this collector has no way to
            // authenticate any more. The donor can re-auth and a
            // new collector row will be created via the registration
            // flow.
            $table->foreignId('token_id')
                ->constrained('eve_market_tokens')
                ->cascadeOnDelete();

            // Primary-picker flag. At most one row per hub_id should
            // carry this = true; enforced by the promotion service,
            // not the DB. Poller tries the primary first, falls over
            // to any other active collector on failure.
            $table->boolean('is_primary')->default(false);

            // Per-collector kill switch. Flipped off automatically
            // by the poller on a structure-ACL failure; flipped on
            // by a successful re-auth.
            $table->boolean('is_active')->default(true);

            // Wallclock of the most recent successful
            // /universe/structures/ access check against this
            // collector's token. Used to pre-empt a poll against a
            // collector that the verifier has recently shown to be
            // dead. Refreshed on a slow cadence; bearer of the
            // "still has docking rights" signal.
            $table->timestamp('last_verified_at')->nullable();

            // Wallclock of the most recent successful poll using
            // this collector's token. Distinct from last_verified_at
            // — a collector can have been verified without having
            // been the picked primary in the most recent poll tick.
            $table->timestamp('last_success_at')->nullable();

            // Wallclock + short reason for the most recent failure
            // using this collector. "revoked_access",
            // "token_refresh_failed", "scope_missing", "5xx_timeout".
            // A single success clears last_failure_at via the poller
            // (it writes NULL), matching the existing
            // market_watched_locations.consecutive_failure_count
            // reset discipline.
            $table->timestamp('last_failure_at')->nullable();
            $table->string('failure_reason', 255)->nullable();

            // Consecutive failure counter mirroring the existing
            // watched-locations discipline: auto-deactivate at 3
            // consecutive 403s or 5 consecutive 5xx/timeout. One
            // success resets to 0.
            $table->unsignedInteger('consecutive_failure_count')->default(0);

            $table->timestamps();

            // Same character authorising the same hub twice upserts.
            $table->unique(['hub_id', 'character_id'], 'uniq_collector_hub_character');

            // Poller probe: "active collectors for this hub, primary
            // first" — ORDER BY is_primary DESC, last_failure_at.
            $table->index(['hub_id', 'is_active', 'is_primary'], 'idx_collector_hub_active_primary');

            // Admin probe: "which collectors are failing right now".
            $table->index('last_failure_at', 'idx_collector_last_failure');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_hub_collectors');
    }
};
