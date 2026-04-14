<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| eve_market_tokens — donor-authorised structure-market tokens
|--------------------------------------------------------------------------
|
| Fourth flavour of EVE token storage, completing the set ADR-0002 §
| phase-2 amendment started:
|
|   1. Login token       — discarded after identity extraction.
|   2. Service token     — eve_service_tokens, admin-authorised, broad
|                          scope set for platform-default polling.
|   3. Donations token   — eve_donations_tokens, single-character,
|                          wallet-read scope only.
|   4. Market token      — THIS table, donor-authorised, scope set
|                          `publicData esi-search.search_structures.v1
|                          esi-universe.read_structures.v1
|                          esi-markets.structure_markets.v1`.
|                          One row per donor character.
|
| Why a dedicated table rather than a shared eve_tokens with a `kind`
| column: same reason ADR-0002 gave for splitting donations from
| service — schema-level boundaries catch SQL-typo-class bugs that a
| WHERE clause cannot. The market poller queries this table by name;
| the donations poller cannot accidentally reach for a market token
| via the wrong predicate.
|
| Ownership binding (ADR-0004 § Token ownership enforced at read/use):
|
|   - `user_id`     — the AegisCore user who authorised this token.
|                     ON DELETE CASCADE: if the user is deleted, the
|                     token goes with them. Enforced at the DB level
|                     because the security-sensitive invariant
|                     "every token traces to a live user" has to be
|                     true even if an application code path forgets.
|   - `character_id` — the EVE character whose ACLs this token
|                     embodies. UNIQUE so re-auth upserts in place
|                     (rotating access/refresh).
|
| Before every ESI call, the poller asserts
| `token.user_id == market_watched_location.owner_user_id` AND
| `token.character_id ∈ user.characters`. Mismatch is a security
| violation: immediate row disable, not routine error handling.
|
| `access_token` and `refresh_token` ride Laravel's `'encrypted'` cast
| on the model. APP_KEY is the encryption key; a SELECT * leak is
| ciphertext, not bearer tokens.
|
| Refresh story: each donor's token refreshes independently. Phase-1
| single-scheduler assumption means no distributed lock is needed yet
| (same as donations — ADR-0002 § phase-2 amendment). When we scale
| past one scheduler instance, add a row-level advisory lock on this
| table's PK before the refresh call. Noted in ADR-0004 § Follow-ups.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eve_market_tokens', function (Blueprint $table) {
            $table->id();

            // The AegisCore user who owns this token. CASCADE ON DELETE
            // so the security invariant "every market token traces to
            // a live user" is enforced at the DB level, not relying on
            // an app-level cleanup that might drift.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // CCP's permanent character ID. UNIQUE so re-auth of the
            // same character upserts rather than stacking. The SSO
            // callback validates that the authorising user's linked
            // characters include this one before upserting — a user
            // can only register a market token for a character they
            // themselves have SSO-authenticated as.
            $table->unsignedBigInteger('character_id')->unique();

            // Current EVE name — refreshed on each re-auth from the
            // JWT. Same 100-char length as the other token tables for
            // consistency.
            $table->string('character_name', 100);

            // Scope list from the JWT `scp` claim, string[]. Expected
            // set for this flow is
            //   publicData
            //   esi-search.search_structures.v1
            //   esi-universe.read_structures.v1
            //   esi-markets.structure_markets.v1
            // but we store what we got rather than asserting — scope
            // drift (CCP adding/renaming) is handled by a predicate
            // check at use time, not at insert time.
            $table->json('scopes');

            // Encrypted at rest via the model's `'encrypted'` cast.
            // TEXT (not VARCHAR) for the same base64-JWT + envelope
            // reasons as the other token tables.
            $table->text('access_token');
            $table->text('refresh_token');

            // Absolute UTC instant the access token rolls over.
            // Computed as `now() + expires_in` on every refresh.
            $table->timestamp('expires_at');

            $table->timestamps();

            // Common probes:
            //   - "which tokens need refreshing soonest" (scheduler)
            //   - "does this user have a market token" (UI predicate
            //     — backed by the FK index on user_id, which the
            //     foreignId()->constrained() call above already adds)
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eve_market_tokens');
    }
};
