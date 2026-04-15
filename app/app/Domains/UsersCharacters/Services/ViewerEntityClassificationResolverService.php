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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 */
final class ViewerEntityClassificationResolverService
{
    private const FRESH_DAYS = 7;

    private const STALE_DAYS = 30;

    public function resolveForTarget(
        ViewerContext $viewerContext,
        string $targetEntityType,
        int $targetEntityId,
    ): ViewerEntityClassification {
        $targets = [[
            'target_entity_type' => $targetEntityType,
            'target_entity_id' => $targetEntityId,
        ]];

        $catalog = $this->buildEvidenceCatalog($viewerContext, $targets, Carbon::now());

        return $this->resolveForTargetUsingCatalog(
            $viewerContext,
            $targetEntityType,
            $targetEntityId,
            Carbon::now(),
            $catalog,
        );
    }

    /**
     * @param  list<array{target_entity_type:string,target_entity_id:int}>  $targets
     * @return list<ViewerEntityClassification>
     */
    public function resolveManyForViewerContext(ViewerContext $viewerContext, array $targets): array
    {
        $now = Carbon::now();
        $catalog = $this->buildEvidenceCatalog($viewerContext, $targets, $now);
        $results = [];

        foreach ($targets as $target) {
            $results[] = $this->resolveForTargetUsingCatalog(
                $viewerContext,
                $target['target_entity_type'],
                $target['target_entity_id'],
                $now,
                $catalog,
            );
        }

        $viewerContext->forceFill(['last_recomputed_at' => $now])->save();

        return $results;
    }

