<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| viewer_entity_classification_history — audit log of alignment changes
|--------------------------------------------------------------------------
|
| Append-only change log for viewer_entity_classifications. A new row
| is written every time the resolver flips a classification's alignment
| or confidence band for a given (viewer_context, target) pair. Writes
| are triggered in the resolver service, not via DB triggers, so the
| change_reason string can capture the exact semantic cause ("override
| applied", "standing crossed +5", "alliance history updated") rather
| than just the delta.
|
| Why this exists:
|
|   - Donor trust. A paying donor asking "why did my classification
|     for Alliance X flip yesterday" needs an answer, and the answer
|     has to survive cache invalidation. The live classification row
|     only carries the current reason_summary; history carries the
|     full sequence.
|   - Operator forensics. If the resolver produces a surprising mass
|     flip (label import gone wrong, override sweep, history
|     correction), this log is the audit trail.
|
| Why no FK on viewer_context_id:
|
|   - History should survive viewer-context deletion. If a donor's
|     account is removed, we still want operator access to prior
|     resolver behaviour for their entities for compliance and
|     debugging. Deleting the log alongside the context would be an
|     over-cascade.
|   - The table is append-only. Referential integrity on deletions
|     isn't the safety property that matters here.
|
| Retention is deliberately unbounded in this migration. When this
| table becomes large enough to matter, a later migration will add
| a partition-on-changed_at or a retention policy. Phase 0 punts that.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewer_entity_classification_history', function (Blueprint $table) {
            $table->id();

            // Viewer scope, not FK'd — see header comment. UNSIGNED
            // BIGINT mirrors the id column of viewer_contexts.
            $table->unsignedBigInteger('viewer_context_id');

            // Target entity. Same vocabulary as the live
            // classification table.
            $table->enum('target_entity_type', ['corporation', 'alliance']);
            $table->unsignedBigInteger('target_entity_id');

            // Before/after pair for alignment. old_alignment is
            // nullable because the first classification for a (viewer,
            // target) tuple has no predecessor.
            $table->enum('old_alignment', ['friendly', 'hostile', 'neutral', 'unknown'])->nullable();
            $table->enum('new_alignment', ['friendly', 'hostile', 'neutral', 'unknown']);

            // Before/after pair for confidence band. Same nullable
            // rationale as above.
            $table->enum('old_confidence_band', ['high', 'medium', 'low'])->nullable();
            $table->enum('new_confidence_band', ['high', 'medium', 'low']);

            // Semantic cause of the change, produced by the resolver.
            // 500 chars matches the related tables' reason/ summary
            // columns. Examples: "override created by admin",
            // "standing changed to +7.3 via character_standings sync",
            // "alliance history refresh flipped inherited alignment".
            $table->string('change_reason', 500);

            // When the change happened. Separate column rather than
            // relying on timestamps() because this table has no
            // updated_at (append-only) and because the resolver
            // writes the exact moment the change was decided, which
            // may differ slightly from the DB insert time in
            // batched runs.
            $table->timestamp('changed_at')->useCurrent();

            // Primary audit probe: "show me the history for this
            // viewer's view of this entity, most recent first".
            $table->index(
                ['viewer_context_id', 'target_entity_type', 'target_entity_id', 'changed_at'],
                'idx_vech_viewer_target_time'
            );

            // Cross-viewer probe: "show me everything that changed
            // for this entity across all viewers" — useful for
            // detecting resolver-wide regressions when a label
            // import misfires.
            $table->index(
                ['target_entity_type', 'target_entity_id', 'changed_at'],
                'idx_vech_target_time'
            );

            // Time-range probe for operator ops: "what resolved
            // differently in the last hour".
            $table->index('changed_at', 'idx_vech_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewer_entity_classification_history');
    }
};
