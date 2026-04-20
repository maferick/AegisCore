<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Derive corporation FW enlistment from killmail signal (SQL-only).
 *
 * ESI's /corporations/{id}/fw/stats/ was documented as public but
 * actually returns 401 without an auth token — so we can't use it.
 * Instead, aggregate killmail_attackers.faction_id per corp over
 * the last N days. A corp whose pilot-appearances carry the same
 * faction_id in ≥ `ENLISTED_RATIO` of recent killmails is flagged
 * is_enlisted with that faction.
 *
 * Single SQL pass wipes + rewrites the whole table — the aggregation
 * is cheap (grouped index on killmail_attackers.corporation_id).
 */
final class FetchCorporationFwEnlistment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const ACTIVE_WINDOW_DAYS = 30;

    private const MIN_APPEARANCES = 10;

    private const ENLISTED_RATIO = 0.5;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function handle(): void
    {
        $now = now();
        $inserted = DB::affectingStatement(<<<'SQL'
            INSERT INTO corporation_fw_enlistment
                (corporation_id, faction_id, enlisted_on, is_enlisted, last_fetched_at)
            SELECT agg.corporation_id,
                   CASE WHEN agg.faction_ratio >= ? THEN agg.faction_id ELSE NULL END AS faction_id,
                   NULL AS enlisted_on,
                   CASE WHEN agg.faction_ratio >= ? THEN 1 ELSE 0 END AS is_enlisted,
                   ? AS last_fetched_at
              FROM (
                SELECT ka.corporation_id,
                       MAX(ka.faction_id) AS faction_id,
                       SUM(CASE WHEN ka.faction_id IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) AS faction_ratio,
                       COUNT(*) AS total_appearances
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                 WHERE ka.corporation_id IS NOT NULL
                   AND k.killed_at >= DATE_SUB(?, INTERVAL ? DAY)
                 GROUP BY ka.corporation_id
                HAVING total_appearances >= ?
              ) AS agg
            ON DUPLICATE KEY UPDATE
              faction_id       = VALUES(faction_id),
              is_enlisted      = VALUES(is_enlisted),
              last_fetched_at  = VALUES(last_fetched_at)
        SQL, [
            self::ENLISTED_RATIO,
            self::ENLISTED_RATIO,
            $now,
            $now,
            self::ACTIVE_WINDOW_DAYS,
            self::MIN_APPEARANCES,
        ]);

        Log::info('fetch-corp-fw-enlistment: sweep complete', [
            'rows_upserted' => $inserted,
            'window_days' => self::ACTIVE_WINDOW_DAYS,
            'min_appearances' => self::MIN_APPEARANCES,
            'enlisted_ratio' => self::ENLISTED_RATIO,
        ]);
    }
}
