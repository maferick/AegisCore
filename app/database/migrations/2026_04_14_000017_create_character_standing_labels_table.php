<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| character_standing_labels — contact-list labels per owner
|--------------------------------------------------------------------------
|
| Paired with `character_standings` (see
| 2026_04_14_000016_create_character_standings_table.php). Each row
| here is one entry from CCP's per-owner `/contacts/labels` endpoints:
|
|   GET /corporations/{id}/contacts/labels
|   GET /alliances/{id}/contacts/labels
|   GET /characters/{id}/contacts/labels
|
| Response shape (stable across the above three endpoints):
|
|   [ { "label_id": 123, "label_name": "Blue" }, ... ]
|
| `character_standings.label_ids` is a JSON array of `label_id` values
| — the join from a standing row back to human-readable names goes
| through this table keyed by (owner_type, owner_id, label_id).
|
| Why separate from the standings table rather than denormalising
| label_name onto each standing row:
|
|   - The same label typically appears on many contacts (a corp marks
|     20 allied corps "Blue"). Denormalising duplicates the name 20×
|     and forces a rewrite on every rename.
|   - Labels are managed independently from contacts in-game (rename
|     a label and every contact keeps its membership). The DB shape
|     should mirror that.
|   - The UI probe is bulk: render the /account/settings table by
|     loading labels per (owner_type, owner_id) once and hydrating
|     all rows in a single collection lookup, not N independent name
|     resolutions.
|
| Pruning: on each sync, rows for an owner whose label_id wasn't in
| the latest fetch are deleted — same pattern the standings fetcher
| uses, so orphaned rename-victims don't accumulate.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_standing_labels', function (Blueprint $table) {
            $table->id();

            // Matches character_standings.owner_type. Kept as a
            // parallel ENUM rather than a FK because the (owner_type,
            // owner_id) composite references a CCP ID, not a local
            // row — there's no natural FK target.
            $table->enum('owner_type', ['corporation', 'alliance', 'character']);

            // CCP owner ID — corp, alliance, or character, matching
            // character_standings.owner_id.
            $table->unsignedBigInteger('owner_id');

            // CCP label ID. Unique scoped to (owner_type, owner_id):
            // label IDs are only distinct within one owner's list,
            // not globally across CCP.
            $table->unsignedBigInteger('label_id');

            // Display name. 100 chars is comfortably above CCP's
            // in-game 40-char label cap.
            $table->string('label_name', 100);

            // Last successful sync timestamp — used to prune rows
            // that fell out of the latest fetch.
            $table->timestamp('synced_at')->useCurrent();

            $table->timestamps();

            // One row per (owner, label_id). Re-sync is an upsert.
            $table->unique(['owner_type', 'owner_id', 'label_id'], 'uniq_standing_label_owner');

            // Lookup probe: "give me all labels for this owner so I
            // can hydrate the standings table in the Livewire view".
            $table->index(['owner_type', 'owner_id'], 'idx_standing_label_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_standing_labels');
    }
};
