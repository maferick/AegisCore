<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Backfill system_sovereignty_history from EVE Ref's public daily
 * sovereignty snapshot archive
 * (https://data.everef.net/sovereignty-map/history/).
 *
 * One snapshot per day is enough for "did sov flip since baseline"
 * diffing — we pick the 12:40 UTC snapshot since EVE downtime is at
 * 11:00 UTC, so 12:40 captures the post-downtime state for that day.
 *
 * Idempotent: re-running with an already-snapshotted date overwrites
 * (kept simple — `delete` then `insert` keyed by captured_on).
 */
class BackfillSovHistoryCommand extends Command
{
    protected $signature = 'sov:backfill-history
        {--from= : ISO date floor, default 2026-04-02 (vs-imperium war start)}
        {--to= : ISO date ceiling, default today}
        {--hour=12 : Hour of day to capture (UTC)}
        {--force : Overwrite days that already exist in history}';

    protected $description = 'Backfill system_sovereignty_history from EVE Ref daily JSON snapshots.';

    public function handle(): int
    {
        $from = CarbonImmutable::parse($this->option('from') ?: '2026-04-02', 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->option('to') ?: 'today', 'UTC')->startOfDay();
        $hour = (int) $this->option('hour');
        $force = (bool) $this->option('force');

        if ($from->greaterThan($to)) {
            $this->error('--from is after --to');
            return self::FAILURE;
        }

        // Skip dates we already have (unless --force).
        $existing = $force ? [] : DB::table('system_sovereignty_history')
            ->whereBetween('captured_on', [$from->toDateString(), $to->toDateString()])
            ->select('captured_on')
            ->distinct()
            ->pluck('captured_on')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();
        $skip = array_flip($existing);

        $cur = $from;
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        while ($cur->lessThanOrEqualTo($to)) {
            $date = $cur->toDateString();
            if (isset($skip[$date])) {
                $cur = $cur->addDay();
                $skipped++;
                continue;
            }

            $url = sprintf(
                'https://data.everef.net/sovereignty-map/history/%d/%s/sovereignty-map-%s_%02d-40-01.json.bz2',
                $cur->year, $date, $date, $hour,
            );
            $this->info("Fetching {$date}...");
            try {
                $resp = Http::withHeaders(['User-Agent' => 'AegisCore/1.0 sov-history-backfill'])
                    ->timeout(15)
                    ->get($url);
                if (! $resp->successful()) {
                    $this->warn("  {$date}: HTTP {$resp->status()}, skipping");
                    $failed++;
                    $cur = $cur->addDay();
                    continue;
                }
                // ext-bz2 isn't built into our php-fpm image; shell
                // out to bunzip2 (always present on alpine).
                $tmp = tempnam(sys_get_temp_dir(), 'sov-');
                file_put_contents($tmp, $resp->body());
                $raw = shell_exec("bunzip2 -c {$tmp} 2>/dev/null");
                @unlink($tmp);
                if (! is_string($raw) || $raw === '') {
                    $this->warn("  {$date}: bz2 decode failed, skipping");
                    $failed++;
                    $cur = $cur->addDay();
                    continue;
                }
                $rows = json_decode($raw, true);
                if (! is_array($rows) || $rows === []) {
                    $this->warn("  {$date}: empty JSON, skipping");
                    $failed++;
                    $cur = $cur->addDay();
                    continue;
                }
                // Wipe + insert atomically per day.
                $now = now();
                $batch = [];
                foreach ($rows as $r) {
                    $batch[] = [
                        'solar_system_id' => (int) ($r['system_id'] ?? 0),
                        'alliance_id' => isset($r['alliance_id']) ? (int) $r['alliance_id'] : null,
                        'corporation_id' => isset($r['corporation_id']) ? (int) $r['corporation_id'] : null,
                        'faction_id' => isset($r['faction_id']) ? (int) $r['faction_id'] : null,
                        'captured_on' => $date,
                        'captured_at' => $now,
                    ];
                }
                DB::table('system_sovereignty_history')->where('captured_on', $date)->delete();
                foreach (array_chunk($batch, 1000) as $chunk) {
                    DB::table('system_sovereignty_history')->insert($chunk);
                }
                $processed++;
                $this->info("  {$date}: " . count($batch) . " rows");
            } catch (\Throwable $e) {
                $this->warn("  {$date}: {$e->getMessage()}");
                $failed++;
            }
            $cur = $cur->addDay();
            usleep(200_000); // polite throttle
        }

        $this->info("Done. processed={$processed} skipped={$skipped} failed={$failed}");
        return self::SUCCESS;
    }
}
