<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| entity_classification_overrides — manual overrides on resolver output
|--------------------------------------------------------------------------
|
| Forced classifications that outrank the resolver's deterministic chain
| for a specific entity. Two scopes are supported on day one:
|
|   - `global` — an admin correction that applies to every viewer.
|                Used sparingly, typically for fixing broken data or
|                pinning a well-known "everyone's enemy" / "everyone's
|                ally" tag.
|   - `viewer` — a per-viewer override owned by that donor. Always wins
|                over global for that viewer, but ranks BELOW the
|                viewer's own fresh standing evidence in the resolver.
|                That precedence is the resolver's responsibility, not
|                this table's — we store the override, the resolver
|                decides when it fires.
|
| The precedence chain (for reference, enforced in code):
|
|     1. viewer override (this table, scope='viewer')
|     2. viewer direct evidence (character_standings rows, scoped to
|        the viewer's character/corp/alliance)
|     3. global override (this table, scope='global')
|     4. coalition_entity_labels match
|     5. current alliance inheritance
|     6. alliance-history-derived inference
|     7. consensus (Phase 2)
|     8. fallback (neutral / unknown)
|
| Every override is expected to carry a `reason`. Overrides without
| rationale rot fast. `expires_at` gives operators and donors a way to
| set "I want this forced for the next month" without creating permanent
| drift — the resolver treats any override with expires_at in the past
| as inactive.
|
| Constraint to enforce at the model layer (MariaDB CHECK constraints
| are inconsistently supported across our deploy targets):
|
|     scope_type = 'global'  <=>  viewer_context_id IS NULL
|     scope_type = 'viewer'  <=>  viewer_context_id IS NOT NULL
|
| The unique key below allows the same (target, viewer_context_id) pair
| across scopes, which combined with the model-layer constraint above
| means at most one override per scope per viewer per target.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_classification_overrides', function (Blueprint $table) {
            $table->id();

            // Which precedence bucket this override belongs in.
            $table->enum('scope_type', ['global', 'viewer']);

            // Owning viewer for scope='viewer' rows. Null for global.
            // Cascade delete: if the viewer context goes, their
            // personal overrides go with it.
            $table->foreignId('viewer_context_id')
                ->nullable()
                ->constrained('viewer_contexts')
                ->cascadeOnDelete();

            // What kind of entity this override targets. Same ENUM
            // vocabulary as coalition_entity_labels.entity_type.
            $table->enum('target_entity_type', ['corporation', 'alliance']);

            // CCP id of the corp or alliance.
            $table->unsignedBigInteger('target_entity_id');

            // The forced classification result. Matches the resolver
            // output vocabulary used in viewer_entity_classifications.
            $table->enum('forced_alignment', ['friendly', 'hostile', 'neutral', 'unknown']);

            // Optional narrower tags the override can also force.
            // side_key is a free-form grouping (e.g. 'bloc-frontline',
            // 'home-def') — deliberately not FK'd to anything for
            // Phase 0 flexibility. role mirrors coalition_relationship_
            // types.default_role; admins can force a role independent
            // of the normalised label taxonomy.
            $table->string('forced_side_key', 32)->nullable();
            $table->string('forced_role', 32)->nullable();

            // Required rationale. 500 chars is deliberately generous —
            // operator notes matter more than column density here.
            $table->string('reason', 500);

            // Optional expiry. Null = no expiry (permanent until an
            // operator deactivates or deletes it). The resolver reads
            // this on every classification and skips expired rows
            // without needing a cleanup sweep.
            $table->timestamp('expires_at')->nullable();

            // Who created this override. FK to characters.id (same
            // convention as source_character_id on character_standings).
            // nullOnDelete so character deletion doesn't orphan the
            // override — the override remains valid, we just lose the
            // attribution.
            $table->foreignId('created_by_character_id')
                ->nullable()
                ->constrained('characters')
                ->nullOnDelete();

            // Soft-disable flag. Preferred over hard delete so audit
            // history remains intact and an accidentally-toggled
            // override can be reactivated trivially.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One active override per scope per viewer per target.
            // MariaDB treats NULLs as distinct in unique keys, so the
            // (scope='global', viewer_context_id=null) case works
            // correctly — only one global override per target is
            // allowed.
            $table->unique(
                ['scope_type', 'viewer_context_id', 'target_entity_type', 'target_entity_id'],
                'uniq_overrides_scope_viewer_target'
            );

            // Primary resolver probe: "for this target entity, do any
            // active overrides apply right now".
            $table->index(
                ['target_entity_type', 'target_entity_id', 'is_active'],
                'idx_overrides_target_active'
            );

            // Viewer-scoped probe: "show me my overrides" for the
            // donor-facing settings screen.
            $table->index(
                ['viewer_context_id', 'is_active'],
                'idx_overrides_viewer_active'
            );

            // Expiry sweep probe: "find overrides that crossed expiry
            // since last check" — used by a nightly job to flip
            // is_active off and emit an audit event.
            $table->index(
                ['expires_at', 'is_active'],
                'idx_overrides_expiry_sweep'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_classification_overrides');
    }
};
