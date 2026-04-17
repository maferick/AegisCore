<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Domains\KillmailsBattleTheaters\Models\CorporationAllianceHistory;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\Esi\EsiRateLimitException;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill `corporation_alliance_history` for every corp we've seen on
 * a killmail. Mirrors {@see FetchCharacterCorporationHistory} one level
 * up: "which alliance was this corp in at time Y?" — the killmail
 * detail view uses the answer to flag "(now: Z)" when the corp's
 * alliance on the kill differs from its alliance today.
 *
 * Pulls from ESI /corporations/{id}/alliancehistory/ (public, unauthed,
 * 1-day cache by CCP) through the shared rate-limited client.
 *
 * Processes BATCH_SIZE corps per dispatch; self-dispatches until
 * caught up. The numerator is ~20k active + ~80k dead player corps,
 * so a handful of dispatches closes the gap.
 */
final class FetchCorporationAllianceHistory implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const BATCH_SIZE = 500;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function handle(EsiClientInterface $esi): void
    {
        $uncached = $this->findUncachedCorporations();

        if ($uncached === []) {
            Log::info('fetch-corp-alliance-history: all caught up');
            return;
        }

        $fetched = 0;
        $failed = 0;

        foreach ($uncached as $corporationId) {
            try {
                $this->fetchAndPersist($esi, $corporationId);
                $fetched++;
            } catch (EsiRateLimitException $e) {
                Log::warning('fetch-corp-alliance-history: rate limited, stopping batch', [
                    'corporation_id' => $corporationId,
                    'retry_after' => $e->retryAfter,
                ]);
                break;
            } catch (EsiException $e) {
                if ($e->status === 404) {
                    // Dead corp — placeholder row so we stop retrying.
                    CorporationAllianceHistory::updateOrCreate(
                        ['corporation_id' => $corporationId, 'record_id' => 0],
                        [
                            'alliance_id' => null,
                            'start_date' => Carbon::createFromTimestamp(0),
                            'is_deleted' => true,
                            'fetched_at' => now(),
                        ],
                    );
                    $fetched++;
                } else {
                    $failed++;
                    Log::warning('fetch-corp-alliance-history: ESI error', [
                        'corporation_id' => $corporationId,
                        'status' => $e->status,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('fetch-corp-alliance-history: unexpected error', [
                    'corporation_id' => $corporationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('fetch-corp-alliance-history: batch complete', [
            'fetched' => $fetched,
            'failed' => $failed,
            'batch_size' => count($uncached),
        ]);

        if (count($uncached) >= self::BATCH_SIZE) {
            static::dispatch()->delay(now()->addSeconds(3));
        }
    }

    /**
     * @return list<int>
     */
    private function findUncachedCorporations(): array
    {
        // Any corp that shows up on killmails (victim side OR attacker
        // side) and doesn't have a history row yet.
        return DB::table('killmails')
            ->select('victim_corporation_id as corp_id')
            ->whereNotNull('victim_corporation_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('corporation_alliance_history')
                    ->whereColumn('corporation_alliance_history.corporation_id', 'killmails.victim_corporation_id');
            })
            ->union(
                DB::table('killmail_attackers')
                    ->select('corporation_id as corp_id')
                    ->whereNotNull('corporation_id')
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('corporation_alliance_history')
                            ->whereColumn('corporation_alliance_history.corporation_id', 'killmail_attackers.corporation_id');
                    })
            )
            ->groupBy('corp_id')
            ->limit(self::BATCH_SIZE)
            ->pluck('corp_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function fetchAndPersist(EsiClientInterface $esi, int $corporationId): void
    {
        $newBaseUrl = (string) config('eve.esi.new_base_url', 'https://esi.evetech.net');
        $compatDate = (string) config('eve.esi.compat_date', '2025-12-16');

        $url = rtrim($newBaseUrl, '/')."/corporations/{$corporationId}/alliancehistory/";

        $response = $esi->get($url, headers: [
            'X-Compatibility-Date' => $compatDate,
        ]);

        $records = $response->body;
        if (! is_array($records) || $records === []) {
            // Some corps have never been in an alliance — record a
            // "never in an alliance" placeholder so we don't keep
            // re-fetching.
            CorporationAllianceHistory::updateOrCreate(
                ['corporation_id' => $corporationId, 'record_id' => 0],
                [
                    'alliance_id' => null,
                    'start_date' => Carbon::createFromTimestamp(0),
                    'is_deleted' => false,
                    'fetched_at' => now(),
                ],
            );
            return;
        }

        usort($records, fn ($a, $b) => ($a['record_id'] ?? 0) <=> ($b['record_id'] ?? 0));

        $now = now();
        $rows = [];

        for ($i = 0; $i < count($records); $i++) {
            $rec = $records[$i];
            $startDate = Carbon::parse($rec['start_date']);
            $endDate = isset($records[$i + 1])
                ? Carbon::parse($records[$i + 1]['start_date'])
                : null;

            $rows[] = [
                'corporation_id' => $corporationId,
                'alliance_id' => isset($rec['alliance_id']) ? (int) $rec['alliance_id'] : null,
                'record_id' => (int) $rec['record_id'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_deleted' => (bool) ($rec['is_deleted'] ?? false),
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('corporation_alliance_history')->upsert(
            $rows,
            ['corporation_id', 'record_id'],
            ['alliance_id', 'start_date', 'end_date', 'is_deleted', 'fetched_at', 'updated_at'],
        );
    }
}
