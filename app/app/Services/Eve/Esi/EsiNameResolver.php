<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use App\Models\EsiEntityName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared, DB-backed entity name resolver via ESI POST /universe/names/.
 *
 * Consolidates the duplicated postUniverseNames() calls from
 * CharacterStandingsFetcher, PollDonationsWallet, and
 * CoalitionEntityLabelResource into one service that:
 *
 *   1. Checks the `esi_entity_names` cache table first.
 *   2. Batch-resolves only missing IDs via ESI.
 *   3. Upserts all results back into the cache table.
 *   4. Returns the full resolved map (cache + fresh).
 *
 * Callers get `array<int, array{name: string, category: string}>` keyed
 * by entity ID. Failures are logged and silently skipped — callers should
 * tolerate missing names rather than failing their main operation.
 *
 * Cache entries have no hard TTL — entity names in EVE rarely change.
 * A `cached_at` timestamp is stored so callers or batch jobs can refresh
 * stale entries if needed.
 */
class EsiNameResolver
{
    private const STALE_AFTER_SECONDS = 604_800;

    private const ESI_BATCH_LIMIT = 1000;

    public function __construct(
        private readonly EsiClientInterface $esiClient,
    ) {}

    /**
     * Resolve one or more CCP entity IDs to names.
     *
     * @param  array<int, int>  $ids  CCP entity IDs to resolve.
     * @param  bool  $forceRefresh  Bypass the cache and always call ESI.
     * @return array<int, array{name: string, category: string}>  Keyed by entity ID.
     */
    public function resolve(array $ids, bool $forceRefresh = false): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $result = [];
        $missing = $ids;

        // 1. Check DB cache (unless force refresh).
        if (! $forceRefresh) {
            $staleThreshold = Carbon::now()->subSeconds(self::STALE_AFTER_SECONDS);

            $cached = EsiEntityName::query()
                ->whereIn('entity_id', $ids)
                ->where('cached_at', '>=', $staleThreshold)
                ->get();

            foreach ($cached as $row) {
                $result[$row->entity_id] = [
                    'name' => $row->name,
                    'category' => $row->category,
                ];
            }

            $cachedIds = array_keys($result);
            $missing = array_values(array_diff($ids, $cachedIds));
        }

        // 2. Batch-resolve missing IDs via ESI.
        if ($missing !== []) {
            $fresh = $this->fetchFromEsi($missing);

            // 3. Upsert into cache table.
            if ($fresh !== []) {
                $this->upsertCache($fresh);
            }

            foreach ($fresh as $entry) {
                $id = (int) ($entry['id'] ?? 0);
                $name = (string) ($entry['name'] ?? '');
                $category = (string) ($entry['category'] ?? '');

                if ($id > 0 && $name !== '') {
                    $result[$id] = [
                        'name' => $name,
                        'category' => $category,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Resolve a single entity ID. Convenience wrapper.
     *
     * @return array{name: string, category: string}|null
     */
    public function resolveOne(int $id): ?array
    {
        $result = $this->resolve([$id]);

        return $result[$id] ?? null;
    }

    /**
     * Resolve IDs and return a simple id → name map.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>  Keyed by entity ID, value is name.
     */
    public function resolveNames(array $ids): array
    {
        $resolved = $this->resolve($ids);

        return array_map(fn (array $entry) => $entry['name'], $resolved);
    }

    /**
     * Call ESI POST /universe/names/ in chunks of 1000.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, name: string, category: string}>
     */
    /**
     * Call ESI POST /universe/names/ via the shared EsiClient.
     *
     * Goes through the same rate limiter, User-Agent, and error handling
     * as every other ESI call in the platform.
     */
    private function fetchFromEsi(array $ids): array
    {
        $all = [];

        foreach (array_chunk($ids, self::ESI_BATCH_LIMIT) as $chunk) {
            try {
                $response = $this->esiClient->post(
                    '/universe/names/',
                    array_values($chunk),
                );

                $body = $response->body;
                if (is_array($body)) {
                    array_push($all, ...$body);
                }
            } catch (EsiRateLimitException $e) {
                Log::warning('ESI /universe/names/ rate limited', [
                    'chunk_size' => count($chunk),
                    'retry_after' => $e->retryAfter,
                ]);
                // Stop hitting ESI — the rate limiter will block further calls.
                break;
            } catch (\Throwable $e) {
                Log::warning('ESI /universe/names/ failed', [
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $all;
    }

    /**
     * Upsert resolved entries into the esi_entity_names cache table.
     *
     * @param  array<int, array{id: int, name: string, category: string}>  $entries
     */
    private function upsertCache(array $entries): void
    {
        $now = Carbon::now();
        $rows = [];

        foreach ($entries as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $name = (string) ($entry['name'] ?? '');
            $category = (string) ($entry['category'] ?? '');

            if ($id > 0 && $name !== '') {
                $rows[] = [
                    'entity_id' => $id,
                    'name' => $name,
                    'category' => $category,
                    'cached_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        // Batch upsert — update name/category/cached_at on conflict.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('esi_entity_names')->upsert(
                $chunk,
                ['entity_id'],
                ['name', 'category', 'cached_at'],
            );
        }
    }
}
