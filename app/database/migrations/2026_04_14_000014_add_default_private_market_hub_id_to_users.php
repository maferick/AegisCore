<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| users.default_private_market_hub_id — user-preferred comparison hub
|--------------------------------------------------------------------------
|
| User-scoped preference: "when I open a market page, which private
| hub should the comparison panel default to?" Optional — free users
| never set it, donors may or may not set it, a donor with exactly
| one hub entitlement sees that hub picked by inference without
| setting this field explicitly (computed in the controller; this
| field is only consulted when the user has chosen to pin one).
|
| Null-safe:
|
|   - Default NULL (every existing user gets NULL on backfill).
|   - ON DELETE SET NULL on the target hub: if the hub is deleted,
|     the user's preference clears to "no default" rather than the
|     row disappearing.
|   - The access policy still enforces the intersection rule at read
|     time — setting this field does NOT grant visibility. A user
|     whose default_private_market_hub_id points at a hub they no
|     longer have an entitlement for sees the default silently
|     demoted to "no default" in the UI, not an error.
|
| See docs/adr/0005-private-market-hub-overlay.md § User preference.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_private_market_hub_id')
                ->nullable()
                ->after('remember_token')
                ->constrained('market_hubs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_private_market_hub_id');
        });
    }
};
