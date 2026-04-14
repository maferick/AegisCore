<?php

declare(strict_types=1);

namespace App\Domains\Markets\Services;

use App\Reference\Models\SolarSystem;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ESI-backed Upwell structure picker.
 *
 * Powers two surfaces:
 *
 *   - `/account/settings` donor picker — donor's own EveMarketToken
 *     via MarketTokenAuthorizer.
 *   - `/admin/market-watched-locations` admin create-form picker —
 *     the platform service character's EveServiceToken via
 *     ServiceTokenAuthorizer.
 *
 * ADR-0004 § Structure access is alliance/corp-gated: structure
 * *discovery* is ACL-gated. The picker is a thin wrapper around
 * `GET /characters/{id}/search/?categories=structure` — ESI only
 * returns IDs the character can see. The system never accepts
 * free-form structure IDs; the only path for an ID to enter
 * `market_watched_locations` / `market_hubs` is via a search
 * response from a live, authorised token within the same request
 * lifetime.
 *
 * Token-agnostic signature: callers pass `(characterId, accessToken)`
 * pairs, where the access token is known-fresh (the caller already
 * ran it through MarketTokenAuthorizer / ServiceTokenAuthorizer).
 * This service does not touch token rotation — that's the
 * authorizers' job — which keeps this class testable with a plain
 * ESI stub and no DB transactions.
 *
 * Two responsibilities:
 *
 *   1. `search($characterId, $accessToken, $query)` — ESI search,
 *      returns resolved structure candidates.
 *   2. `resolve($characterId, $accessToken, $ids)` — batch
 *      `/universe/structures/{id}/` + ref_solar_systems join for
 *      region_id / system_name. Separately callable so "add by
 *      known ID" flows (future) share one code path with search.
 *
 * Network costs: a 5-character query might return ~20 structure
 * IDs; we resolve each one individually. That's 10-20 ESI calls
 * per search. ESI caches `/universe/structures/{id}/` for 1 hour
 * and our CachedEsiClient decorator replays the body. The picker
 * is user-initiated and low-cadence — this is not a hot path.
 */
final class StructurePickerService
{
    public function __construct(
        private readonly EsiClientInterface $esi,
    ) {}

    /**
     * Search ESI for structures whose names match `$query`, scoped to
     * the character's ACLs (docking rights).
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
     * The query can be a structure name OR a system name like
     * "4-HWWF" — most staging Upwell structures embed their system
     * name in their display name ("4-HWWF - GSF Keepstar"), so the
     * structure-category search is usually enough. Enumerating
     * "all structures in system X" directly is not possible via
     * ESI — discovery is name-search-only.
     *
     * @return list<array<string, mixed>>
     */
    public function search(int $characterId, string $accessToken, string $query): array
    {
        $query = trim($query);
        if (strlen($query) < 3) {
            // ESI's /characters/{id}/search/ rejects short queries
            // with HTTP 400. Surface an early empty result rather
            // than round-trip to CCP.
            return [];
        }

        try {
            $response = $this->esi->get(
                "/characters/{$characterId}/search/",
                query: ['categories' => 'structure', 'search' => $query],
                bearerToken: $accessToken,
            );
        } catch (EsiException $e) {
            Log::warning('structure search failed', [
                'character_id' => $characterId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Structure search failed. If this persists, re-authorise the character whose token is in use.',
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

        return $this->resolve($characterId, $accessToken, $structureIds);
    }

    /**
     * Resolve a set of structure IDs into structured candidates.
     * Tolerates individual 403/404s by dropping the candidate —
     * the character may have lost access between search and resolve.
     *
     * @param  array<int, int>  $structureIds
     * @return list<array<string, mixed>>
     */
    public function resolve(int $characterId, string $accessToken, array $structureIds): array
    {
        if ($structureIds === []) {
            return [];
        }

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
                    'character_id' => $characterId,
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
