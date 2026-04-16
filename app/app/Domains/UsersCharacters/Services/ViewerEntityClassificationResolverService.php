<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\CharacterStanding;
use App\Domains\UsersCharacters\Models\CoalitionBloc;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\CorporationAffiliationProfile;
use App\Domains\UsersCharacters\Models\EntityClassificationOverride;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Models\ViewerEntityClassification;
use App\Domains\UsersCharacters\Models\ViewerEntityClassificationHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1 resolver for donor-facing entity classifications.
 *
 * Implements the deterministic precedence chain documented in
 * 2026_04_15_000005_create_entity_classification_overrides_table.php:
 *
 *   1. viewer override
 *   2. viewer direct evidence (standings)
 *   3. global override
 *   4. coalition_entity_labels match
 *   5. current alliance inheritance (corp targets)
 *   6. alliance-history-derived inference (corp targets)
 *   7. consensus (Phase 2, not implemented in v1)
 *   8. fallback (neutral / unknown)
 *
 * Persistence is transactional: the classification upsert and the
 * history append live in one DB::transaction so a partial write
 * (cache row saved but audit entry lost) cannot happen.
 *
 * Standing thresholds and affiliation freshness windows are read from
 * config('classification.*') so operators can tune without a deploy.
 */
final class ViewerEntityClassificationResolverService
{
    public function resolveForTarget(
        ViewerContext $viewerContext,
        string $targetEntityType,
        int $targetEntityId,
    ): ViewerEntityClassification {
        $now = Carbon::now();
        $resolution = $this->resolveDeterministically(
            $viewerContext,
            $targetEntityType,
            $targetEntityId,
            $now,
        );

        return $this->persistResolution(
            $viewerContext,
            $targetEntityType,
            $targetEntityId,
            $resolution,
            $now,
        );
    }

