<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * eve-log:dscan-backfill — register every distinct dscan.info URL
 * seen in eve_log_events into eve_log_dscan_snapshots. One-shot
 * companion to the new ingest hook for events that predate it.
 *
 * Idempotent. INSERT ... ON DUPLICATE bumps mention_count.
 */
class EveLogDscanBackfillCommand extends Command
{
    protected $signature = 'eve-log:dscan-backfill';

    protected $description = 'Register dscan.info URLs from existing events into eve_log_dscan_snapshots.';

    public function handle(): int
    {
        $rows = DB::table('eve_log_events')
            ->whereNotNull('external_dscan_url')
            ->where('parsed_json', 'like', '%dscan_id%')
            ->select('external_dscan_url', 'parsed_json', 'event_timestamp')
            ->orderBy('id')
            ->get();
        $total = $rows->count();
        $this->info("Backfilling {$total} dscan references…");
        if ($total === 0) return self::SUCCESS;

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        foreach ($rows as $r) {
            $bar->advance();
            $pj = json_decode((string) $r->parsed_json, true);
            $sid = is_array($pj) ? ($pj['dscan_id'] ?? null) : null;
            if (! $sid) continue;
            $ts = $r->event_timestamp ?? now();
            DB::statement(
                'INSERT INTO eve_log_dscan_snapshots
                   (snapshot_id, url, fetch_status, mention_count, first_seen_at, last_seen_at)
                 VALUES (?, ?, "pending", 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   mention_count = mention_count + 1,
                   last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at))',
                [
                    mb_substr((string) $sid, 0, 64),
                    mb_substr((string) $r->external_dscan_url, 0, 255),
                    $ts, $ts,
                ],
            );
        }
        $bar->finish();
        $this->newLine(2);
        $distinct = DB::table('eve_log_dscan_snapshots')->count();
        $this->info("Distinct snapshots in registry: {$distinct}");
        return self::SUCCESS;
    }
}
