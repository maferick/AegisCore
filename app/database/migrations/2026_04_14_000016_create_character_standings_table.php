<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| character_standings — corp / alliance standings fetched via donor tokens
|--------------------------------------------------------------------------
|
| Each row is a single standing entry: "owner X holds a standing of Y
| toward contact Z". The owner is always a corporation or alliance —
| individual character contact lists are NOT stored here. They're
| filtered out at fetch time (see StandingsFetcher) because:
|
|   1. /account/settings MUST NOT show individual-character standings
|      per the donor-facing UX rule (a donor's personal grudges shouldn't
|      leak onto a shared settings surface).
|   2. The downstream consumer (automatic battle reports for donors /
|      admins) tags participants as "friendly" / "enemy" based on
|      group-level standings only — the authoritative "who's on our
|      side" signal in EVE is alliance- or corp-set, not personal.
|
| The table is populated by {@see App\Domains\UsersCharacters\Jobs\SyncCharacterStandings}
| which reads the donor's market token, resolves the donor character's
| current corporation + alliance affiliation, and fetches:
|
|   GET /corporations/{corp_id}/contacts/   (requires esi-corporations.read_contacts.v1
|                                            AND Personnel_Manager or Contact_Manager
|                                            role — will 403 for line members)
|   GET /alliances/{alliance_id}/contacts/  (requires esi-alliances.read_contacts.v1;
|                                            any alliance member can read)
|
| Multiple donors may be in the same corp/alliance. The unique key is
| (owner_type, owner_id, contact_id) — not (source_character_id, ...).
| Whichever donor syncs last wins; the data's identity is "what the
| corp/alliance's current contact list says", not "what donor X saw".
| `source_character_id` + `synced_at` are audit metadata so an operator
| can trace provenance.
|
| Contact types (ENUM) mirror CCP's own vocabulary: 'character',
| 'corporation', 'alliance', 'faction'. The display surface on
| /account/settings filters to corporation + alliance only (per the
| donor-UX rule above); the other two are stored to keep the table a
| faithful mirror of what ESI returned, so a future surface (e.g. an
| admin-only "full contacts audit" page) doesn't need a re-sync.
|
| Battle-report contract:
|
|   - Donor / admin reports: join participants against this table via
|     (participant_corp_id, participant_alliance_id) → standing value,
|     tag friendlies (standing >= 5) / neutrals / enemies (standing <= -5).
|   - Non-donor manual reports: ignore this table entirely, show Team A
|     vs Team B only. Automatic report generation is donor-gated — no
|     donor token on the account, no auto reports.
|
| `standing` is stored as DECIMAL(4,1) — CCP's contact standings range
| is -10.0 to +10.0 in 0.1 increments. Float would be fine but decimal
| sidesteps the NaN / ±Infinity edge cases for a ranged value.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_standings', function (Blueprint $table) {
            $table->id();

            // Who holds this standing. 'corporation' or 'alliance' —
            // ENUM so a stray 'character' insert is a DB error, not a
            // silent store of individual-character contacts. The
            // sync service filters before insert, this is belt-and-
            // braces.
            $table->enum('owner_type', ['corporation', 'alliance']);

            // CCP corporation_id or alliance_id — the owner of the
            // contact list this row came from.
            $table->unsignedBigInteger('owner_id');

            // CCP entity ID the standing is toward. Interpretation
            // depends on contact_type.
            $table->unsignedBigInteger('contact_id');

            // Kind of entity the standing points at. Stored as ENUM
            // mirroring CCP's own values so future code can reason
            // about it without string-matching surprises.
            $table->enum('contact_type', ['character', 'corporation', 'alliance', 'faction']);

            // -10.0 to +10.0 in 0.1 increments. DECIMAL to avoid
            // float NaN traps on a bounded scalar.
            $table->decimal('standing', 4, 1);

            // Display name for the contact, resolved after sync via
            // the unauth'd POST /universe/names/ batch endpoint (same
            // pattern PollDonationsWallet uses for donor names). 200
            // chars matches `ref_npc_corporations.name` — the widest
            // entity-name column in the SDE mirror. Nullable because
            // the resolve pass runs after the initial upsert; a brief
            // window exists where a row has no name yet. The UI
            // falls back to "#<contact_id>" for null names.
            $table->string('contact_name', 200)->nullable();

            // Optional free-text label list CCP returns (`label_ids`).
            // Stored verbatim as JSON for audit; not used on the
            // /account/settings surface yet. Future: could let admins
            // map label names to friendly/enemy overrides.
            $table->json('label_ids')->nullable();

            // Provenance: which donor character's token fetched this
            // row on the last sync. ON DELETE SET NULL so removing a
            // character (via character-table cascade, e.g. user
            // deleted) doesn't erase the standing data itself — the
            // corp/alliance contact list is still valid, we just lose
            // the "who last pulled it" breadcrumb. Another donor in
            // the same corp/alliance will overwrite on next sync.
            $table->foreignId('source_character_id')
                ->nullable()
                ->constrained('characters')
                ->nullOnDelete();

            // Last successful sync timestamp for this row. Separate
            // from updated_at because an upsert that rewrites the same
            // standing value bumps updated_at whether or not the value
            // changed, whereas synced_at is the operational "how
            // stale is this" signal.
            $table->timestamp('synced_at')->useCurrent();

            $table->timestamps();

            // One row per (owner, contact) tuple. Re-syncing from any
            // donor is an upsert. Index name short enough for MariaDB's
            // 64-char identifier cap.
            $table->unique(['owner_type', 'owner_id', 'contact_id'], 'uniq_standing_owner_contact');

            // Primary policy probe for the battle-report path: given
            // a participant's (corp_id, alliance_id), look up standings
            // for either owner across all sync'd lists.
            $table->index(['contact_id', 'contact_type'], 'idx_standing_contact');

            // Settings-page probe: "show me the standings owned by
            // corp/alliance X" (one row per donor's corp+alliance).
            $table->index(['owner_type', 'owner_id'], 'idx_standing_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_standings');
    }
};
