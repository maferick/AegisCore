<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Backfill market_hubs + collectors + entitlements from the existing
| market_watched_locations rows
|--------------------------------------------------------------------------
|
| ADR-0005 § Transition: market_watched_locations pre-dates the
| canonical-hub model. This migration lifts every existing watched
| row into the new three-table shape so the policy layer has a clean
| starting state:
|
|   A. Every market_watched_locations row gets a corresponding
|      market_hubs row (keyed by (location_type, location_id)). The
|      watched row's hub_id FK is populated to point at it.
|
|   B. Platform-owned (owner_user_id IS NULL) rows become public
|      reference hubs — is_public_reference = true, no collectors,
|      no entitlements. Jita is the canonical example; any other NPC
|      station admins seeded pre-ADR-0005 lands here too.
|
|   C. Donor-owned rows become private hubs — is_public_reference =
|      false, with the donor's existing eve_market_tokens row wired
|      up as the primary collector, AND a self-entitlement granting
|      the donor the right to view their own hub. This matches the
|      product behaviour ADR-0005 spells out for the registration
|      flow.
|
| Idempotency: keyed by (location_type, location_id) / (hub_id,
| character_id) / (hub_id, subject_type, subject_id). Re-running
| after a partial failure (or a manual DB surgery) picks up where
| it left off without duplicating rows.
|
| Wrapped in a single transaction so a failure halfway through
| either applies nothing or the full set — there is no partial state
| where a hub exists but its watched row's hub_id is still NULL.
|
| See docs/adr/0005-private-market-hub-overlay.md § Transition.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $watchedRows = DB::table('market_watched_locations')
                ->whereNull('hub_id')
                ->get();

            foreach ($watchedRows as $watched) {
                $isPlatform = $watched->owner_user_id === null;

                // A. Canonical hub — upsert by (location_type, location_id).
                $hubId = DB::table('market_hubs')
                    ->where('location_type', $watched->location_type)
                    ->where('location_id', $watched->location_id)
                    ->value('id');

                if ($hubId === null) {
                    $hubId = DB::table('market_hubs')->insertGetId([
                        'location_type' => $watched->location_type,
                        'location_id' => $watched->location_id,
                        'region_id' => $watched->region_id,
                        'solar_system_id' => null,
                        'structure_name' => $watched->name,
                        'is_public_reference' => $isPlatform,
                        'is_active' => $watched->enabled,
                        'created_by_user_id' => $watched->owner_user_id,
                        'active_collector_character_id' => null,
                        'last_sync_at' => $watched->last_polled_at,
                        'last_access_verified_at' => null,
                        'disabled_reason' => $watched->disabled_reason,
                        'created_at' => $watched->created_at ?? now(),
                        'updated_at' => $watched->updated_at ?? now(),
                    ]);
                }

                // Point the watched row at its hub.
                DB::table('market_watched_locations')
                    ->where('id', $watched->id)
                    ->update(['hub_id' => $hubId, 'updated_at' => now()]);

                if ($isPlatform) {
                    // B. Public reference: no collectors, no entitlements.
                    continue;
                }

                // C. Donor-owned: attach collector + self-entitlement.
                //
                // If there's no eve_market_tokens row yet for this
                // owner (the donor has added the row but not
                // completed the market-scopes SSO), skip the
                // collector wiring — the registration flow will
                // create it when the donor re-auths. We still
                // create the self-entitlement so the donor can see
                // their hub as soon as a collector is attached.
                $token = DB::table('eve_market_tokens')
                    ->where('user_id', $watched->owner_user_id)
                    ->orderBy('id')
                    ->first();

                if ($token !== null) {
                    $existingCollectorId = DB::table('market_hub_collectors')
                        ->where('hub_id', $hubId)
                        ->where('character_id', $token->character_id)
                        ->value('id');

                    if ($existingCollectorId === null) {
                        DB::table('market_hub_collectors')->insert([
                            'hub_id' => $hubId,
                            'user_id' => $watched->owner_user_id,
                            'character_id' => $token->character_id,
                            'token_id' => $token->id,
                            'is_primary' => true,
                            'is_active' => $watched->enabled,
                            'last_verified_at' => null,
                            'last_success_at' => $watched->last_polled_at,
                            'last_failure_at' => $watched->last_error_at,
                            'failure_reason' => $watched->disabled_reason,
                            'consecutive_failure_count' => $watched->consecutive_failure_count ?? 0,
                            'created_at' => $watched->created_at ?? now(),
                            'updated_at' => now(),
                        ]);

                        DB::table('market_hubs')
                            ->where('id', $hubId)
                            ->update([
                                'active_collector_character_id' => $token->character_id,
                                'updated_at' => now(),
                            ]);
                    }
                }

                $existingEntitlementId = DB::table('market_hub_entitlements')
                    ->where('hub_id', $hubId)
                    ->where('subject_type', 'user')
                    ->where('subject_id', $watched->owner_user_id)
                    ->value('id');

                if ($existingEntitlementId === null) {
                    DB::table('market_hub_entitlements')->insert([
                        'hub_id' => $hubId,
                        'subject_type' => 'user',
                        'subject_id' => $watched->owner_user_id,
                        'granted_by_user_id' => $watched->owner_user_id,
                        'granted_at' => $watched->created_at ?? now(),
                        'created_at' => $watched->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Reversing the backfill is destructive — collectors and
        // entitlements only live in the new tables, and dropping them
        // would lose the donor grants. The three tables' own down()s
        // drop the data wholesale if a full rollback is really needed;
        // this migration's down() only un-points the watched rows so
        // they can be re-backfilled on the next up().
        DB::table('market_watched_locations')
            ->whereNotNull('hub_id')
            ->update(['hub_id' => null]);
    }
};
