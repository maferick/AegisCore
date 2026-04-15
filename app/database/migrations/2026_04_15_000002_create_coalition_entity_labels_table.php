<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| coalition_entity_labels — normalised coalition tags on corps / alliances
|--------------------------------------------------------------------------
|
| One row per (entity, raw label, source) triple. The raw label is the
| string a human or import actually produced (`wc.member`); the parsed
| bloc_id / relationship_type_id columns are the normalised decomposition
| the resolver consumes.
|
| Named `coalition_entity_labels` (not just `entity_labels`) to avoid
| confusion with `character_standing_labels`, which stores CCP-provided
| ESI contact-list labels (e.g. "Blue", "Allies"). That table is the
| mirror of what a corp/alliance has tagged internally in-game. This
| table is the platform's own coalition taxonomy.
|
| Multiple labels per entity are supported by design. An alliance can
| legitimately carry `wc.member` and `wc.logistics` simultaneously (it
| is a member AND it runs logistics for the bloc). Order-of-display is
| handled via coalition_relationship_types.display_order, not here.
|
| `source` tracks provenance: manual admin tag, bulk import, seed data.
| Part of the uniqueness key deliberately — the same raw_label from two
| sources is legal and informative (agreement strengthens confidence in
| the resolver).
|
| No FK on entity_id. Corporation and alliance IDs are bare CCP bigints
| in this codebase — there are no player-side ref_corporations /
| ref_alliances tables yet (deferred to a later phase). The (entity_type,
| entity_id) pair is the join key; consumers of this table are responsible
| for resolving names via the /universe/names/ batch pattern already used
| in character_standings.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coalition_entity_labels', function (Blueprint $table) {
            $table->id();

            // What kind of entity this label is attached to. ENUM rather
            // than a free-form string so a typo is a DB error, not a
            // silent missed lookup. Matches the vocabulary used in
            // character_standings.contact_type (minus 'character' and
            // 'faction' — coalition labels don't apply to individuals or
            // NPC factions).
            $table->enum('entity_type', ['corporation', 'alliance']);

            // CCP corporation_id or alliance_id. UNSIGNED BIGINT for
            // consistency with the characters table and every other
            // CCP-id column in the app.
            $table->unsignedBigInteger('entity_id');

            // The original label string as produced by the human or
            // importer. 100 chars matches character_standing_labels.
            // Kept verbatim so operators can audit what was entered
            // even if the parser later changes how it decomposes.
            $table->string('raw_label', 100);

            // Parsed bloc. Nullable: a raw_label that doesn't match any
            // known bloc still gets stored (operator can fix the bloc
            // registry or correct the label), but the resolver treats
            // null-bloc labels as unresolved evidence.
            $table->foreignId('bloc_id')
                ->nullable()
                ->constrained('coalition_blocs')
                ->nullOnDelete();

            // Parsed relationship type. Nullable for the same reason
            // as bloc_id above.
            $table->foreignId('relationship_type_id')
                ->nullable()
                ->constrained('coalition_relationship_types')
                ->nullOnDelete();

            // Where this label came from. VARCHAR rather than ENUM so
            // new import sources (e.g. `zkill_import`, `donor_crowd`)
            // slot in without a schema change. Kept short — current
            // values: 'manual', 'import', 'seed'.
            $table->string('source', 32)->default('manual');

            // Soft-disable flag. Removing a label (mis-tagged, obsolete,
            // bloc dissolved) sets this false rather than deleting the
            // row, preserving audit history and keeping the unique key
            // stable across toggles.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // (entity_type, entity_id, raw_label, source) is the unique
            // key — `source` is deliberately part of it so the same
            // label coming from both a manual tag and an import can
            // co-exist (agreement is a signal). Name shortened to fit
            // MariaDB's 64-char identifier cap.
            $table->unique(
                ['entity_type', 'entity_id', 'raw_label', 'source'],
                'uniq_coalition_labels_entity_raw_src'
            );

            // Primary resolver probe: "what coalition labels are on
            // this entity, right now, active ones only".
            $table->index(
                ['entity_type', 'entity_id', 'is_active'],
                'idx_coalition_labels_entity_active'
            );

            // Reverse probe for admin screens: "show me every entity
            // tagged into this bloc with this relationship".
            $table->index(
                ['bloc_id', 'relationship_type_id', 'is_active'],
                'idx_coalition_labels_bloc_rel'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coalition_entity_labels');
    }
};
