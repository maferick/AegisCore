<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Domains\KillmailsBattleTheaters\Models\CharacterCorporationHistory;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\Esi\EsiRateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fetches corporation history for characters observed in killmails.
 *
 * Scans for character IDs that don't have history cached yet, then
 * fetches ESI GET /characters/{id}/corporationhistory/ (public, unauthed,
 * 1-day cache by CCP) through the rate-limited ESI client.
 *
 * Processes BATCH_SIZE characters per dispatch, self-dispatches if more
 * remain. Derives end_date from the next record's start_date.
 */
final class FetchCharacterCorporationHistory implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Characters to fetch per dispatch.
     *
     * Bumped from 50 to 500 after the 2026-04-17 pipeline-health audit
     * showed 97.5% of killmail characters had no history (12,993 of
     * 524,959). At 50 / 5 min the backfill was a 36-day wall clock;
     * 500 / 5 min drops that to ~3 days while staying well under
     * CCP's public-endpoint rate cap (~100 req/sec with UA) because
     * the shared ESI client paces per-request internally, a 60-sec
     * batch timeout per dispatch, and the EsiRateLimitException
     * hard-break still short-circuits any burst.
     */
    private const BATCH_SIZE = 500;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /**
     * Shard fan-out for parallelism. Each shard picks characters where
     * `character_id % $shardCount === $shardId` so N concurrent copies
     * drain disjoint slices of the uncached set without racing on the
     * same rows. uniqueId() includes the shard so Laravel's ShouldBeUnique
     * keeps within-shard serial (fair — one slice at a time) while
     * letting distinct shards run in parallel.
     *
     * Properties declared with explicit defaults so pre-existing queued
     * jobs (serialized before this field existed) unserialize cleanly
     * as single-shard dispatches.
     */
    public int $shardId = 0;
    public int $shardCount = 1;

    public function __construct(int $shardId = 0, int $shardCount = 1)
    {
        $this->shardId = $shardId;
        $this->shardCount = $shardCount;
    }

    public function uniqueId(): string
    {
        return sprintf('corp-history:%d/%d', $this->shardId, $this->shardCount);
    }

    public function handle(EsiClientInterface $esi): void
    {
        // Find character IDs from killmails that don't have history yet.
        $uncached = $this->findUncachedCharacters();

        if ($uncached === []) {
            Log::info('fetch-corp-history: all caught up');

            return;
        }

        $fetched = 0;
        $failed = 0;

        foreach ($uncached as $characterId) {
            try {
                $this->fetchAndPersist($esi, $characterId);
                $fetched++;
            } catch (EsiRateLimitException $e) {
                Log::warning('fetch-corp-history: rate limited, stopping batch', [
                    'character_id' => $characterId,
                    'retry_after' => $e->retryAfter,
                ]);
                break;
            } catch (EsiException $e) {
                // 404 = character doesn't exist (biomassed/deleted). Skip.
                if ($e->status === 404) {
                    // Insert a placeholder so we don't retry.
                    CharacterCorporationHistory::updateOrCreate(
                        ['character_id' => $characterId, 'record_id' => 0],
                        [
                            'corporation_id' => 0,
                            'start_date' => Carbon::createFromTimestamp(0),
                            'is_deleted' => true,
                            'fetched_at' => now(),
                        ],
                    );
                    $fetched++;
                } else {
                    $failed++;
                    Log::warning('fetch-corp-history: ESI error', [
                        'character_id' => $characterId,
                        'status' => $e->status,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('fetch-corp-history: unexpected error', [
                    'character_id' => $characterId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('fetch-corp-history: batch complete', [
            'fetched' => $fetched,
            'failed' => $failed,
            'batch_size' => count($uncached),
        ]);

        // Self-dispatch to keep the shard draining between scheduler
        // ticks — a full batch means more work remains. Critical that
        // shardId/shardCount propagate so the follow-up lands in the
        // same partition (earlier bug: args-less static::dispatch()
        // spawned shardCount=1 orphans that bypassed the 8-shard split
        // and piled up in the queue). uniqueId() is per-shard, so the
        // 3-second delay + ShouldBeUnique combo dedupes cleanly as
        // the current job exits.
        if (count($uncached) >= self::BATCH_SIZE) {
            static::dispatch($this->shardId, $this->shardCount)
                ->delay(now()->addSeconds(3));
        }
    }

    /**
     * Find character IDs from killmails that don't have corp history cached.
     *
     * Unions victim_character_id and killmail_attackers.character_id — the
     * pipeline-health probe uses the same union as its denominator, so
     * draining only the victim side leaves attacker-only pilots forever
     * uncached (2026-04-20 stall at 95%: 9 uncached victims vs 28,376
     * uncached attackers).
     *
     * @return list<int>
     */
    private function findUncachedCharacters(): array
    {
        $shardFilter = '';
        $shardBind = [];
        if ($this->shardCount > 1) {
            $shardFilter = ' AND cid % ? = ?';
            $shardBind = [$this->shardCount, $this->shardId];
        }

        $sql = <<<SQL
            SELECT cid FROM (
                SELECT DISTINCT victim_character_id AS cid
                  FROM killmails
                 WHERE victim_character_id IS NOT NULL
                UNION
                SELECT DISTINCT character_id AS cid
                  FROM killmail_attackers
                 WHERE character_id IS NOT NULL
            ) u
            WHERE NOT EXISTS (
                SELECT 1 FROM character_corporation_history h
                 WHERE h.character_id = u.cid
            ){$shardFilter}
            LIMIT ?
        SQL;

        $rows = DB::select($sql, array_merge($shardBind, [self::BATCH_SIZE]));
        return array_map(fn ($r) => (int) $r->cid, $rows);
    }

    /**
     * Fetch and persist corporation history for one character.
     */
    private function fetchAndPersist(EsiClientInterface $esi, int $characterId): void
    {
        $newBaseUrl = (string) config('eve.esi.new_base_url', 'https://esi.evetech.net');
        $compatDate = (string) config('eve.esi.compat_date', '2025-12-16');

        $url = rtrim($newBaseUrl, '/')."/characters/{$characterId}/corporationhistory/";

        $response = $esi->get($url, headers: [
            'X-Compatibility-Date' => $compatDate,
        ]);

        $records = $response->body;

        if (! is_array($records) || $records === []) {
            return;
        }

        // Sort by record_id ascending to derive end_dates.
        usort($records, fn ($a, $b) => ($a['record_id'] ?? 0) <=> ($b['record_id'] ?? 0));

        $now = now();
        $rows = [];

        for ($i = 0; $i < count($records); $i++) {
            $rec = $records[$i];
            $startDate = Carbon::parse($rec['start_date']);

            // end_date = next record's start_date, or null if current corp.
            $endDate = isset($records[$i + 1])
                ? Carbon::parse($records[$i + 1]['start_date'])
                : null;

            $rows[] = [
                'character_id' => $characterId,
                'corporation_id' => (int) $rec['corporation_id'],
                'record_id' => (int) $rec['record_id'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_deleted' => (bool) ($rec['is_deleted'] ?? false),
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Upsert — safe to re-fetch.
        DB::table('character_corporation_history')->upsert(
            $rows,
            ['character_id', 'record_id'],
            ['corporation_id', 'start_date', 'end_date', 'is_deleted', 'fetched_at', 'updated_at'],
        );
    }
}
