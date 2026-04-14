<?php

declare(strict_types=1);

namespace App\Domains\Markets\Services;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Reference\Models\SolarSystem;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\MarketTokenAuthorizer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Powers the `/account/settings` structure picker.
 *
 * ADR-0004 § Structure access is alliance/corp-gated: structure
 * *discovery* is ACL-gated. The picker is a thin wrapper around the
 * donor's own `GET /characters/{id}/search/?categories=structure`
 * — ESI only returns IDs the donor's character can see. The system
 * never accepts free-form structure IDs; the only path for an ID to
 * enter `market_watched_locations` is via a search response from
 * the donor's own token within the same request lifetime.
 *
 * Two responsibilities:
 *
 *   1. `search(EveMarketToken, query)` — ESI search, return
 *      structure IDs the character has ACLs at.
 *   2. `resolve(EveMarketToken, ids)` — `GET /universe/structures/{id}/`
 *      for each, plus a `ref_solar_systems` join for `region_id`
 *      (the structure endpoint returns `solar_system_id` but not
 *      `region_id`). Returns an array of structured candidates
 *      the UI can display.
 *
 * Network costs: a 5-character query might return ~20 structure IDs;
 * we then resolve each one with an individual `/universe/structures/`
 * call. That's moderately expensive (10-20 ESI calls per picker
 * search), but:
 *
 *   - ESI caches `/universe/structures/{id}/` for 1 hour; our
 *     CachedEsiClient decorator replays cached bodies.
 *   - Individual calls respect the shared rate-limit budget.
 *   - The picker is user-initiated, low-cadence (a donor adds a
 *     few structures once, not continuously).
 *
 * If this becomes a hotspot, the right fix is a per-result-ID
 * caching layer keyed on `structure_id` + weekly TTL — matches the
 * ADR's "refresh weekly" cadence for name resolution.
 */
final class StructurePickerService
{
    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly MarketTokenAuthorizer $authorizer,
    ) {}

    /**
     * Search ESI for structures whose names match `$query`, using the
     * donor's own token (restricts results to structures they have
     * ACLs at).
     *
     * Returns resolved candidates:
     *
     *     [
     *         [
     *             'structure_id' => 1035466617946,
     *             'name' => 'Perimeter - Tranquility Trading Tower',
     *             'solar_system_id' => 30000144,
     *             'region_id' => 10000002,
     *             'system_name' => 'Perimeter',
     *         ],
     *         ...
     *     ]
     *
     * @return list<array<string, mixed>>
     */
    public function search(EveMarketToken $token, string $query): array
    {
        $query = trim($query);
        if (strlen($query) < 3) {
            // ESI's /characters/{id}/search/ rejects short queries
            // with HTTP 400. Surface an early empty result rather
            // than round-trip to CCP.
            return [];
        }

        $accessToken = $this->authorizer->freshAccessToken($token);

        try {
            $response = $this->esi->get(
                "/characters/{$token->character_id}/search/",
                query: ['categories' => 'structure', 'search' => $query],
                bearerToken: $accessToken,
            );
        } catch (EsiException $e) {
            Log::warning('structure search failed', [
                'user_id' => $token->user_id,
                'character_id' => $token->character_id,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Structure search failed. If this persists, re-authorise market data on your account.',
                previous: $e,
            );
        }

        $body = $response->body ?? [];
        $structureIds = array_values(array_map(
            static fn ($id) => (int) $id,
            (array) ($body['structure'] ?? []),
        ));
        if ($structureIds === []) {
            return [];
        }

        return $this->resolve($token, $structureIds, accessToken: $accessToken);
    }

    /**
     * Resolve a set of structure IDs into structured candidates.
     * Separately callable so "add by ID" flows (future) can share
     * the same resolve path as the search flow.
     *
     * We tolerate individual 403/404s from `/universe/structures/{id}/`
     * by dropping that candidate from the result list — it means
     * the character lost access between the search response and this
     * resolve (unlikely in the same second, but defensive).
     *
     * @param  array<int, int>  $structureIds
     * @return list<array<string, mixed>>
     */
    public function resolve(EveMarketToken $token, array $structureIds, ?string $accessToken = null): array
    {
        if ($structureIds === []) {
            return [];
        }

        $accessToken ??= $this->authorizer->freshAccessToken($token);

        // Dedupe + cap — unlikely ESI ever returns >50 matches, but
        // guard against adversarial inputs on future "add by ID" paths.
        $structureIds = array_values(array_unique(array_map('intval', $structureIds)));
        $structureIds = array_slice($structureIds, 0, 50);

        $candidates = [];
        $systemIds = [];
        foreach ($structureIds as $id) {
            try {
                $resp = $this->esi->get(
                    "/universe/structures/{$id}/",
                    bearerToken: $accessToken,
                );
            } catch (EsiException $e) {
                Log::info('structure resolve failed, skipping candidate', [
                    'structure_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $body = $resp->body ?? [];
            $name = (string) ($body['name'] ?? '');
            $systemId = isset($body['solar_system_id']) ? (int) $body['solar_system_id'] : null;
            if ($name === '' || $systemId === null) {
                // Incomplete response — treat as unresolvable.
                continue;
            }

            $candidates[$id] = [
                'structure_id' => $id,
                'name' => $name,
                'solar_system_id' => $systemId,
                // `region_id` + `system_name` are filled in below via a
                // single ref_solar_systems join (one SQL rather than N).
                'region_id' => null,
                'system_name' => null,
            ];
            $systemIds[] = $systemId;
        }

        if ($candidates === []) {
            return [];
        }

        $systems = SolarSystem::query()
            ->whereIn('id', array_unique($systemIds))
            ->get(['id', 'region_id', 'name'])
            ->keyBy('id');

        foreach ($candidates as $id => $c) {
            $system = $systems->get($c['solar_system_id']);
            if ($system === null) {
                // SDE not yet imported, or a structure in a system we
                // don't have reference data for. Skip.
                unset($candidates[$id]);
                continue;
            }
            $candidates[$id]['region_id'] = (int) $system->region_id;
            $candidates[$id]['system_name'] = (string) $system->name;
        }

        return array_values($candidates);
    }
}
