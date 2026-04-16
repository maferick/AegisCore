<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Services\Eve\Esi\EsiNameResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves entity names from killmail participants into the shared
 * esi_entity_names cache via ESI /universe/names/.
 *
 * Walks the `killmails` table by ascending killmail_id using a cursor
 * stored in Redis (`resolve-entity-names:cursor`). Each invocation
 * processes batches until its time budget is exhausted; the cursor
 * survives between invocations so progress is monotonic. On wrap
 * (cursor past MAX killmail_id), resets to 0 — any participant name
 * that got missed in the first pass, or any new participant from
 * fresh ingestion, gets a second chance without an operator touch.
 *
 * **Why no self-dispatch / ShouldBeUnique**: a prior implementation
 * used `ShouldBeUnique` + `static::dispatch($cursor)->delay(1s)` at
 * the end of handle(). Laravel's unique-lock middleware holds the
 * lock for the whole duration of handle(); the self-dispatch happens
 * BEFORE handle returns, so the lock is still held and Laravel
 * silently drops the new dispatch. The chain broke after the first
 * batch every time, so the scheduler's 2-minute ticks were the only
 * progress — and each one restarted at the beginning with
 * `afterKillmailId=null`. Result: the same first 200 killmails
 * re-scanned forever; the cache crawled from 1500 → 2000 over hours
 * while the table grew to 1.1M killmails. Fix is in-process looping
 * with a persistent cursor; no self-dispatch, no unique lock.
 */
final class ResolveEntityNames implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Killmails scanned per batch inside the loop. */
    private const KILLMAIL_BATCH = 500;

    /** Soft time budget per job invocation, seconds. */
    private const TIME_BUDGET_SECONDS = 50;

    /** Redis key for the persistent cursor. */
    private const CURSOR_KEY = 'resolve-entity-names:cursor';

    public int $tries = 3;

    /**
     * Hard timeout — leaves headroom above TIME_BUDGET_SECONDS for
     * an in-flight ESI round-trip + upsert to finish once the loop
     * exits.
     */
    public int $timeout = 120;

    public function handle(EsiNameResolver $resolver): void
    {
        $start = microtime(true);
        $cursor = (int) Cache::get(self::CURSOR_KEY, 0);

        $totalScanned = 0;
        $totalResolved = 0;
        $totalAlreadyCached = 0;
        $batches = 0;

        while (microtime(true) - $start < self::TIME_BUDGET_SECONDS) {
            $killmails = DB::table('killmails')
                ->select([
                    'killmail_id',
                    'victim_character_id',
                    'victim_corporation_id',
                    'victim_alliance_id',
                ])
                ->where('killmail_id', '>', $cursor)
                ->orderBy('killmail_id')
                ->limit(self::KILLMAIL_BATCH)
                ->get();

            if ($killmails->isEmpty()) {
                // Wrap to the start — any IDs that were uncached
                // during the previous pass (ESI failure, rate limit)
                // get retried, and any newly-ingested killmails at
                // lower-than-cursor IDs (shouldn't happen given
                // monotonic killmail_id, but defensive) are covered.
                Log::info('resolve-entity-names: wrap', [
                    'previous_cursor' => $cursor,
                    'batches' => $batches,
                    'resolved' => $totalResolved,
                    'already_cached' => $totalAlreadyCached,
                    'scanned' => $totalScanned,
                ]);
                Cache::put(self::CURSOR_KEY, 0);

                return;
            }

            $lastId = (int) $killmails->last()->killmail_id;
            $killmailIds = $killmails->pluck('killmail_id')->all();

            // Collect participant IDs bucketed by category. We keep
            // the character bucket separate so the resolver can
            // pre-filter it through /characters/affiliation/ (which
            // tolerates deleted characters) instead of 404'ing the
            // whole /universe/names/ batch on a single biomassed pilot.
            $characterIds = [];
            $otherIds = [];
            foreach ($killmails as $km) {
                if ($km->victim_character_id) {
                    $characterIds[] = (int) $km->victim_character_id;
                }
                foreach (['victim_corporation_id', 'victim_alliance_id'] as $col) {
                    if ($km->$col) {
                        $otherIds[] = (int) $km->$col;
                    }
                }
            }

            $attackers = DB::table('killmail_attackers')
                ->whereIn('killmail_id', $killmailIds)
                ->select(['character_id', 'corporation_id', 'alliance_id', 'faction_id'])
                ->get();

            foreach ($attackers as $att) {
                if ($att->character_id) {
                    $characterIds[] = (int) $att->character_id;
                }
                foreach (['corporation_id', 'alliance_id', 'faction_id'] as $col) {
                    if ($att->$col) {
                        $otherIds[] = (int) $att->$col;
                    }
                }
            }

            $characterIds = array_values(array_unique(array_filter($characterIds, fn (int $id) => $id > 0)));
            $otherIds = array_values(array_unique(array_filter($otherIds, fn (int $id) => $id > 0)));
            $uniqueIds = array_values(array_unique(array_merge($characterIds, $otherIds)));

            if ($uniqueIds !== []) {
                $cached = DB::table('esi_entity_names')
                    ->whereIn('entity_id', $uniqueIds)
                    ->pluck('entity_id')
                    ->flip();

                $uncached = [];
                foreach ($uniqueIds as $id) {
                    if (! $cached->has($id)) {
                        $uncached[] = $id;
                    }
                }

                $totalAlreadyCached += $cached->count();

                if ($uncached !== []) {
                    // Re-derive the character subset of the uncached
                    // IDs so the resolver can route them through
                    // /characters/affiliation/ for pre-validation.
                    $uncachedChars = array_values(array_intersect($uncached, $characterIds));
                    $resolved = $resolver->resolve($uncached, characterIds: $uncachedChars);
                    $totalResolved += count($resolved);
                }
            }

            $cursor = $lastId;
            Cache::put(self::CURSOR_KEY, $cursor);
            $batches++;
            $totalScanned += $killmails->count();
        }

        Log::info('resolve-entity-names: budget exhausted', [
            'cursor' => $cursor,
            'batches' => $batches,
            'scanned' => $totalScanned,
            'resolved' => $totalResolved,
            'already_cached' => $totalAlreadyCached,
            'duration_s' => round(microtime(true) - $start, 2),
        ]);
    }
}
