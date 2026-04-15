<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| coalition_blocs — normalised bloc registry for donor classification
|--------------------------------------------------------------------------
|
| First of the classification-system tables (Phase 0 of the donor-facing
| affiliation intelligence layer). Stores the coalition "blocs" we map
| alliances and corporations into: WinterCo, B2, PanFam, and so on.
|
| Why this is a table and not an enum or hard-coded list:
|
|   - Coalition structure in EVE shifts on a multi-month cadence. Adding
|     a new bloc should be a row insert and a label import, not a
|     migration.
|   - The resolver (see the viewer_entity_classifications migration) needs
|     to FK labels and consensus back to a stable bloc id. That only
|     works if blocs live in a table.
|   - Admins need to deactivate dead blocs without losing the historical
|     labels that pointed at them (is_active flag, no hard deletes).
|
| Intentionally lightweight — the semantically rich "what does this bloc
| stand for" goes into coalition_relationship_types + the resolver. This
| table is just the stable identity layer.
|
| Seeded with: wc (WinterCo), b2, cfc, panfam, independent, unknown.
| Seeder lives alongside this migration (coalition seeders, phase 0).
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coalition_blocs', function (Blueprint $table) {
            $table->id();

            // Short stable code the rest of the system references. Lower-
            // case ASCII, no separators — used as the prefix in label
            // strings like "wc.member", "b2.affiliate". 32 chars is
            // generous; actual codes stay under 10.
            $table->string('bloc_code', 32)->unique();

            // Human-readable name for admin UI and donor-facing copy.
            // 100 chars matches the label-column width used elsewhere
            // in the app (character_standing_labels.label_name is 100).
            $table->string('display_name', 100);

            // Default operational role bias for entities that inherit
            // from this bloc without a more specific relationship type.
            // Kept as ENUM mirroring the same vocabulary as
            // coalition_relationship_types.default_role so a migration
            // between the two stays lossless.
            $table->enum('default_role', ['combat', 'support', 'logistics', 'renter'])
                ->default('combat');

            // Soft-disable flag. Inactive blocs keep their rows and
            // historical labels intact; the resolver skips them when
            // inferring viewer bloc or when emitting new suggestions.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Lookup probe: "give me the active blocs" for the admin
            // bloc picker and the resolver's viewer-bloc inference.
            $table->index(['is_active', 'bloc_code'], 'idx_coalition_blocs_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coalition_blocs');
    }
};