    /**
     * @param  list<array{target_entity_type:string,target_entity_id:int}>  $targets
     * @return list<ViewerEntityClassification>
     */
    public function resolveManyForViewerContext(ViewerContext $viewerContext, array $targets): array
    {
        $now = Carbon::now();
        $results = [];

        foreach ($targets as $target) {
            $resolution = $this->resolveDeterministically(
                $viewerContext,
                $target['target_entity_type'],
                $target['target_entity_id'],
                $now,
            );

            $results[] = $this->persistResolution(
                $viewerContext,
                $target['target_entity_type'],
                $target['target_entity_id'],
                $resolution,
                $now,
            );
        }

        $viewerContext->forceFill(['last_recomputed_at' => $now])->save();

        return $results;
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function persistResolution(
        ViewerContext $viewerContext,
        string $targetEntityType,
        int $targetEntityId,
        array $resolution,
        Carbon $now,
    ): ViewerEntityClassification {
        return DB::transaction(function () use ($viewerContext, $targetEntityType, $targetEntityId, $resolution, $now): ViewerEntityClassification {
            $classification = ViewerEntityClassification::query()->firstOrNew([
                'viewer_context_id' => $viewerContext->id,
                'target_entity_type' => $targetEntityType,
                'target_entity_id' => $targetEntityId,
            ]);

            $wasExisting = $classification->exists;
            $oldAlignment = $wasExisting ? $classification->resolved_alignment : null;
            $oldConfidence = $wasExisting ? $classification->confidence_band : null;

            $classification->fill([
                'resolved_alignment' => $resolution['resolved_alignment'],
                'resolved_side_key' => $resolution['resolved_side_key'],
                'resolved_role' => $resolution['resolved_role'],
                'confidence_band' => $resolution['confidence_band'],
                'reason_summary' => $resolution['reason_summary'],
                'evidence_snapshot' => $resolution['evidence_snapshot'],
                'needs_review' => $resolution['needs_review'],
                'is_dirty' => false,
                'computed_at' => $now,
            ]);
            $classification->save();

            $changed = ! $wasExisting
                || $oldAlignment !== $classification->resolved_alignment
                || $oldConfidence !== $classification->confidence_band;

            if ($changed) {
                ViewerEntityClassificationHistory::query()->create([
                    'viewer_context_id' => $viewerContext->id,
                    'target_entity_type' => $targetEntityType,
                    'target_entity_id' => $targetEntityId,
                    'old_alignment' => $oldAlignment,
                    'new_alignment' => $classification->resolved_alignment,
                    'old_confidence_band' => $oldConfidence,
                    'new_confidence_band' => $classification->confidence_band,
                    'change_reason' => $classification->reason_summary,
                    'changed_at' => $now,
                ]);
            }

            return $classification->refresh();
        });
    }

    /**
     * @return array{
     *   resolved_alignment:string,
     *   resolved_side_key:?string,
     *   resolved_role:?string,
     *   confidence_band:string,
     *   reason_summary:string,
     *   evidence_snapshot:?array<string,mixed>,
     *   needs_review:bool
     * }
     */
    private function resolveDeterministically(
        ViewerContext $viewerContext,
        string $targetEntityType,
        int $targetEntityId,
        Carbon $now,
    ): array {
        // 1) viewer override.
        $viewerOverride = $this->activeOverride(
            EntityClassificationOverride::SCOPE_VIEWER,
            $viewerContext->id,
            $targetEntityType,
            $targetEntityId,
            $now,
        );
        if ($viewerOverride !== null) {
            return [
                'resolved_alignment' => $viewerOverride->forced_alignment,
                'resolved_side_key' => $viewerOverride->forced_side_key,
                'resolved_role' => $viewerOverride->forced_role,
                'confidence_band' => ViewerEntityClassification::CONFIDENCE_HIGH,
                'reason_summary' => 'Viewer override applied: '.$viewerOverride->reason,
                'evidence_snapshot' => [
                    'step' => 1,
                    'source' => 'viewer_override',
                    'override_id' => $viewerOverride->id,
                ],
                'needs_review' => false,
            ];
        }

        // 2) viewer direct evidence (character/corp/alliance standings).
        $direct = $this->resolveFromDirectStandings($viewerContext, $targetEntityType, $targetEntityId);
        if ($direct !== null) {
            return $direct;
        }

        // 3) global override.
        $globalOverride = $this->activeOverride(
            EntityClassificationOverride::SCOPE_GLOBAL,
            null,
            $targetEntityType,
            $targetEntityId,
            $now,
        );
        if ($globalOverride !== null) {
            return [
                'resolved_alignment' => $globalOverride->forced_alignment,
                'resolved_side_key' => $globalOverride->forced_side_key,
                'resolved_role' => $globalOverride->forced_role,
                'confidence_band' => ViewerEntityClassification::CONFIDENCE_HIGH,
                'reason_summary' => 'Global override applied: '.$globalOverride->reason,
                'evidence_snapshot' => [
                    'step' => 3,
                    'source' => 'global_override',
                    'override_id' => $globalOverride->id,
                ],
                'needs_review' => false,
            ];
        }

        // 4) direct coalition label match on the target entity.
        $directLabel = $this->resolveFromEntityLabels($viewerContext->bloc_id, $targetEntityType, $targetEntityId, 4);
        if ($directLabel !== null) {
            return $directLabel;
        }

        // 5) current-alliance inheritance for corporation targets.
        if ($targetEntityType === ViewerEntityClassification::ENTITY_CORPORATION) {
            $profile = CorporationAffiliationProfile::query()->find($targetEntityId);
            if ($profile !== null && $profile->current_alliance_id !== null) {
                $freshnessBand = $this->freshnessBandForProfile($profile, $now);
                if ($freshnessBand !== null) {
                    $inherited = $this->resolveFromEntityLabels(
                        $viewerContext->bloc_id,
                        ViewerEntityClassification::ENTITY_ALLIANCE,
                        $profile->current_alliance_id,
                        5,
                        'Inherited from corporation current alliance label.',
                    );
                    if ($inherited !== null) {
                        $inherited['confidence_band'] = $this->minimumConfidenceBand([
                            ViewerEntityClassification::CONFIDENCE_MEDIUM,
                            $freshnessBand,
                        ]);
                        $inherited['needs_review'] = $inherited['needs_review']
                            || $freshnessBand === ViewerEntityClassification::CONFIDENCE_LOW;
                        if ($freshnessBand !== ViewerEntityClassification::CONFIDENCE_HIGH) {
                            $inherited['reason_summary'] .= ' Affiliation profile is stale; confidence downgraded.';
                        }
                        $inherited['evidence_snapshot']['profile_observed_at'] = $profile->observed_at?->toIso8601String();
                        $inherited['evidence_snapshot']['profile_freshness_band'] = $freshnessBand;
                        $inherited['evidence_snapshot']['via_corporation_affiliation_profile_id'] = $profile->corporation_id;

                        return $inherited;
                    }
                }
            }

            // 6) history-derived inference via previous alliance.
            if (isset($profile) && $profile !== null && $profile->previous_alliance_id !== null) {
                $freshnessBand = $this->freshnessBandForProfile($profile, $now);
                if ($freshnessBand !== null) {
                    $history = $this->resolveFromEntityLabels(
                        $viewerContext->bloc_id,
                        ViewerEntityClassification::ENTITY_ALLIANCE,
                        $profile->previous_alliance_id,
                        6,
                        'Inferred from corporation previous-alliance history.',
                    );
                    if ($history !== null) {
                        $history['confidence_band'] = $this->minimumConfidenceBand([
                            ViewerEntityClassification::CONFIDENCE_LOW,
                            $profile->history_confidence_band ?? ViewerEntityClassification::CONFIDENCE_LOW,
                            $freshnessBand,
                        ]);
                        $history['needs_review'] = true;
                        $history['evidence_snapshot']['history_confidence_band'] = $profile->history_confidence_band;
                        $history['evidence_snapshot']['recently_changed_affiliation'] = $profile->recently_changed_affiliation;
                        $history['evidence_snapshot']['last_alliance_change_at'] = $profile->last_alliance_change_at?->toIso8601String();
                        $history['evidence_snapshot']['profile_observed_at'] = $profile->observed_at?->toIso8601String();
                        $history['evidence_snapshot']['profile_freshness_band'] = $freshnessBand;
                        $history['evidence_snapshot']['via_corporation_affiliation_profile_id'] = $profile->corporation_id;

                        return $history;
                    }
                }
            }
        }

        // 8) fallback (7 consensus intentionally omitted in v1).
        $alignment = $viewerContext->bloc_id === null
            ? ViewerEntityClassification::ALIGNMENT_UNKNOWN
            : ViewerEntityClassification::ALIGNMENT_NEUTRAL;

        return [
            'resolved_alignment' => $alignment,
            'resolved_side_key' => null,
            'resolved_role' => null,
            'confidence_band' => ViewerEntityClassification::CONFIDENCE_LOW,
            'reason_summary' => $alignment === ViewerEntityClassification::ALIGNMENT_UNKNOWN
                ? 'Fallback: viewer bloc unresolved and no evidence matched.'
                : 'Fallback: no matching evidence; defaulting to neutral.',
            'evidence_snapshot' => [
                'step' => 8,
                'source' => 'fallback',
                'consensus' => 'not_implemented_v1',
            ],
            'needs_review' => false,
        ];
    }

    private function activeOverride(
        string $scope,
        ?int $viewerContextId,
        string $targetEntityType,
        int $targetEntityId,
        Carbon $now,
    ): ?EntityClassificationOverride {
        return EntityClassificationOverride::query()
            ->where('scope_type', $scope)
            ->where('viewer_context_id', $viewerContextId)
            ->where('target_entity_type', $targetEntityType)
            ->where('target_entity_id', $targetEntityId)
            ->where('is_active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->first();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveFromDirectStandings(
        ViewerContext $viewerContext,
        string $targetEntityType,
        int $targetEntityId,
    ): ?array {
        $ownerCandidates = [
            [CharacterStanding::OWNER_CHARACTER, $viewerContext->character_id],
            [CharacterStanding::OWNER_CORPORATION, $viewerContext->viewer_corporation_id],
            [CharacterStanding::OWNER_ALLIANCE, $viewerContext->viewer_alliance_id],
        ];

        $allStandings = [];
        foreach ($ownerCandidates as [$ownerType, $ownerId]) {
            if ($ownerId === null) {
                continue;
            }

            $standing = CharacterStanding::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->where('contact_type', $targetEntityType)
                ->where('contact_id', $targetEntityId)
                ->first();

            if ($standing !== null) {
                $allStandings[] = [$ownerType, $ownerId, $standing];
            }
        }

        if ($allStandings === []) {
            return null;
        }

        // Most specific wins (character > corp > alliance — iteration
        // order above guarantees this). Record the winner and check
        // for conflicts with less specific levels.
        [$winnerOwnerType, $winnerOwnerId, $winnerStanding] = $allStandings[0];
        $winnerAlignment = $this->alignmentFromStanding($winnerStanding);

        $conflicts = [];
        for ($i = 1, $count = count($allStandings); $i < $count; $i++) {
            [$otherOwnerType, $otherOwnerId, $otherStanding] = $allStandings[$i];
            $otherAlignment = $this->alignmentFromStanding($otherStanding);
            if ($otherAlignment !== $winnerAlignment) {
                $conflicts[] = [
                    'owner_type' => $otherOwnerType,
                    'owner_id' => $otherOwnerId,
                    'standing' => $otherStanding->standing,
                    'alignment' => $otherAlignment,
                ];
            }
        }

        $needsReview = $conflicts !== [];
        $reason = sprintf(
            'Viewer standing evidence (%s #%d): %s.',
            $winnerOwnerType,
            $winnerOwnerId,
            $winnerStanding->standing,
        );
        if ($needsReview) {
            $reason .= ' Conflicting standings exist at other owner levels; precedence applied.';
        }

        return [
            'resolved_alignment' => $winnerAlignment,
            'resolved_side_key' => null,
            'resolved_role' => null,
            'confidence_band' => ViewerEntityClassification::CONFIDENCE_HIGH,
            'reason_summary' => $reason,
            'evidence_snapshot' => [
                'step' => 2,
                'source' => 'direct_standing',
                'standing_id' => $winnerStanding->id,
                'owner_type' => $winnerOwnerType,
                'owner_id' => $winnerOwnerId,
                'standing' => $winnerStanding->standing,
                'conflicting_owner_level_evidence' => $conflicts,
            ],
            'needs_review' => $needsReview,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveFromEntityLabels(
        ?int $viewerBlocId,
        string $targetEntityType,
        int $targetEntityId,
        int $step,
        ?string $reasonPrefix = null,
    ): ?array {
        // Column names here must be fully qualified — `is_active`,
        // `entity_type`, `entity_id`, and `bloc_id` all exist on more
        // than one of the joined tables, so an unqualified reference
        // is ambiguous on every strict-SQL engine (MariaDB, SQLite).
        $labels = CoalitionEntityLabel::query()
            ->where('coalition_entity_labels.entity_type', $targetEntityType)
            ->where('coalition_entity_labels.entity_id', $targetEntityId)
            ->where('coalition_entity_labels.is_active', true)
            ->whereNotNull('coalition_entity_labels.bloc_id')
            ->leftJoin('coalition_relationship_types', 'coalition_entity_labels.relationship_type_id', '=', 'coalition_relationship_types.id')
            ->leftJoin('coalition_blocs', 'coalition_entity_labels.bloc_id', '=', 'coalition_blocs.id')
            ->orderByRaw('CASE WHEN coalition_relationship_types.display_order IS NULL THEN 9999 ELSE coalition_relationship_types.display_order END')
            ->orderBy('coalition_entity_labels.id')
            ->select([
                'coalition_entity_labels.id as label_id',
                'coalition_entity_labels.raw_label',
                'coalition_entity_labels.bloc_id',
                'coalition_relationship_types.default_role as relationship_default_role',
                'coalition_relationship_types.inherits_alignment',
                'coalition_blocs.bloc_code',
                'coalition_blocs.default_role as bloc_default_role',
            ])
            ->get();

        if ($labels->isEmpty()) {
            return null;
        }

        $selected = null;
        foreach ($labels as $row) {
            if ((bool) $row->inherits_alignment === false) {
                continue;
            }
            $selected = $row;
            break;
        }

        if ($selected === null) {
            return null;
        }

        $alignment = $this->alignmentFromBlocMatch($viewerBlocId, (int) $selected->bloc_id, (string) $selected->bloc_code);
        $role = $selected->relationship_default_role ?: $selected->bloc_default_role;
        $isConflicted = $labels->pluck('bloc_id')->unique()->count() > 1;

        $reason = ($reasonPrefix ? rtrim($reasonPrefix).' ' : '')
            .'Matched coalition label '.$selected->raw_label.'.';
        if ($isConflicted) {
            $reason .= ' Multiple bloc labels present; using highest-priority inheriting relationship.';
        }

        return [
            'resolved_alignment' => $alignment,
            'resolved_side_key' => $selected->bloc_code,
            'resolved_role' => $role,
            'confidence_band' => ViewerEntityClassification::CONFIDENCE_MEDIUM,
            'reason_summary' => $reason,
            'evidence_snapshot' => [
                'step' => $step,
                'source' => $step === 4 ? 'entity_label' : ($step === 5 ? 'current_alliance_inheritance' : 'alliance_history_inference'),
                'label_id' => (int) $selected->label_id,
                'label' => (string) $selected->raw_label,
                'target_bloc_id' => (int) $selected->bloc_id,
                'target_bloc_code' => (string) $selected->bloc_code,
                'viewer_bloc_id' => $viewerBlocId,
                'conflicting_bloc_labels' => $isConflicted,
            ],
            'needs_review' => $isConflicted,
        ];
    }

    private function alignmentFromStanding(CharacterStanding $standing): string
    {
        $value = (float) $standing->standing;
        $friendlyAt = (float) config('classification.standings.friendly_at', 5.0);
        $hostileAt = (float) config('classification.standings.hostile_at', -5.0);

        if ($value >= $friendlyAt) {
            return ViewerEntityClassification::ALIGNMENT_FRIENDLY;
        }
        if ($value <= $hostileAt) {
            return ViewerEntityClassification::ALIGNMENT_HOSTILE;
        }

        return ViewerEntityClassification::ALIGNMENT_NEUTRAL;
    }

    private function alignmentFromBlocMatch(?int $viewerBlocId, int $targetBlocId, string $targetBlocCode): string
    {
        if ($targetBlocCode === CoalitionBloc::CODE_UNKNOWN) {
            return ViewerEntityClassification::ALIGNMENT_UNKNOWN;
        }

        if ($targetBlocCode === CoalitionBloc::CODE_INDEPENDENT) {
            return ViewerEntityClassification::ALIGNMENT_NEUTRAL;
        }

        if ($viewerBlocId === null) {
            return ViewerEntityClassification::ALIGNMENT_UNKNOWN;
        }

        if ($viewerBlocId === $targetBlocId) {
            return ViewerEntityClassification::ALIGNMENT_FRIENDLY;
        }

        return ViewerEntityClassification::ALIGNMENT_HOSTILE;
    }

    /**
     * Returns the confidence band implied by how recently the profile
     * was observed. Returns null when the profile is beyond the stale
     * window — the caller should skip the rung entirely.
     */
    private function freshnessBandForProfile(CorporationAffiliationProfile $profile, Carbon $now): ?string
    {
        $observedAt = $profile->observed_at;
        if ($observedAt === null) {
            return ViewerEntityClassification::CONFIDENCE_LOW;
        }

        $freshDays = (int) config('classification.affiliation_freshness.fresh_days', 7);
        $staleDays = (int) config('classification.affiliation_freshness.stale_days', 30);
        $ageDays = (int) $observedAt->diffInDays($now);

        if ($ageDays <= $freshDays) {
            return ViewerEntityClassification::CONFIDENCE_HIGH;
        }
        if ($ageDays <= $staleDays) {
            return ViewerEntityClassification::CONFIDENCE_MEDIUM;
        }

        // Beyond stale window — rung should be skipped.
        return null;
    }

    /** @param list<string> $bands */
    private function minimumConfidenceBand(array $bands): string
    {
        $rank = [
            ViewerEntityClassification::CONFIDENCE_LOW => 1,
            ViewerEntityClassification::CONFIDENCE_MEDIUM => 2,
            ViewerEntityClassification::CONFIDENCE_HIGH => 3,
        ];

        $winner = ViewerEntityClassification::CONFIDENCE_HIGH;
        foreach ($bands as $band) {
            if (($rank[$band] ?? 0) < $rank[$winner]) {
                $winner = $band;
            }
        }

        return $winner;
    }
}
