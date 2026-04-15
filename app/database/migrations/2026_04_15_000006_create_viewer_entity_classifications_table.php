<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| viewer_entity_classifications — resolved donor-facing classifications
|--------------------------------------------------------------------------
|
| The output cache of the resolver. One row per (viewer_context, target
| entity) tuple. Everything the donor-facing UI renders comes from this
| table — no runtime resolver calls on the render path.
|
| Why a cache and not on-demand resolution:
|
|   - Resolver input is expensive to assemble (standings + labels +
|     overrides + affiliation profile + history). Doing it per page
|     load does not scale.
|   - Donors expect stable classifications. Silent re-resolution on
|     every read risks flapping when upstream data is mid-sync.
|   - The reason_summary + evidence_snapshot columns need to reflect
|     the exact inputs that produced the alignment; that's trivially
|     captured at resolver time and awkward to reconstruct on read.
|
| Invalidation strategy (hybrid event-driven + nightly rebuild):
|
|   - Events that mutate inputs (standings sync, label change, override
|     create/edit, affiliation profile refresh) mark affected rows
|     is_dirty=1 and enqueue the owning viewer for recompute.
|   - A nightly sweep re-resolves viewers with last_recomputed_at older
|     than the freshness window, catching anything a missed event
|     didn't flag.
|
| `evidence_snapshot` is a JSON blob capturing the exact evidence rows
| that fed this resolution — useful for "why is this hostile?" donor
| support queries and for the audit-history migration that follows. It
| is nullable because the resolver may skip capturing it on trivial
| resolutions (e.g. a pure fallback to 'unknown' with no evidence).
|
| needs_review is set when the resolver detected conflicting evidence,
| stale-but-critical data, or a bloc boundary crossing. The reviewer-
| queue UI reads from this flag.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewer_entity_classifications', function (Blueprint $table) {
            $table->id();

            // Tenant scope. Cascade delete: if the viewer context
            // goes, their cached classifications go with it.
            $table->foreignId('viewer_context_id')
                ->constrained('viewer_contexts')
                ->cascadeOnDelete();

            // Target entity. Same vocabulary as the other classification
            // tables. No FK (bare CCP IDs, no player-entity ref tables
            // in this phase).
            $table->enum('target_entity_type', ['corporation', 'alliance']);
            $table->unsignedBigInteger('target_entity_id');

            // Resolver output. Matches entity_classification_overrides.
            // forced_alignment vocabulary so the two are interchangeable
            // from the UI's perspective.
            $table->enum('resolved_alignment', ['friendly', 'hostile', 'neutral', 'unknown']);

            // Same shape as the override columns. Nullable because the
            // resolver can produce an alignment without necessarily
            // pinning a side/role.
            $table->string('resolved_side_key', 32)->nullable();
            $table->string('resolved_role', 32)->nullable();

            // Ordinal confidence band. Deliberately not a decimal — the
            // resolver composes evidence from heterogeneous sources
            // where fake precision would mislead. Band thresholds live
            // in the resolver's documented rules.
            $table->enum('confidence_band', ['high', 'medium', 'low']);

            // Short human-readable "why did this resolve this way"
            // string the donor UI renders inline. 500 chars matches
            // entity_classification_overrides.reason.
            $table->string('reason_summary', 500);

            // Full structured evidence captured at resolve time. JSON
            // rather than a normalised child table because the shape
            // varies by evidence source and because this column is
            // primarily for human debugging, not machine querying.
            // Retention policy to be decided in a later phase — this
            // column will be the largest in the table by volume.
            $table->json('evidence_snapshot')->nullable();

            // Flags the row for admin / donor review because the
            // resolver detected conflict, staleness, or bloc-boundary
            // weirdness it couldn't unambiguously resolve.
            $table->boolean('needs_review')->default(false);

            // Invalidation flag. Set by upstream-change events; read
            // by the resolver worker's dirty-sweep loop. Distinct from
            // the nightly staleness-based rebuild — this is the fast
            // path for "I know this specific row is out of date".
            $table->boolean('is_dirty')->default(false);

            // Timestamp of the last resolver pass that wrote this row.
            // Drives both the nightly staleness sweep and the donor-
            // facing "classification last updated" label.
            $table->timestamp('computed_at')->useCurrent();

            $table->timestamps();

            // One classification per viewer per target. The resolver
            // upserts on this key.
            $table->unique(
                ['viewer_context_id', 'target_entity_type', 'target_entity_id'],
                'uniq_vec_viewer_target'
            );

            // UI probe: "friendlies I care about" (and its hostile /
            // neutral / unknown siblings) for the donor settings and
            // entity-lookup screens.
            $table->index(
                ['viewer_context_id', 'resolved_alignment'],
                'idx_vec_viewer_alignment'
            );

            // Reviewer-queue probe.
            $table->index(
                ['needs_review', 'computed_at'],
                'idx_vec_review_queue'
            );

            // Dirty-sweep probe for the resolver worker.
            $table->index(
                ['is_dirty', 'computed_at'],
                'idx_vec_dirty_sweep'
            );

            // Reverse probe: "who thinks X is hostile" for admin ops
            // and Phase 2 consensus computation.
            $table->index(
                ['target_entity_type', 'target_entity_id', 'resolved_alignment'],
                'idx_vec_target_alignment'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewer_entity_classifications');
    }
};
