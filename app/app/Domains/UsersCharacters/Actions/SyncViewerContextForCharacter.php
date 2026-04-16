<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Actions;

use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\CoalitionEntityLabel;
use App\Domains\UsersCharacters\Models\CoalitionRelationshipType;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Services\ViewerBlocInferenceInput;
use App\Domains\UsersCharacters\Services\ViewerBlocInferenceService;
use App\Services\Eve\Esi\EsiClientInterface;
use Illuminate\Support\Facades\Log;

/**
 * Creates (first time) or refreshes a ViewerContext row for a single
 * Character, and runs viewer-bloc inference against that character's
 * current corp/alliance.
 *
 * Called on:
 *   - first render of /account/settings for a character with no
 *     viewer_context yet (lazy-create);
 *   - the onboarding "Re-infer" action if the donor wants to rerun
 *     inference after labels were updated upstream;
 *   - later: ESI affiliation sync when a character changes corp.
 *
 * Confirmation semantics:
 *
 *   - A NEW viewer_context is created with bloc_unresolved=true and
 *     inference runs once. The onboarding surface prompts the donor
 *     to confirm or pick.
 *   - An EXISTING confirmed viewer_context (bloc_unresolved=false) is
 *     NEVER re-inferred here — the donor's confirmed choice wins.
 *     The only thing this action refreshes on a confirmed row is the
 *     cached viewer_corporation_id / viewer_alliance_id.
 *   - An EXISTING unconfirmed viewer_context is re-inferred ONLY when
 *     `$forceReinfer` is true (i.e. the donor clicked "Re-infer" on
 *     the onboarding UI). Page renders pass `$forceReinfer=false` so
 *     inference doesn't run on every render.
 *
 * Return: the persisted ViewerContext instance (fresh from DB).
 */
final class SyncViewerContextForCharacter
{
    public function __construct(
        private readonly ViewerBlocInferenceService $inference,
        private readonly EsiClientInterface $esi,
    ) {}

    public function handle(Character $character, bool $forceReinfer = false): ViewerContext
    {
        $context = ViewerContext::query()
            ->where('character_id', $character->id)
            ->first();

        $isNew = $context === null;

        if ($isNew) {
            $context = new ViewerContext([
                'character_id' => $character->id,
                'viewer_corporation_id' => $character->corporation_id,
                'viewer_alliance_id' => $character->alliance_id,
                'bloc_unresolved' => true,
                'is_active' => true,
            ]);
        } else {
            // Refresh cached affiliation if it drifted. Cheap guard:
            // don't bump updated_at when nothing changed.
            $context->viewer_corporation_id = $character->corporation_id;
            $context->viewer_alliance_id = $character->alliance_id;
        }

        // Inference runs on creation, or on explicit re-infer for
        // unconfirmed rows. Never on confirmed rows; never on every
        // page render.
        if ($isNew || ($forceReinfer && $context->bloc_unresolved === true)) {
            // Cold-start guard: SSO login rows initially only have
            // character_id + name. If affiliation mirroring hasn't run yet,
            // fetch corporation/alliance once so inference has labels to
            // match against. Non-fatal: unresolved is still a valid state.
            if ($character->corporation_id === null) {
                $this->fetchAndPersistAffiliation($character);
                $context->viewer_corporation_id = $character->corporation_id;
                $context->viewer_alliance_id = $character->alliance_id;
            }

            $result = $this->inference->infer($this->buildInput($character));
            $context->bloc_id = $result->blocId;
            $context->bloc_confidence_band = $result->confidenceBand;
            // Resolved inference still leaves bloc_unresolved=true
            // until the donor confirms. The confidence band tells the
            // UI how strongly to phrase the suggestion.
            $context->bloc_unresolved = true;
            $context->last_recomputed_at = now();
        }

        if ($isNew || $context->isDirty()) {
            $context->save();
        }

        return $context->refresh();
    }

    /**
     * Fetch character affiliation from public ESI and persist it to the
     * local character row. Best-effort; failures are logged and caller
     * continues with unresolved inference.
     */
    private function fetchAndPersistAffiliation(Character $character): void
    {
        try {
            $base = (string) config('eve.esi.new_base_url', 'https://esi.evetech.net');
            $url = rtrim($base, '/').'/characters/'.((int) $character->character_id).'/';
            $compatDate = (string) config('eve.esi.compat_date', '2025-12-16');

            $response = $this->esi->get($url, headers: ['X-Compatibility-Date' => $compatDate]);
            $body = $response->body ?? [];
            $corpId = isset($body['corporation_id']) ? (int) $body['corporation_id'] : null;
            $allianceId = isset($body['alliance_id']) ? (int) $body['alliance_id'] : null;

            if ($corpId !== null) {
                $character->corporation_id = $corpId;
                $character->alliance_id = $allianceId;
                $character->save();

                Log::info('viewer-bloc: character affiliation fetched from ESI', [
                    'character_id' => $character->character_id,
                    'corporation_id' => $corpId,
                    'alliance_id' => $allianceId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('viewer-bloc: ESI affiliation fetch failed (inference will be unresolved)', [
                'character_id' => $character->character_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Loads the label universe the inference service needs in a single
     * DB round-trip — all active labels on the viewer's alliance AND
     * corporation, pre-ordered by relationship display_order so the
     * service's "first per bloc wins" logic honours the relationship
     * hierarchy (member > affiliate > logistics > renter).
     */
    private function buildInput(Character $character): ViewerBlocInferenceInput
    {
        $query = CoalitionEntityLabel::query()->where('is_active', true);

        $query->where(function ($q) use ($character): void {
            if ($character->alliance_id !== null) {
                $q->orWhere(function ($qq) use ($character): void {
                    $qq->where('entity_type', CoalitionEntityLabel::ENTITY_ALLIANCE)
                        ->where('entity_id', $character->alliance_id);
                });
            }
            if ($character->corporation_id !== null) {
                $q->orWhere(function ($qq) use ($character): void {
                    $qq->where('entity_type', CoalitionEntityLabel::ENTITY_CORPORATION)
                        ->where('entity_id', $character->corporation_id);
                });
            }
            // Neither — the closure has no predicates, which would
            // select every active label. Short-circuit with a
            // never-true clause.
            if ($character->alliance_id === null && $character->corporation_id === null) {
                $q->whereRaw('1 = 0');
            }
        });

        $labels = $query
            ->leftJoin('coalition_relationship_types', 'coalition_entity_labels.relationship_type_id', '=', 'coalition_relationship_types.id')
            ->orderBy('coalition_relationship_types.display_order')
            ->select('coalition_entity_labels.*')
            ->get();

        return new ViewerBlocInferenceInput(
            viewerAllianceId: $character->alliance_id,
            viewerCorporationId: $character->corporation_id,
            labels: $labels,
        );
    }
}
