<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| eve_donations_tokens — wallet-read token for the donations character
|--------------------------------------------------------------------------
|
| Phase-2 work landing alongside the donations poller. ADR-0002 §
| phase-2 amendment carves three flavours of EVE token storage:
|
|   1. Login token   — discarded after identity extraction (phase 1).
|   2. Service token — eve_service_tokens, broad ESI scope set, consumed
|                      by Python execution-plane pollers.
|   3. Donations     — THIS table. Single-character, single-scope
|                      (esi-wallet.read_character_wallet.v1), polled by
|                      a Laravel scheduled job for ISK donation events.
|
| The schema is intentionally separate from eve_service_tokens (rather
| than a shared eve_tokens table with a `kind` column) so the boundary
| is enforced at the schema level: the donations poller queries this
| table by name, the service-character flow upserts that one. A buggy
| caller cannot reach for the wrong row even with a SQL typo.
|
| Singleton: phase 1 supports one donations character per stack — the
| `unique` on character_id catches accidental double-rows, and the
| controller upserts on re-auth. Multi-character donations would
| require a UI selection, which we don't have a use case for yet.
|
| `access_token` + `refresh_token` ride Laravel's `'encrypted'` cast
| on the model — APP_KEY is the encryption key. The donations poller
| auto-refreshes via the stored refresh token (this is the first
| sustained-polling caller on the Laravel plane; ADR-0002 § phase-2
| anticipated this would precede the Python plane build-out).
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eve_donations_tokens', function (Blueprint $table) {
            $table->id();

            // The donations character's CCP ID. Unique so re-auth
            // upserts the row in place; the controller also enforces
            // that this matches `EVE_SSO_DONATIONS_CHARACTER_ID` from
            // env so a wrong-character authorisation never lands here.
            $table->unsignedBigInteger('character_id')->unique();

            // Current EVE name — refreshed on re-auth from the JWT.
            // String length matches eve_service_tokens for consistency.
            $table->string('character_name', 100);

            // Granted scopes from the JWT `scp` claim, normalised to
            // string[]. For a typical donations setup this is the
            // single value `esi-wallet.read_character_wallet.v1`,
            // but the polling code asks `hasScope()` rather than
            // assuming the shape — keeps multi-scope upgrades
            // (e.g. transactions in addition to journal) cheap.
            $table->json('scopes');

            // Encrypted at rest via the model's `'encrypted'` cast.
            // TEXT (not VARCHAR) for the same reasons as
            // eve_service_tokens — base64 JWT + encryption envelope
            // overflows a typical VARCHAR limit.
            $table->text('access_token');
            $table->text('refresh_token');

            // Absolute UTC instant the access token rolls over.
            // Computed as `now() + expires_in` on every refresh.
            $table->timestamp('expires_at');

            // Audit trail — the admin who clicked Authorise. Cascade
            // null on user delete so a removed admin doesn't take the
            // working donations token with them.
            $table->foreignId('authorized_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eve_donations_tokens');
    }
};
