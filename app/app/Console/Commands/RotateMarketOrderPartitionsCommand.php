<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rolling 72-hour HOT retention on market_orders.
 *
 * Drops daily partitions older than today − retention_days, and
 * pre-creates partitions for today + future_days. Designed to
 * run from the Laravel scheduler (no docker socket needed —
 * scheduler talks to mariadb directly through the configured
 * connection).
 *
 * Replaces the host-cron shell script
 * `scripts/market-orders-rotate.sh` so the run shows up in
 * Laravel logs / scheduler output rather than a host log file.
 *
 * Per-step DDL:
 *   - DROP PARTITION   = metadata-only, frees the .ibd, < 1 s
 *   - REORGANIZE p_future INTO (… new dailies …, p_future)
 *                      = metadata-only, no row movement (p_future is
 *                        always empty), < 1 s
 *
 * Idempotent: re-running the same day is a no-op (no candidates
 * to drop, no missing future partitions).
 */
class RotateMarketOrderPartitionsCommand extends Command
{
    protected $signature = 'market:rotate-partitions
                            {--retention-days=3 : keep this many days hot (default 3 = 72 h window)}
                            {--future-days=90 : pre-create this many days ahead}
                            {--dry-run : report planned DDL without executing}';

    protected $description = 'Daily partition rotation for market_orders (drops > retention, pre-creates future).';

    public function handle(): int
    {
        $retentionDays = max(1, (int) $this->option('retention-days'));
        $futureDays = max(0, (int) $this->option('future-days'));
        $dryRun = (bool) $this->option('dry-run');

        $today = Carbon::now('UTC')->startOfDay();
        $cutoffName = 'p' . $today->copy()->subDays($retentionDays)->format('Ymd');

        $this->info(sprintf(
            'rotate start (retention=%dd, future=%dd, dry_run=%s)',
            $retentionDays,
            $futureDays,
            $dryRun ? 'yes' : 'no'
        ));

        $oldParts = DB::table('information_schema.partitions')
            ->select('partition_name')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'market_orders')
            ->whereNotNull('partition_name')
            ->where('partition_name', '!=', 'p_future')
            ->where('partition_name', '<', $cutoffName)
            ->orderBy('partition_name')
            ->pluck('partition_name')
            ->all();

        if (count($oldParts) > 0) {
            $list = implode(', ', $oldParts);
            $this->info("drop candidates (older than {$cutoffName}): {$list}");
            if ($dryRun) {
                $this->info('dry-run: skipping DROP PARTITION');
            } else {
                DB::statement("ALTER TABLE market_orders DROP PARTITION {$list}");
                Log::info('market:rotate-partitions dropped', ['partitions' => $oldParts]);
                $this->info('drop complete');
            }
        } else {
            $this->info("no partitions older than {$cutoffName}");
        }

        $needed = [];
        for ($i = 0; $i <= $futureDays; $i++) {
            $day = $today->copy()->addDays($i);
            $pname = 'p' . $day->format('Ymd');
            $exists = DB::table('information_schema.partitions')
                ->where('table_schema', DB::raw('DATABASE()'))
                ->where('table_name', 'market_orders')
                ->where('partition_name', $pname)
                ->exists();
            if (! $exists) {
                $next = $day->copy()->addDay()->format('Y-m-d');
                $needed[] = "PARTITION {$pname} VALUES LESS THAN ('{$next}')";
            }
        }

        if (count($needed) > 0) {
            $this->info(sprintf('creating %d new future partitions', count($needed)));
            if ($dryRun) {
                $this->info('dry-run: skipping ALTER TABLE REORGANIZE');
            } else {
                $newList = implode(",\n            ", $needed);
                DB::statement("ALTER TABLE market_orders REORGANIZE PARTITION p_future INTO (
            {$newList},
            PARTITION p_future VALUES LESS THAN (MAXVALUE)
        )");
                Log::info('market:rotate-partitions created', ['count' => count($needed)]);
                $this->info('create complete');
            }
        }

        $this->info('rotate done');

        return self::SUCCESS;
    }
}
