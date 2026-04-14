<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| market_hub_entitlements — viewer access to a private hub
|--------------------------------------------------------------------------
|
| Zero-or-many per hub. Each row grants a subject the right to view
| the hub's market data. Viewing is additionally gated by the feature
| entitlement (isDonor() OR admin); see ADR-0005 § Intersection rule:
|
|     can_view = has_feature_access(user) AND has_hub_access(user, hub)
|
| The `has_hub_access` half is exactly "exists a row in this table
| whose subject matches the user" — where "matches" is:
|
|   subject_type = 'user'     → subject_id = user.id
|   subject_type = 'corp'     → subject_id ∈ user.characters.corp_ids
|   subject_type = 'alliance' → subject_id ∈ user.characters.alliance_ids
|
| v1 ships with subject_type = 'user' only. The 'corp' and 'alliance'
| values are pre-wired in the ENUM so the upgrade path is a code-only
| change (resolve user → corp/alliance set, expand the match query) —
| no migration. The policy service refuses to match 'corp'/'alliance'
| entitlements until corp/alliance resolution is wired, and logs a
| warning if it encounters one in the DB.
|
| Public-reference hubs (Jita) have NO entitlement rows: the access
| policy short-circuits on `market_hubs.is_public_reference = true`
| before it even looks at this table.
|
| `granted_by_user_id` is audit metadata. On user delete we keep the
| grant but set this to NULL — the grant itself survives the granter's
| departure (admin turnover should not silently revoke access).
|
| On subject-user delete, the grant is CASCADE-deleted for
| subject_type = 'user' (the user no longer exists — the grant is
| meaningless). For subject_type = 'corp'/'alliance' the grant
| survives membership changes naturally because it keys on the
| corp/alliance ID, not on individuals. Enforced application-side on
| the user-delete path, not via the single `subject_id` column (which
| can point at a user / corp / alliance and therefore can't carry a
| single CASCADE FK).
|
| UNIQUE(hub_id, subject_type, subject_id) prevents accidental
| duplicate grants and lets the registration flow use
| INSERT ... ON DUPLICATE KEY UPDATE to upsert cleanly.
|
| See docs/adr/0005-private-market-hub-overlay.md § Entitlement matrix.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_hub_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hub_id')
                ->constrained('market_hubs')
                ->cascadeOnDelete();

            // Subject discriminator. ENUM rather than a polymorphic
            // *able_type string to keep the values bounded and
            // indexable. 'user' is the only live path in v1;
            // 'corp' / 'alliance' are pre-wired for the phase-2
            // group-sharing rollout.
            $table->enum('subject_type', ['user', 'corp', 'alliance']);

            // The subject's identifier. Interpretation follows
            // subject_type:
            //   'user'     → users.id      (AegisCore-local)
            //   'corp'     → corporation_id (CCP)
            //   'alliance' → alliance_id    (CCP)
            // Stored unsigned bigint to fit all three; users.id is a
            // much smaller number in practice, corp/alliance IDs sit
            // in the 32-bit range today but we allow headroom.
            $table->unsignedBigInteger('subject_id');

            // The user who granted this entitlement. NULL after the
            // granter is deleted — the grant itself survives (admin
            // turnover does not silently revoke access). Set by the
            // Livewire registration flow and by the Filament admin
            // "grant viewer" action.
            $table->foreignId('granted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('granted_at')->useCurrent();

            $table->timestamps();

            // One grant per (hub, subject) tuple. Re-granting is an
            // upsert no-op.
            $table->unique(['hub_id', 'subject_type', 'subject_id'], 'uniq_entitlement_hub_subject');

            // Policy probe: "which hubs can this user see" — scan by
            // (subject_type, subject_id), join to market_hubs.
            $table->index(['subject_type', 'subject_id'], 'idx_entitlement_subject');

            // Admin audit probe: "which grants did this user issue".
            $table->index('granted_by_user_id', 'idx_entitlement_granter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_hub_entitlements');
    }
};
