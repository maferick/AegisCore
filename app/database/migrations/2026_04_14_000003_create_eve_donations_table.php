<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| eve_donations — wallet-journal-derived donation events
|--------------------------------------------------------------------------
|
| Captures `ref_type === 'player_donation'` rows from the donations
| character's wallet journal. Each row corresponds to one in-game ISK
| transfer from a supporter to our donations character.
|
| Why a dedicated table (rather than e.g. a polymorphic
| `wallet_journal_entries` table)?
|
|   - Donations are the only journal ref_type we currently care about.
|     Storing only the rows we use keeps the table small (donor count is
|     in the dozens, not millions) and the schema obvious.
|   - Add a polymorphic table later if/when other ref_types matter
|     (corp wallets, market sales, etc.) — until then, a bespoke table
|     beats premature abstraction.
|
| `journal_ref_id` is CCP's primary key for the journal entry — UNIQUE
| so the 5-minute poller can `upsert(['journal_ref_id'])` and re-insert
| the same page without duplicating rows. CCP's IDs are monotonically
| increasing per-character, so a checkpoint-style "latest seen" pointer
| is also possible later as an optimisation.
|
| No FK from donor_character_id → characters.character_id by design:
|   - Donors don't need an AegisCore account to donate. The donation is
|     recorded the moment ISK arrives in-game.
|   - When (or if) the donor later logs in via SSO, the existing flow
|     creates the characters row keyed on the same character_id, and
|     `User::isDonor()` starts returning true automatically — no
|     migration needed. See User model PHPDoc.
|
| See ADR-0002 § phase-2 amendment.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eve_donations', function (Blueprint $table) {
            $table->id();

            // CCP's journal entry ID — the primary key of the row in
            // /characters/{id}/wallet/journal. Unique so re-polling the
            // same page is idempotent (insertOrIgnore / upsert).
            $table->unsignedBigInteger('journal_ref_id')->unique();

            // Donor's EVE character ID (journal `first_party_id` on a
            // player_donation row). The character may or may not have
            // an AegisCore account; User::isDonor() joins via this
            // column when they log in.
            $table->unsignedBigInteger('donor_character_id');

            // Resolved name from /universe/names/. Nullable for the
            // brief window between insert + the next name-resolve pass
            // (the poller resolves fresh ones at the end of each run).
            // Mutable: characters can rename in EVE, the `latest seen`
            // value here is "good enough" — the donation amount/date
            // is the durable fact.
            $table->string('donor_name', 100)->nullable();

            // ISK amount donated. CCP returns this as a JSON `float`
            // with up to 2dp precision. Storing as DECIMAL(20, 2)
            // gives us 18 digits of integer ISK headroom (nine
            // quadrillion ISK — well over CCP's economy-wide total)
            // and exact-decimal arithmetic in MySQL.
            $table->decimal('amount', 20, 2);

            // Free-text reason the donor types in the in-game send
            // money dialog. CCP delivers this in the journal `reason`
            // field (sometimes called `description` in older API
            // versions). Capped at 500 — CCP's UI limit is 100 chars
            // but a generous server-side cap keeps us future-proof.
            $table->string('reason', 500)->nullable();

            // CCP's timestamp for the donation. Stored separately from
            // created_at because created_at is "when our poller saw
            // it" and donated_at is "when it actually happened" —
            // those drift by up to one poll interval.
            $table->timestamp('donated_at');

            $table->timestamps();

            // Common probes:
            //   1. "did character X donate?" → User::isDonor() lookup.
            //   2. "list recent donations" (admin page) → ORDER BY
            //      donated_at DESC.
            $table->index('donor_character_id');
            $table->index('donated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eve_donations');
    }
};
