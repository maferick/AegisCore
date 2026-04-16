<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Observers;

use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\CorporationAffiliationProfile;
use App\Domains\UsersCharacters\Models\EntityClassificationOverride;
use App\Domains\UsersCharacters\Models\ViewerEntityClassification;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks viewer_entity_classifications rows as dirty when any of the
 * four upstream input models change. Registered once per model in
 * {@see \App\Providers\AppServiceProvider::boot()}.
 *
 * The observer fires on saved() and deleted() — covering create,
 * update, and delete in one handler each. It does NOT recompute
 * the classification inline; it only flips the `is_dirty` flag so
 * the {@see \App\Domains\UsersCharacters\Jobs\RecomputeDirtyClassificationsJob}
 * picks up the affected rows on its next sweep.
 *
 * Query shape is deliberately simple: a single UPDATE with an
 * indexed WHERE. The `idx_vec_target_alignment` index on
 * (target_entity_type, target_entity_id, resolved_alignment)
 * covers the target-scoped dirty-mark queries.
 */
final class ClassificationDirtyObserver
{
    public function saved(Model $model): void
    {
        $this->markDirty($model);
    }

    public function deleted(Model $model): void
    {
        $this->markDirty($model);
    }

    private function markDirty(Model $model): void
    {
        match (true) {
            $model instanceof CharacterStanding => $this->markDirtyForStanding($model),
            $model instanceof CoalitionEntityLabel => $this->markDirtyForLabel($model),
            $model instanceof EntityClassificationOverride => $this->markDirtyForOverride($model),
            $model instanceof CorporationAffiliationProfile => $this->markDirtyForProfile($model),
            default => null,
        };
    }

    /**
     * A standing change affects the (contact_type, contact_id) target
     * across ALL viewers — the standing belongs to a viewer's owner
     * identity, but determining which viewer_context rows share that
     * owner is an expensive join we don't need here. Marking every
     * viewer's row for this target dirty is safe: the recompute job
     * will re-evaluate and produce the same result for unaffected
     * viewers (a no-op idempotent write).
     */
    private function markDirtyForStanding(CharacterStanding $standing): void
    {
        if (! in_array($standing->contact_type, ['corporation', 'alliance'], true)) {
            return;
        }

        ViewerEntityClassification::query()
            ->where('target_entity_type', $standing->contact_type)
            ->where('target_entity_id', $standing->contact_id)
            ->where('is_dirty', false)
            ->update(['is_dirty' => true]);
    }

    /**
     * A label change affects the (entity_type, entity_id) target
     * directly. For alliance labels it also affects corporation
     * targets whose affiliation profile points at this alliance
     * (inheritance rungs 5 + 6).
     */
    private function markDirtyForLabel(CoalitionEntityLabel $label): void
    {
        // Direct target.
        ViewerEntityClassification::query()
            ->where('target_entity_type', $label->entity_type)
            ->where('target_entity_id', $label->entity_id)
            ->where('is_dirty', false)
            ->update(['is_dirty' => true]);

        // Inheritance: if this is an alliance label, corps inheriting
        // from it need invalidation too.
        if ($label->entity_type === CoalitionEntityLabel::ENTITY_ALLIANCE) {
            $corpIds = CorporationAffiliationProfile::query()
                ->where(function ($q) use ($label): void {
                    $q->where('current_alliance_id', $label->entity_id)
                        ->orWhere('previous_alliance_id', $label->entity_id);
                })
                ->pluck('corporation_id');

            if ($corpIds->isNotEmpty()) {
                ViewerEntityClassification::query()
                    ->where('target_entity_type', ViewerEntityClassification::ENTITY_CORPORATION)
                    ->whereIn('target_entity_id', $corpIds)
                    ->where('is_dirty', false)
                    ->update(['is_dirty' => true]);
            }
        }
    }

    /**
     * Override changes affect a specific target. Viewer-scope overrides
     * only affect rows for that viewer; global overrides affect all
     * viewers' rows for that target.
     */
    private function markDirtyForOverride(EntityClassificationOverride $override): void
    {
        $query = ViewerEntityClassification::query()
            ->where('target_entity_type', $override->target_entity_type)
            ->where('target_entity_id', $override->target_entity_id)
            ->where('is_dirty', false);

        if ($override->scope_type === EntityClassificationOverride::SCOPE_VIEWER && $override->viewer_context_id !== null) {
            $query->where('viewer_context_id', $override->viewer_context_id);
        }

        $query->update(['is_dirty' => true]);
    }

    /**
     * An affiliation profile change affects the corp target across
     * all viewers.
     */
    private function markDirtyForProfile(CorporationAffiliationProfile $profile): void
    {
        ViewerEntityClassification::query()
            ->where('target_entity_type', ViewerEntityClassification::ENTITY_CORPORATION)
            ->where('target_entity_id', $profile->corporation_id)
            ->where('is_dirty', false)
            ->update(['is_dirty' => true]);
    }
}
