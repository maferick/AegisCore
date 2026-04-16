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

    /** Characters to fetch per dispatch. */
    private const BATCH_SIZE = 50;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

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

        // Self-dispatch if there are likely more.
        if (count($uncached) >= self::BATCH_SIZE) {
            static::dispatch()->delay(now()->addSeconds(3));
        }
    }

    /**
     * Find character IDs from killmails that don't have corp history cached.
     *
     * @return list<int>
     */
    private function findUncachedCharacters(): array
    {
        // Get distinct character IDs from recent killmail victims that
        // aren't in the history table yet.
        return DB::table('killmails')
            ->select('victim_character_id')
            ->whereNotNull('victim_character_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('character_corporation_history')
                    ->whereColumn('character_corporation_history.character_id', 'killmails.victim_character_id');
            })
            ->groupBy('victim_character_id')
            ->limit(self::BATCH_SIZE)
            ->pluck('victim_character_id')
            ->all();
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
