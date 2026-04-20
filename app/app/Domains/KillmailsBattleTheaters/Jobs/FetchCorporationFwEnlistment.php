<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiException;
use App\Services\Eve\Esi\EsiRateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refresh corporation_fw_enlistment from ESI /corporations/{id}/fw/stats/.
 *
 * Targets corps that appear on a killmail in the last 90 days and have
 * no row yet OR the row is older than STALE_DAYS. ESI responds with
 * either:
 *   - 200 {faction_id, enlisted_on, kills, victory_points}  → enlisted
 *   - 200 {}            → corp exists but never enlisted
 *   - 404               → corp not in FW or gone
 *
 * Either outcome lands a row (with is_enlisted=0 for the non-enlisted
 * cases) so the job stops retrying them every pass.
 */
final class FetchCorporationFwEnlistment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const BATCH_SIZE = 500;

    private const STALE_DAYS = 7;

    private const ACTIVE_WINDOW_DAYS = 90;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function handle(EsiClientInterface $esi): void
    {
        $targets = $this->findStaleCorporations();
        if ($targets === []) {
            Log::info('fetch-corp-fw-enlistment: all caught up');
            return;
        }

        $fetched = 0;
        $failed = 0;
        foreach ($targets as $corporationId) {
            try {
                $this->fetchAndPersist($esi, $corporationId);
                $fetched++;
            } catch (EsiRateLimitException $e) {
                Log::warning('fetch-corp-fw-enlistment: rate limited, stopping batch', [
                    'corporation_id' => $corporationId,
                    'retry_after' => $e->retryAfter,
                ]);
                break;
            } catch (EsiException $e) {
                // Empirically ESI returns 401 as well as 404 for corps
                // that aren't currently enlisted — the doc says public
                // but live behaviour treats "no record" as unauthorised.
                // Treat both as "not enlisted" and land a placeholder.
                if ($e->status === 404 || $e->status === 401 || $e->status === 403) {
                    $this->upsert($corporationId, null, null, []);
                    $fetched++;
                } else {
                    $failed++;
                    Log::warning('fetch-corp-fw-enlistment: ESI error', [
                        'corporation_id' => $corporationId,
                        'status' => $e->status,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('fetch-corp-fw-enlistment: unexpected error', [
                    'corporation_id' => $corporationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('fetch-corp-fw-enlistment: batch complete', [
            'fetched' => $fetched,
            'failed' => $failed,
            'batch_size' => count($targets),
        ]);

        if (count($targets) >= self::BATCH_SIZE) {
            static::dispatch()->delay(now()->addSeconds(3));
        }
    }

    /** @return list<int>
     *
     * Scope: only corps whose pilots ever attacked with a non-null
     * faction_id on a recent killmail. A corp enlisted in FW taints
     * its pilots' killmail rows with faction_id at log time, so a
     * zero-faction corp is provably not enlisted and there's no
     * reason to ask ESI (and burn error budget on the 401 it would
     * return). Shrinks the universe from ~100k corps to low thousands
     * and lets the TTL-skip actually retire stable rows.
     *
     * `is_enlisted=0` + a 90-day placeholder TTL is handled by
     * upsert — a pilot-side faction signal will always land before
     * the 90d window expires, so an unlisted corp that later enlists
     * gets picked up next sweep.
     */
    private function findStaleCorporations(): array
    {
        $sql = <<<'SQL'
            SELECT u.corp_id
              FROM (
                SELECT DISTINCT ka.corporation_id AS corp_id
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                 WHERE ka.corporation_id IS NOT NULL
                   AND ka.faction_id IS NOT NULL
                   AND k.killed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                UNION
                SELECT DISTINCT k.victim_corporation_id AS corp_id
                  FROM killmails k
                 WHERE k.victim_corporation_id IS NOT NULL
                   AND k.victim_faction_id IS NOT NULL
                   AND k.killed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              ) u
              LEFT JOIN corporation_fw_enlistment cfe
                ON cfe.corporation_id = u.corp_id
             WHERE cfe.corporation_id IS NULL
                OR cfe.last_fetched_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT ?
        SQL;
        $rows = DB::select($sql, [self::ACTIVE_WINDOW_DAYS, self::ACTIVE_WINDOW_DAYS, self::STALE_DAYS, self::BATCH_SIZE]);
        return array_map(fn ($r) => (int) $r->corp_id, $rows);
    }

    private function fetchAndPersist(EsiClientInterface $esi, int $corporationId): void
    {
        $newBaseUrl = (string) config('eve.esi.new_base_url', 'https://esi.evetech.net');
        $compatDate = (string) config('eve.esi.compat_date', '2025-12-16');
        $url = rtrim($newBaseUrl, '/')."/corporations/{$corporationId}/fw/stats/";

        $response = $esi->get($url, headers: [
            'X-Compatibility-Date' => $compatDate,
        ]);

        $body = $response->body;
        $factionId = null;
        $enlistedOn = null;
        $extras = [];
        if (is_array($body) && ! empty($body)) {
            $factionId = isset($body['faction_id']) ? (int) $body['faction_id'] : null;
            $enlistedOn = isset($body['enlisted_on']) ? date('Y-m-d H:i:s', strtotime((string) $body['enlisted_on'])) : null;
            $extras = [
                'kills_yesterday' => $body['kills']['yesterday'] ?? null,
                'kills_last_week' => $body['kills']['last_week'] ?? null,
                'kills_total' => $body['kills']['total'] ?? null,
                'victory_points_yesterday' => $body['victory_points']['yesterday'] ?? null,
                'victory_points_last_week' => $body['victory_points']['last_week'] ?? null,
                'victory_points_total' => $body['victory_points']['total'] ?? null,
            ];
        }
        $this->upsert($corporationId, $factionId, $enlistedOn, $extras);
    }

    /** @param array<string,mixed> $extras */
    private function upsert(int $corporationId, ?int $factionId, ?string $enlistedOn, array $extras): void
    {
        DB::table('corporation_fw_enlistment')->upsert(
            [[
                'corporation_id' => $corporationId,
                'faction_id' => $factionId,
                'enlisted_on' => $enlistedOn,
                'is_enlisted' => $factionId !== null ? 1 : 0,
                'kills_yesterday' => $extras['kills_yesterday'] ?? null,
                'kills_last_week' => $extras['kills_last_week'] ?? null,
                'kills_total' => $extras['kills_total'] ?? null,
                'victory_points_yesterday' => $extras['victory_points_yesterday'] ?? null,
                'victory_points_last_week' => $extras['victory_points_last_week'] ?? null,
                'victory_points_total' => $extras['victory_points_total'] ?? null,
                'last_fetched_at' => now(),
            ]],
            ['corporation_id'],
            [
                'faction_id', 'enlisted_on', 'is_enlisted',
                'kills_yesterday', 'kills_last_week', 'kills_total',
                'victory_points_yesterday', 'victory_points_last_week', 'victory_points_total',
                'last_fetched_at',
            ],
        );
    }
}
