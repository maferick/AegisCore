<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * eve-log:fetch-dscan — pull dscan.info snapshot data for pending
 * rows.
 *
 * Strict rate limiting. Treats dscan.info content as supporting
 * evidence only — never blocks ingestion, never retries
 * indefinitely. Failures stay marked `failed` with the http_status
 * + error so an operator can review.
 *
 * v1: respects --limit per run, configurable rate (--rate-per-min,
 * default 6/min), bounded retries (max 3 fetch_attempts before
 * blocked).
 *
 * Privacy + ABAC: parsed dscan content is stored raw in raw_json
 * (longtext) but the column is access-gated by policy at read time
 * — readers must use a service that checks user role rather than
 * reading the column directly.
 */
class EveLogFetchDscanCommand extends Command
{
    protected $signature = 'eve-log:fetch-dscan
        {--limit=20 : maximum snapshots to fetch per run}
        {--rate-per-min=6 : max requests per minute (sleep enforces)}
        {--max-attempts=3 : retry cap before status=blocked}
        {--api-base=https://dscan.info : dscan.info root}';

    protected $description = 'Fetch + parse pending dscan.info snapshots referenced by intel events.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $ratePerMin = max(1, (int) $this->option('rate-per-min'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $apiBase = rtrim((string) $this->option('api-base'), '/');
        $sleepSec = max(1, (int) ceil(60 / $ratePerMin));

        $rows = DB::table('eve_log_dscan_snapshots')
            ->where('fetch_status', 'pending')
            ->where('fetch_attempts', '<', $maxAttempts)
            ->orderBy('last_seen_at')
            ->limit($limit)
            ->get(['id', 'snapshot_id', 'url']);

        $total = $rows->count();
        if ($total === 0) {
            $this->info('No pending dscan snapshots to fetch.');
            return self::SUCCESS;
        }
        $this->info("Fetching {$total} snapshots ({$ratePerMin}/min)…");

        $ok = 0;
        $fail = 0;
        foreach ($rows as $r) {
            $apiUrl = "{$apiBase}/api/scans/{$r->snapshot_id}";
            try {
                $res = Http::timeout(20)
                    ->withHeaders(['User-Agent' => 'AegisCore-CounterIntel/1.0'])
                    ->get($apiUrl);
                $now = now();
                if ($res->successful()) {
                    $body = $res->json();
                    $shipCount = is_array($body) ? count((array) ($body['ships'] ?? $body['contents'] ?? $body)) : null;
                    $shipTypes = self::summariseShipTypes($body);
                    DB::table('eve_log_dscan_snapshots')
                        ->where('id', $r->id)
                        ->update([
                            'fetch_status' => 'success',
                            'http_status' => $res->status(),
                            'ship_count' => $shipCount,
                            'ship_types_json' => $shipTypes ? json_encode($shipTypes, JSON_UNESCAPED_UNICODE) : null,
                            'top_ship_summary' => $shipTypes ? mb_substr(self::topShipSummary($shipTypes), 0, 500) : null,
                            'raw_json' => mb_substr((string) $res->body(), 0, 1024 * 64),
                            'fetch_attempts' => DB::raw('fetch_attempts + 1'),
                            'last_fetched_at' => $now,
                            'error' => null,
                        ]);
                    $ok++;
                } else {
                    $status = $res->status();
                    DB::table('eve_log_dscan_snapshots')
                        ->where('id', $r->id)
                        ->update([
                            'fetch_status' => $status >= 500 ? 'pending' : ($status === 404 ? 'expired' : 'failed'),
                            'http_status' => $status,
                            'error' => mb_substr("HTTP {$status}", 0, 500),
                            'fetch_attempts' => DB::raw('fetch_attempts + 1'),
                            'last_fetched_at' => $now,
                        ]);
                    $fail++;
                }
            } catch (\Throwable $e) {
                DB::table('eve_log_dscan_snapshots')
                    ->where('id', $r->id)
                    ->update([
                        'fetch_status' => 'pending',
                        'error' => mb_substr($e->getMessage(), 0, 500),
                        'fetch_attempts' => DB::raw('fetch_attempts + 1'),
                        'last_fetched_at' => now(),
                    ]);
                $fail++;
            }
            sleep($sleepSec);
        }
        $this->info("Done. ok={$ok} fail={$fail}");
        // Promote rows that hit the attempt cap to status=blocked so
        // they stop appearing in the pending queue.
        DB::table('eve_log_dscan_snapshots')
            ->where('fetch_status', 'pending')
            ->where('fetch_attempts', '>=', $maxAttempts)
            ->update(['fetch_status' => 'blocked']);
        return self::SUCCESS;
    }

    /**
     * Best-effort ship-type → count rollup. dscan.info v1 API shape
     * varies per snapshot; we accept either {ships: [{type:..., count:..}]}
     * or a flat {contents: [...]} list of types.
     *
     * @return array<string, int>
     */
    private static function summariseShipTypes($body): array
    {
        if (! is_array($body)) return [];
        $out = [];
        $list = $body['ships'] ?? $body['contents'] ?? null;
        if (is_array($list)) {
            foreach ($list as $row) {
                $type = null;
                $count = 1;
                if (is_array($row)) {
                    $type = $row['type'] ?? $row['typeName'] ?? $row['name'] ?? null;
                    $count = (int) ($row['count'] ?? 1);
                } elseif (is_string($row)) {
                    $type = $row;
                }
                if (! $type) continue;
                $out[$type] = ($out[$type] ?? 0) + max(1, $count);
            }
        }
        arsort($out);
        return $out;
    }

    /** @param  array<string, int>  $types */
    private static function topShipSummary(array $types, int $limit = 5): string
    {
        $parts = [];
        $i = 0;
        foreach ($types as $type => $count) {
            if ($i++ >= $limit) break;
            $parts[] = "{$count}x {$type}";
        }
        return implode(', ', $parts);
    }
}
