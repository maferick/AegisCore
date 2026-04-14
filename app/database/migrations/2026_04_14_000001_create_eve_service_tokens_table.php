<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| eve_service_tokens — long-lived ESI tokens for background polling
|--------------------------------------------------------------------------
|
| Phase-2 work landing early. ADR-0002 § Token kinds called out two
| flavours: the user-login token (publicData scope, discarded after
| identity extraction — phase 1) and the service character token
| (elevated scopes, stored encrypted, consumed by the Python execution
| plane for sustained polling).
|
| This is the storage for the latter. Keyed on `character_id` so a re-auth
| of the same EVE character upserts the row (rotating the access/refresh
| tokens) instead of stacking duplicates. Phase-1 deployments use one
| service character per stack; the table can grow per-feature later
| without a migration.
|
| `access_token` and `refresh_token` are encrypted at rest via Laravel's
| `'encrypted'` cast on the model — APP_KEY is the encryption key. We
| store the raw scopes JSON so future code can ask "does this token grant
| esi-markets.structure_markets.v1?" without re-decoding the JWT.
|
| `expires_at` is the absolute UTC instant the access token rolls over.
| The Python poller compares against `now()` and refreshes via the
| stored refresh token (which doesn't expire until the user revokes the
| app on https://community.eveonline.com/support/third-party-applications/
| or CCP rotates app secrets).
|
| See docs/adr/0002-eve-sso-and-esi-client.md § phase-2 amendment.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eve_service_tokens', function (Blueprint $table) {
            $table->id();

            // CCP's EVE character ID for the service character — the
            // entity actually authorised against ESI when the token is
            // used. Unique so re-auth upserts.
            $table->unsignedBigInteger('character_id')->unique();

            // Current EVE name. Mutable; we refresh on every service
            // re-auth so the admin UI shows the right thing.
            $table->string('character_name', 100);

            // Raw scope list as JSON, exactly as it came back in the
            // JWT's `scp` claim. Lets readers ask "is this scope granted?"
            // without re-decoding the access token. Array of strings.
            $table->json('scopes');

            // Encrypted at rest via the model's `'encrypted'` cast.
            // TEXT (rather than VARCHAR) because a base64 JWT with the
            // app's full scope set can run >1000 chars before encryption,
            // and Laravel's encryption envelope adds ~80 chars of
            // overhead on top.
            $table->text('access_token');
            $table->text('refresh_token');

            // When the access token expires (UTC, absolute). We compute
            // this as `now() + expires_in` on token receipt; CCP's token
            // endpoint returns a relative `expires_in` in seconds.
            $table->timestamp('expires_at');

            // Tracks who hit the "authorize" button on /admin so an
            // audit-curious operator can chase down provenance. Cascade
            // null on user delete — losing the audit trail beats losing
            // the working token.
            $table->foreignId('authorized_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Common probe — "find the token expiring soonest" for the
            // refresh dashboard. Tiny table today; index keeps the same
            // shape if we ever go per-feature.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eve_service_tokens');
    }
};