    /**
     * @param  array{
     *   viewer_overrides:array<string,EntityClassificationOverride>,
     *   global_overrides:array<string,EntityClassificationOverride>,
     *   standings:array<string,list<CharacterStanding>>,
     *   labels:array<string,EloquentCollection<int,object>>,
     *   profiles:array<int,CorporationAffiliationProfile>
     * } $catalog
     */
    private function resolveForTargetUsingCatalog(
        ViewerContext $viewerContext,
        string $targetEntityType,
        int $targetEntityId,
        Carbon $now,
        array $catalog,
    ): ViewerEntityClassification {
        $resolution = $this->resolveDeterministically(
            $viewerContext,
            $targetEntityType,
            $targetEntityId,
            $now,
            $catalog,
        );

        return DB::transaction(function () use ($viewerContext, $targetEntityType, $targetEntityId, $now, $resolution): ViewerEntityClassification {
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
     * @param  array{
     *   viewer_overrides:array<string,EntityClassificationOverride>,
     *   global_overrides:array<string,EntityClassificationOverride>,
     *   standings:array<string,list<CharacterStanding>>,
     *   labels:array<string,EloquentCollection<int,object>>,
     *   profiles:array<int,CorporationAffiliationProfile>
     * } $catalog
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
        array $catalog,
    ): array {
        $targetKey = $this->targetKey($targetEntityType, $targetEntityId);

        // 1) viewer override.
        if (isset($catalog['viewer_overrides'][$targetKey])) {
            $viewerOverride = $catalog['viewer_overrides'][$targetKey];

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
        $direct = $this->resolveFromDirectStandings($viewerContext, $targetKey, $catalog['standings']);
        if ($direct !== null) {
            return $direct;
        }

        // 3) global override.
        if (isset($catalog['global_overrides'][$targetKey])) {
            $globalOverride = $catalog['global_overrides'][$targetKey];

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
        $directLabel = $this->resolveFromEntityLabels(
            viewerBlocId: $viewerContext->bloc_id,
            labels: $catalog['labels'][$targetKey] ?? new EloquentCollection(),
            step: 4,
        );
        if ($directLabel !== null) {
            return $directLabel;
        }

        // 5) current-alliance inheritance for corporation targets.
        if ($targetEntityType === ViewerEntityClassification::ENTITY_CORPORATION) {
            $profile = $catalog['profiles'][$targetEntityId] ?? null;
            if ($profile !== null && $profile->current_alliance_id !== null) {
                $inheritedKey = $this->targetKey(ViewerEntityClassification::ENTITY_ALLIANCE, $profile->current_alliance_id);
                $inherited = $this->resolveFromEntityLabels(
                    viewerBlocId: $viewerContext->bloc_id,
                    labels: $catalog['labels'][$inheritedKey] ?? new EloquentCollection(),
                    step: 5,
                    reasonPrefix: 'Inherited from corporation current alliance label.',
                );
                if ($inherited !== null) {
                    $freshnessBand = $this->freshnessBandForProfile($profile, $now);
                    $inherited['confidence_band'] = $this->minimumConfidenceBand([
                        ViewerEntityClassification::CONFIDENCE_MEDIUM,
                        $freshnessBand,
                    ]);
                    $inherited['needs_review'] = $inherited['needs_review'] || $freshnessBand === ViewerEntityClassification::CONFIDENCE_LOW;
                    if ($freshnessBand !== ViewerEntityClassification::CONFIDENCE_HIGH) {
                        $inherited['reason_summary'] .= ' Affiliation profile is stale; confidence downgraded.';
                    }
                    $inherited['evidence_snapshot']['profile_observed_at'] = $profile->observed_at?->toIso8601String();
                    $inherited['evidence_snapshot']['profile_freshness_band'] = $freshnessBand;
                    $inherited['evidence_snapshot']['via_corporation_affiliation_profile_id'] = $profile->corporation_id;

                    return $inherited;
                }
            }

            // 6) history-derived inference via previous alliance.
            if ($profile !== null && $profile->previous_alliance_id !== null) {
                $historyKey = $this->targetKey(ViewerEntityClassification::ENTITY_ALLIANCE, $profile->previous_alliance_id);
                $history = $this->resolveFromEntityLabels(
                    viewerBlocId: $viewerContext->bloc_id,
                    labels: $catalog['labels'][$historyKey] ?? new EloquentCollection(),
                    step: 6,
                    reasonPrefix: 'Inferred from corporation previous-alliance history.',
                );
                if ($history !== null) {
                    $freshnessBand = $this->freshnessBandForProfile($profile, $now);
                    $history['confidence_band'] = $this->minimumConfidenceBand([
                        ViewerEntityClassification::CONFIDENCE_LOW,
                        $profile->history_confidence_band,
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

    /**
     * @param  list<array{target_entity_type:string,target_entity_id:int}>  $targets
     * @return array{
     *   viewer_overrides:array<string,EntityClassificationOverride>,
     *   global_overrides:array<string,EntityClassificationOverride>,
     *   standings:array<string,list<CharacterStanding>>,
     *   labels:array<string,EloquentCollection<int,object>>,
     *   profiles:array<int,CorporationAffiliationProfile>
     * }
     */
    private function buildEvidenceCatalog(ViewerContext $viewerContext, array $targets, Carbon $now): array
    {
        $targetIdsByType = [];
        foreach ($targets as $target) {
            $targetIdsByType[$target['target_entity_type']][] = $target['target_entity_id'];
        }
        foreach ($targetIdsByType as $type => $ids) {
            $targetIdsByType[$type] = array_values(array_unique($ids));
        }

        $corpTargetIds = $targetIdsByType[ViewerEntityClassification::ENTITY_CORPORATION] ?? [];
        $profiles = CorporationAffiliationProfile::query()
            ->whereIn('corporation_id', $corpTargetIds)
            ->get()
            ->keyBy('corporation_id')
            ->all();

        $viewerOverrides = $this->loadOverrides(
            scope: EntityClassificationOverride::SCOPE_VIEWER,
            viewerContextId: $viewerContext->id,
            targetIdsByType: $targetIdsByType,
            now: $now,
        );
        $globalOverrides = $this->loadOverrides(
            scope: EntityClassificationOverride::SCOPE_GLOBAL,
            viewerContextId: null,
            targetIdsByType: $targetIdsByType,
            now: $now,
        );

        $standings = $this->loadStandings($viewerContext, $targetIdsByType);

        $labelTargets = $targets;
        foreach ($profiles as $profile) {
            if ($profile->current_alliance_id !== null) {
                $labelTargets[] = [
                    'target_entity_type' => ViewerEntityClassification::ENTITY_ALLIANCE,
                    'target_entity_id' => $profile->current_alliance_id,
                ];
            }
            if ($profile->previous_alliance_id !== null) {
                $labelTargets[] = [
                    'target_entity_type' => ViewerEntityClassification::ENTITY_ALLIANCE,
                    'target_entity_id' => $profile->previous_alliance_id,
                ];
            }
        }
        $labels = $this->loadLabels($labelTargets);

        return [
            'viewer_overrides' => $viewerOverrides,
            'global_overrides' => $globalOverrides,
            'standings' => $standings,
            'labels' => $labels,
            'profiles' => $profiles,
        ];
    }

    /**
     * @param  array<string,list<int>>  $targetIdsByType
     * @return array<string,EntityClassificationOverride>
     */
    private function loadOverrides(
        string $scope,
        ?int $viewerContextId,
        array $targetIdsByType,
        Carbon $now,
    ): array {
        if ($targetIdsByType === []) {
            return [];
        }

        $rows = EntityClassificationOverride::query()
            ->where('scope_type', $scope)
            ->where('viewer_context_id', $viewerContextId)
            ->where('is_active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->where(function ($query) use ($targetIdsByType): void {
                foreach ($targetIdsByType as $type => $ids) {
                    if ($ids === []) {
                        continue;
                    }
                    $query->orWhere(function ($qq) use ($type, $ids): void {
                        $qq->where('target_entity_type', $type)
                            ->whereIn('target_entity_id', $ids);
                    });
                }
            })
            ->get();

        $byKey = [];
        foreach ($rows as $row) {
            $key = $this->targetKey($row->target_entity_type, $row->target_entity_id);
            $byKey[$key] = $row;
        }

        return $byKey;
    }

    /**
     * @param  array<string,list<int>>  $targetIdsByType
     * @return array<string,list<CharacterStanding>>
     */
    private function loadStandings(ViewerContext $viewerContext, array $targetIdsByType): array
    {
        $rows = CharacterStanding::query()
            ->where(function ($query) use ($viewerContext): void {
                $query->orWhere(function ($qq) use ($viewerContext): void {
                    $qq->where('owner_type', CharacterStanding::OWNER_CHARACTER)
                        ->where('owner_id', $viewerContext->character_id);
                });

                if ($viewerContext->viewer_corporation_id !== null) {
                    $query->orWhere(function ($qq) use ($viewerContext): void {
                        $qq->where('owner_type', CharacterStanding::OWNER_CORPORATION)
                            ->where('owner_id', $viewerContext->viewer_corporation_id);
                    });
                }

                if ($viewerContext->viewer_alliance_id !== null) {
                    $query->orWhere(function ($qq) use ($viewerContext): void {
                        $qq->where('owner_type', CharacterStanding::OWNER_ALLIANCE)
                            ->where('owner_id', $viewerContext->viewer_alliance_id);
                    });
                }
            })
            ->where(function ($query) use ($targetIdsByType): void {
                foreach ($targetIdsByType as $type => $ids) {
                    if ($ids === []) {
                        continue;
                    }
                    $query->orWhere(function ($qq) use ($type, $ids): void {
                        $qq->where('contact_type', $type)
                            ->whereIn('contact_id', $ids);
                    });
                }
            })
            ->get();

        $byKey = [];
        foreach ($rows as $row) {
            $key = $this->targetKey($row->contact_type, $row->contact_id);
            $byKey[$key] ??= [];
            $byKey[$key][] = $row;
        }

        return $byKey;
    }

    /**
     * @param  list<array{target_entity_type:string,target_entity_id:int}>  $targets
     * @return array<string,EloquentCollection<int,object>>
     */
    private function loadLabels(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $rows = CoalitionEntityLabel::query()
            ->where('is_active', true)
            ->whereNotNull('bloc_id')
            ->where(function ($query) use ($targets): void {
                foreach ($targets as $target) {
                    $query->orWhere(function ($qq) use ($target): void {
                        $qq->where('entity_type', $target['target_entity_type'])
                            ->where('entity_id', $target['target_entity_id']);
                    });
                }
            })
            ->leftJoin('coalition_relationship_types', 'coalition_entity_labels.relationship_type_id', '=', 'coalition_relationship_types.id')
            ->leftJoin('coalition_blocs', 'coalition_entity_labels.bloc_id', '=', 'coalition_blocs.id')
            ->orderByRaw('CASE WHEN coalition_relationship_types.display_order IS NULL THEN 9999 ELSE coalition_relationship_types.display_order END')
            ->orderBy('coalition_entity_labels.id')
            ->select([
                'coalition_entity_labels.entity_type',
                'coalition_entity_labels.entity_id',
                'coalition_entity_labels.id as label_id',
                'coalition_entity_labels.raw_label',
                'coalition_entity_labels.bloc_id',
                'coalition_relationship_types.default_role as relationship_default_role',
                'coalition_relationship_types.inherits_alignment',
                'coalition_blocs.bloc_code',
                'coalition_blocs.default_role as bloc_default_role',
            ])
            ->get();

        $byKey = [];
        foreach ($rows as $row) {
            $key = $this->targetKey((string) $row->entity_type, (int) $row->entity_id);
            $byKey[$key] ??= new EloquentCollection();
            $byKey[$key]->push($row);
        }

        return $byKey;
    }

    /**
     * @param  array<string,list<CharacterStanding>>  $standingsByTarget
     * @return array<string,mixed>|null
     */
    private function resolveFromDirectStandings(
        ViewerContext $viewerContext,
        string $targetKey,
        array $standingsByTarget,
    ): ?array {
        $rows = $standingsByTarget[$targetKey] ?? [];
        if ($rows === []) {
            return null;
        }

        $rankByOwnerType = [
            CharacterStanding::OWNER_CHARACTER => 1,
            CharacterStanding::OWNER_CORPORATION => 2,
            CharacterStanding::OWNER_ALLIANCE => 3,
        ];

        usort($rows, function (CharacterStanding $a, CharacterStanding $b) use ($rankByOwnerType): int {
            return ($rankByOwnerType[$a->owner_type] ?? 99) <=> ($rankByOwnerType[$b->owner_type] ?? 99);
        });

        $winner = $rows[0];
        $winnerAlignment = $this->alignmentFromStanding($winner);

        $conflicts = [];
        foreach ($rows as $row) {
            $alignment = $this->alignmentFromStanding($row);
            if ($alignment !== $winnerAlignment) {
                $conflicts[] = [
                    'owner_type' => $row->owner_type,
                    'owner_id' => $row->owner_id,
                    'standing' => $row->standing,
                    'alignment' => $alignment,
                ];
            }
        }

        $needsReview = $conflicts !== [];
        $reason = sprintf(
            'Viewer standing evidence (%s #%d): %s.',
            $winner->owner_type,
            $winner->owner_id,
            $winner->standing,
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
                'standing_id' => $winner->id,
                'owner_type' => $winner->owner_type,
                'owner_id' => $winner->owner_id,
                'standing' => $winner->standing,
                'conflicting_owner_level_evidence' => $conflicts,
            ],
            'needs_review' => $needsReview,
        ];
    }

    /**
     * @param  EloquentCollection<int,object>  $labels
     * @return array<string,mixed>|null
     */
    private function resolveFromEntityLabels(
        ?int $viewerBlocId,
        EloquentCollection $labels,
        int $step,
        ?string $reasonPrefix = null,
    ): ?array {
        if ($labels->isEmpty()) {
            return null;
        }

        $selected = null;
        foreach ($labels as $row) {
            // Deliberately strict: labels without a parsed relationship type
            // (inherits_alignment null due to left join) do not provide
            // inheritable alignment signal in v1.
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
        return match ($standing->classification()) {
            'friendly' => ViewerEntityClassification::ALIGNMENT_FRIENDLY,
            'enemy' => ViewerEntityClassification::ALIGNMENT_HOSTILE,
            default => ViewerEntityClassification::ALIGNMENT_NEUTRAL,
        };
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

    private function freshnessBandForProfile(CorporationAffiliationProfile $profile, Carbon $now): string
    {
        $observedAt = $profile->observed_at;
        if ($observedAt === null) {
            return ViewerEntityClassification::CONFIDENCE_LOW;
        }

        $ageDays = $observedAt->diffInDays($now);
        if ($ageDays <= self::FRESH_DAYS) {
            return ViewerEntityClassification::CONFIDENCE_HIGH;
        }
        if ($ageDays <= self::STALE_DAYS) {
            return ViewerEntityClassification::CONFIDENCE_MEDIUM;
        }

        return ViewerEntityClassification::CONFIDENCE_LOW;
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

    private function targetKey(string $entityType, int $entityId): string
    {
        return $entityType.':'.$entityId;
    }
}
