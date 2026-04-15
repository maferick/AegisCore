<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| viewer_contexts — per-character tenancy row for the classification system
|--------------------------------------------------------------------------
|
| One row per paying character who consumes donor-facing classification.
| The resolver treats this row as the "tenant" key: every resolved
| alignment, every override, every cached classification is scoped to a
| viewer_context_id.
|
| Why tenancy at the character level (not user, not corp, not alliance):
|
|   - A single user can have multiple characters in different corps /
|     alliances, each with a different strategic lens. Per-character
|     scoping keeps each lens faithful.
|   - Standing-source precedence (character > corp > alliance, see
|     resolver design notes) is calculated from a specific character's
|     vantage point. Aggregating to user would lose that.
|   - Corp/alliance-level tenancy would collapse per-character overrides
|     that donors legitimately want (e.g. a diplomat tracking a rival
|     alliance differently from their rank-and-file corpmates).
|
| FK to characters.id (not the bare CCP character_id) for consistency
| with the rest of the app — character_standings.source_character_id,
| eve_donations_tokens, eve_market_tokens all FK to characters.id. This
| also gives us cascading behaviour: deleting a character row (e.g. via
| user deletion) cleans up the viewer context and everything that hangs
| off it.
|
| viewer_corporation_id / viewer_alliance_id are cached CCP IDs for
| resolver convenience — they're duplicated from characters.* but
| refreshed explicitly on viewer-context sync so the resolver doesn't
| race with affiliation churn. No FK because there's no local player-
| corp/alliance ref table to target (deferred to a later phase).
|
| bloc_id + bloc_confidence_band + bloc_unresolved together form the
| viewer-bloc inference output. Per the Phase 0 onboarding plan, the
| FIRST donor-facing screen confirms/fills this triple: if the viewer
| bloc is wrong or missing, every downstream classification is noisy.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewer_contexts', function (Blueprint $table) {
            $table->id();

            // Owning character. One viewer context per character, so
            // the FK is also the uniqueness key. Cascade on delete
            // because a viewer context has no meaning without its
            // character.
            $table->foreignId('character_id')
                ->unique()
                ->constrained('characters')
                ->cascadeOnDelete();

            // Cached corp affiliation at last sync. UNSIGNED BIGINT
            // matches characters.corporation_id. Nullable because a
            // freshly-created viewer context may exist before the
            // first affiliation sync runs.
            $table->unsignedBigInteger('viewer_corporation_id')->nullable();

            // Cached alliance affiliation. Null both pre-sync AND for
            // characters whose corp isn't in an alliance — both cases
            // are legal and distinguished by viewer_corporation_id.
            $table->unsignedBigInteger('viewer_alliance_id')->nullable();

            // Inferred coalition bloc for this viewer, from the
            // resolver's viewer-bloc inference pass. Nullable because
            // inference may fail (small/independent alliance with no
            // labels) — when null, bloc_unresolved is true and the
            // onboarding screen prompts the donor to pick manually.
            $table->foreignId('bloc_id')
                ->nullable()
                ->constrained('coalition_blocs')
                ->nullOnDelete();

            // How confident the inference is. Ordinal bands only — no
            // fake decimals. 'high' = direct label on viewer alliance
            // or corp; 'medium' = inherited from alliance history or
            // similar-viewer consensus (Phase 2); 'low' = weak / mixed
            // signal.
            $table->enum('bloc_confidence_band', ['high', 'medium', 'low'])->nullable();

            // True until the donor has confirmed their bloc (either by
            // accepting the inference or picking manually). The
            // onboarding queue watches this flag.
            $table->boolean('bloc_unresolved')->default(true);

            // Current paid-access status for this viewer. VARCHAR rather
            // than ENUM so subscription model changes don't require a
            // schema migration; expected values ('active', 'expired',
            // 'trialing', 'none') live in the application layer.
            $table->string('subscription_status', 32)->default('none');

            // Soft-disable flag for operators — deactivating a viewer
            // context stops the resolver from recomputing their
            // classifications without deleting any history.
            $table->boolean('is_active')->default(true);

            // Last time the resolver ran a full recompute for this
            // viewer. Used by the event-driven-plus-nightly invalidation
            // strategy to pick up viewers that haven't been touched in
            // a while even if no event fired.
            $table->timestamp('last_recomputed_at')->nullable();

            $table->timestamps();

            // Onboarding queue probe: "active viewers whose bloc is
            // still unresolved, oldest first".
            $table->index(
                ['bloc_unresolved', 'is_active', 'created_at'],
                'idx_viewer_contexts_onboarding'
            );

            // Resolver scheduler probe: "active viewers I haven't
            // recomputed recently".
            $table->index(
                ['is_active', 'last_recomputed_at'],
                'idx_viewer_contexts_recompute'
            );

            // Reverse lookup: "who is in bloc X" for admin ops and
            // Phase 2 consensus cohort building.
            $table->index(['bloc_id', 'is_active'], 'idx_viewer_contexts_bloc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewer_contexts');
    }
};
