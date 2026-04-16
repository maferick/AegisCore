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
     * Sentinel category for IDs CCP refuses to resolve (deleted
     * characters, disbanded corps, etc). Tombstoning them in the
     * cache table prevents repeated bisection work — once we
     * confirm an ID is unresolvable, we mark it and skip forever.
     * Consumers that display names already tolerate missing names;
     * this sentinel is specifically for the resolver's own
     * "is it cached?" check.
     */
    public const CATEGORY_UNRESOLVED = 'unresolved';

    /**
     * Resolve one or more CCP entity IDs to names.
     *
     * @param  array<int, int>  $ids  CCP entity IDs to resolve.
     * @param  bool  $forceRefresh  Bypass the cache and always call ESI.
     * @param  array<int, int>|null  $characterIds  Optional subset of
     *     $ids that the caller knows are character IDs. When
     *     provided, we pre-filter them through POST
     *     /characters/affiliation/ — that endpoint silently drops
     *     invalid character IDs instead of 404'ing the whole batch
     *     (unlike /universe/names/). Characters removed by the
     *     affiliation filter get tombstoned so we never retry them.
     *     The surviving character IDs + the remaining non-character
     *     IDs go to /universe/names/ for actual name resolution.
     *     Dramatically reduces 404 cascades when backfilling against
     *     killmails full of biomassed or deleted characters.
     * @return array<int, array{name: string, category: string}>  Keyed by entity ID.
     */
    public function resolve(array $ids, bool $forceRefresh = false, ?array $characterIds = null): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $result = [];
        $missing = $ids;

        // 1. Check DB cache (unless force refresh). No TTL filter —
        // entity names are immutable for enrichment purposes.
        // Tombstoned (category='unresolved') rows count as cached: we
        // remove them from $result before returning (callers don't
        // want to render "unresolved" strings) but we keep them out
        // of $missing so we don't repeat the ESI round-trip.
        if (! $forceRefresh) {
            $cached = EsiEntityName::query()
                ->whereIn('entity_id', $ids)
                ->get();

            foreach ($cached as $row) {
                if ($row->category !== self::CATEGORY_UNRESOLVED) {
                    $result[$row->entity_id] = [
                        'name' => $row->name,
                        'category' => $row->category,
                    ];
                }
            }

            $cachedIds = $cached->pluck('entity_id')->all();
            $missing = array_values(array_diff($ids, $cachedIds));
        }

        // 2. Pre-filter character IDs through /characters/affiliation/
        //    so /universe/names/ never sees a deleted character and
        //    404s the whole batch. IDs the caller labelled as
        //    characters but aren't in the affiliation response are
        //    tombstoned — they're deleted and will never resolve.
        if ($missing !== [] && $characterIds !== null) {
            $pendingChars = array_values(array_intersect($missing, $characterIds));
            if ($pendingChars !== []) {
                $valid = $this->filterValidCharacters($pendingChars);
                $invalid = array_values(array_diff($pendingChars, $valid));

                if ($invalid !== []) {
                    $this->tombstoneIds($invalid);
                    $missing = array_values(array_diff($missing, $invalid));
                }
            }
        }

        // 3. Batch-resolve remaining missing IDs via ESI.
        if ($missing !== []) {
            $fresh = $this->fetchFromEsi($missing);

            // 4. Upsert into cache table.
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
     * Call POST /characters/affiliation/ and return the subset of
     * input IDs that CCP still recognises as live characters.
     *
     * The endpoint tolerates non-character and invalid IDs silently —
     * it just omits them from the response, never 404s. That's the
     * property we need. Max 1000 IDs per call per CCP docs; we chunk
     * to be safe.
     *
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function filterValidCharacters(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $valid = [];
        foreach (array_chunk($ids, self::ESI_BATCH_LIMIT) as $chunk) {
            try {
                $response = $this->esiClient->post(
                    '/characters/affiliation/',
                    array_values($chunk),
                );

                $body = $response->body;
                if (is_array($body)) {
                    foreach ($body as $row) {
                        $id = (int) ($row['character_id'] ?? 0);
                        if ($id > 0) {
                            $valid[] = $id;
                        }
                    }
                }
            } catch (EsiRateLimitException $e) {
                Log::warning('ESI /characters/affiliation/ rate limited', [
                    'chunk_size' => count($chunk),
                    'retry_after' => $e->retryAfter,
                ]);
                // Conservative: on rate-limit, assume every input is
                // still valid so we don't tombstone real characters.
                // They'll be validated on the next run.
                $valid = array_merge($valid, array_values($chunk));
                break;
            } catch (\Throwable $e) {
                Log::warning('ESI /characters/affiliation/ failed', [
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
                // Same conservative behaviour on any other failure:
                // keep the chunk live rather than mass-tombstoning.
                $valid = array_merge($valid, array_values($chunk));
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * Write tombstone rows for IDs CCP refuses to resolve. Tombstone
     * consumers see them as cached and skip them forever, which
     * eliminates the worst-case bisection loop where the same dead
     * IDs re-enter the resolver on every pass.
     *
     * @param  array<int, int>  $ids
     */
    private function tombstoneIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $now = Carbon::now();
        $rows = [];
        foreach (array_unique($ids) as $id) {
            if ($id > 0) {
                $rows[] = [
                    'entity_id' => (int) $id,
                    'name' => '',
                    'category' => self::CATEGORY_UNRESOLVED,
                    'cached_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::table('esi_entity_names')->upsert(
            $rows,
            ['entity_id'],
            ['name', 'category', 'cached_at'],
        );
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

                // Single-ID 404 at the outer loop (caller passed a
                // tiny batch) — tombstone so we never try it again.
                if ($is404 && count($chunk) === 1) {
                    $this->tombstoneIds(array_values($chunk));

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
                // Tombstone so we don't bisect into this ID again on
                // the next pass.
                $this->tombstoneIds($chunk);

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
                $is404 = str_contains($e->getMessage(), 'HTTP 404');
                if ($is404 && count($part) > 1) {
                    $out = array_merge($out, $this->bisectRetry($part));
                } elseif ($is404 && count($part) === 1) {
                    // Isolated bad ID inside a bisecting branch —
                    // tombstone so the next pass doesn't re-bisect
                    // into it.
                    $this->tombstoneIds($part);
                }
                // Non-404 failure: drop silently; transient ESI
                // issues should not poison the rest of the batch.
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
