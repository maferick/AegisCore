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
    /**
     * Entity names in EVE are effectively immutable for killmail
     * enrichment — characters, corps, alliances rename rarely enough
     * that we treat a cached row as valid forever. The docblock above
     * already promises "no hard TTL"; a prior 7-day stale threshold
     * was silently re-hitting ESI weekly during backlog drains and
     * became a major enrichment bottleneck. Keep cached_at for audit
     * (a batch job can re-resolve specific IDs by passing
     * forceRefresh=true), but do not invalidate on read.
     */
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

        // 1. Check DB cache (unless force refresh). No TTL filter —
        // entity names are immutable for enrichment purposes.
        if (! $forceRefresh) {
            $cached = EsiEntityName::query()
                ->whereIn('entity_id', $ids)
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
                // CCP returns 404 for the ENTIRE batch if even one ID
                // is invalid (deleted character, disbanded corp, typo
                // faction id). Without bisection we'd lose every
                // valid name in the chunk too — on a backfill that's
                // thousands of real names dropped per run. Bisect
                // until chunks are small enough to isolate the dead
                // IDs, keeping the 404-free halves.
                $message = $e->getMessage();
                $is404 = str_contains($message, 'HTTP 404');

                if ($is404 && count($chunk) > 1) {
                    $recovered = $this->bisectRetry(array_values($chunk));
                    if ($recovered !== []) {
                        array_push($all, ...$recovered);
                    }

                    continue;
                }

                Log::warning('ESI /universe/names/ failed', [
                    'chunk_size' => count($chunk),
                    'error' => $message,
                ]);
            }
        }

        return $all;
    }

    /**
     * Retry a 404'd chunk by splitting it in half. Recurses down to
     * size 1; at size 1 a 404 is logged (the single ID is genuinely
     * unresolvable) and skipped, so the caller never sees the bad ID.
     * Every other ID in the original chunk is preserved.
     *
     * @param  array<int, int>  $chunk
     * @return list<array{id: int, name: string, category: string}>
     */
    private function bisectRetry(array $chunk): array
    {
        if (count($chunk) === 1) {
            try {
                $response = $this->esiClient->post('/universe/names/', $chunk);
                $body = $response->body;

                return is_array($body) ? $body : [];
            } catch (\Throwable) {
                // Single unresolvable ID — CCP has no record of it.
                return [];
            }
        }

        $half = intdiv(count($chunk), 2);
        $left = array_slice($chunk, 0, $half);
        $right = array_slice($chunk, $half);

        $out = [];
        foreach ([$left, $right] as $part) {
            try {
                $response = $this->esiClient->post('/universe/names/', $part);
                $body = $response->body;
                if (is_array($body)) {
                    array_push($out, ...$body);
                }
            } catch (\Throwable $e) {
                if (count($part) > 1 && str_contains($e->getMessage(), 'HTTP 404')) {
                    $out = array_merge($out, $this->bisectRetry($part));
                }
                // Non-404 or single-ID failure: drop silently, bad IDs
                // should not poison the rest of the batch.
            }
        }

        return $out;
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
