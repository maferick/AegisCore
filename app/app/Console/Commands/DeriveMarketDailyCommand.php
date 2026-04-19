<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Derive daily market-history aggregates (lowest/highest/average price,
 * executed volume) for a given day directly from our per-minute
 * `market_orders` snapshots. Skips the 3-4 day EveRef lag — we close
 * the freshness gap using data we're already polling.
 *
 * Heuristic: for each (region, type, order_id) that appeared in the
 * 24h window, executed units = max(volume_remain) − min(volume_remain).
 * Works cleanly on high-volume orders; over-counts on cancels but
 * that's a small fraction for liquid items and a tolerable trade-off
 * for sub-hour freshness vs EveRef's multi-day lag.
 *
 * Lowest / highest / average taken as MIN / MAX / volume-weighted-avg
 * of prices observed on sell orders inside the window.
 *
 * Writes to `market_history` with source='esi_derived_daily' so the
 * rows are distinguishable from EveRef rows on the same date (EveRef
 * remains the authoritative backfill; our derivation fills in today +
 * yesterday + the days before EveRef publishes).
 */
class DeriveMarketDailyCommand extends Command
{
    protected $signature = 'market:derive-daily
                            {--date= : YYYY-MM-DD (default: yesterday UTC)}
                            {--region= : single region_id (default: all observed)}
                            {--dry-run}';

    protected $description = 'Derive daily market_history from our per-minute market_orders snapshots.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Region scope: explicit --region wins; otherwise default to
        // active market_hubs (Jita + our player hubs). Scanning all 100+
        // regions hourly would burn ~10h CPU; the operator-watched set
        // is what the dashboards read.
        $regions = [];
        if ($this->option('region')) {
            $regions[] = (int) $this->option('region');
        } else {
            $regions = DB::table('market_hubs')
                ->where('is_active', 1)
                ->where('region_id', '>', 0)
                ->pluck('region_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
            // Always include The Forge (Jita) as reference.
            if (! in_array(10000002, $regions, true)) $regions[] = 10000002;
        }

        // No --date: derive both yesterday and today so the rolling-
        // freshness view stays current on every hourly tick.
        $dates = $this->option('date')
            ? [Carbon::parse((string) $this->option('date'))->startOfDay()]
            : [now()->subDay()->startOfDay(), now()->startOfDay()];

        foreach ($regions as $rid) {
            foreach ($dates as $date) {
                $this->deriveDay($date, $rid, $dryRun);
            }
        }
        return self::SUCCESS;
    }

    private function deriveDay(Carbon $date, ?int $regionFilter, bool $dryRun): void
    {
        $dayStart = $date->toDateTimeString();
        $dayEnd = $date->copy()->endOfDay()->toDateTimeString();

        $this->info("Deriving market_history for {$date->toDateString()}" . ($regionFilter ? " (region {$regionFilter})" : ''));

        $regionBind = $regionFilter ? ' AND region_id = ? ' : '';

        // Per-order window aggregation: first/last volume_remain, price
        // extremes. Executed heuristic = max - min volume_remain. Grouped
        // by (region, type) into final daily rollup in one pass.
        $sql = <<<SQL
            INSERT INTO market_history
                (trade_date, region_id, type_id, average, highest, lowest, volume, order_count, source, observation_kind, created_at, updated_at)
            SELECT
                DATE(?) AS trade_date,
                region_id,
                type_id,
                IFNULL(SUM(consumed * avg_p) / NULLIF(SUM(consumed), 0), AVG(avg_p)) AS average,
                MAX(max_p) AS highest,
                MIN(min_p) AS lowest,
                SUM(consumed) AS volume,
                COUNT(*) AS order_count,
                'esi_derived_daily',
                'incremental_poll',
                NOW(),
                NOW()
            FROM (
                SELECT
                    order_id, region_id, type_id,
                    MAX(volume_remain) - MIN(volume_remain) AS consumed,
                    MIN(price) AS min_p,
                    MAX(price) AS max_p,
                    AVG(price) AS avg_p
                FROM market_orders
                WHERE observed_at >= ? AND observed_at < ?
                  AND is_buy = 0
                  {$regionBind}
                GROUP BY order_id, region_id, type_id
                HAVING consumed > 0
            ) order_windows
            GROUP BY region_id, type_id
            ON DUPLICATE KEY UPDATE
                average = CASE WHEN market_history.source = 'esi_derived_daily' THEN VALUES(average) ELSE market_history.average END,
                highest = CASE WHEN market_history.source = 'esi_derived_daily' THEN VALUES(highest) ELSE market_history.highest END,
                lowest = CASE WHEN market_history.source = 'esi_derived_daily' THEN VALUES(lowest) ELSE market_history.lowest END,
                volume = CASE WHEN market_history.source = 'esi_derived_daily' THEN VALUES(volume) ELSE market_history.volume END,
                order_count = CASE WHEN market_history.source = 'esi_derived_daily' THEN VALUES(order_count) ELSE market_history.order_count END,
                updated_at = NOW()
        SQL;

        $bindings = [$date->toDateString(), $dayStart, $dayEnd];
        if ($regionFilter) $bindings[] = $regionFilter;

        if ($dryRun) {
            // Just count what we'd insert.
            $countSql = <<<SQL
                SELECT COUNT(DISTINCT region_id, type_id) AS n FROM (
                    SELECT region_id, type_id
                    FROM market_orders
                    WHERE observed_at >= ? AND observed_at < ?
                      AND is_buy = 0
                      {$regionBind}
                    GROUP BY order_id, region_id, type_id
                    HAVING (MAX(volume_remain) - MIN(volume_remain)) > 0
                ) x
            SQL;
            $cb = [$dayStart, $dayEnd];
            if ($regionFilter) $cb[] = $regionFilter;
            $n = DB::scalar($countSql, $cb);
            $this->info("Dry run — would upsert ~{$n} rows.");
            return;
        }

        $t0 = microtime(true);
        $affected = DB::affectingStatement($sql, $bindings);
        $t1 = microtime(true);
        $this->info(sprintf('Upserted %s rows in %.1fs', number_format($affected), $t1 - $t0));
    }
}
