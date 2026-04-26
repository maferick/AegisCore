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
            ->whereIn('fetch_status', ['pending'])
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
            // dscan.info serves a viewer HTML page at /v/{id}; there's
            // no public JSON API. We parse the HTML for the embedded
            // ship list. Cloudflare-fronted; Cloudflare returns 200
            // even for revoked snapshots, which then render an empty
            // ship list — the parser treats zero-ship results as
            // expired rather than success.
            $url = "{$apiBase}/v/{$r->snapshot_id}";
            try {
                $res = Http::timeout(20)
                    ->withHeaders(['User-Agent' => 'AegisCore-CounterIntel/1.0 (+admin contact via portal)'])
                    ->get($url);
                $now = now();
                if (! $res->successful()) {
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
                    sleep($sleepSec);
                    continue;
                }
                $html = (string) $res->body();
                $shipMap = self::parseShipsFromHtml($html);
                $shipCount = (int) array_sum($shipMap);
                $totalBadge = self::parseTotalShipsBadge($html);
                if ($totalBadge !== null && $totalBadge !== $shipCount) {
                    // Dscan badge claims a total that differs from the
                    // li sum — store the badge as authoritative since
                    // it matches dscan's own count.
                    $shipCount = $totalBadge;
                }
                if ($shipCount === 0) {
                    DB::table('eve_log_dscan_snapshots')
                        ->where('id', $r->id)
                        ->update([
                            'fetch_status' => 'expired',
                            'http_status' => $res->status(),
                            'error' => 'no ships found in dscan viewer',
                            'fetch_attempts' => DB::raw('fetch_attempts + 1'),
                            'last_fetched_at' => $now,
                        ]);
                    $fail++;
                    sleep($sleepSec);
                    continue;
                }
                DB::table('eve_log_dscan_snapshots')
                    ->where('id', $r->id)
                    ->update([
                        'fetch_status' => 'success',
                        'http_status' => $res->status(),
                        'ship_count' => $shipCount,
                        'ship_types_json' => json_encode($shipMap, JSON_UNESCAPED_UNICODE),
                        'top_ship_summary' => mb_substr(self::topShipSummary($shipMap), 0, 500),
                        'raw_json' => null, // we don't store the full HTML — too heavy and not useful
                        'fetch_attempts' => DB::raw('fetch_attempts + 1'),
                        'last_fetched_at' => $now,
                        'error' => null,
                    ]);
                $ok++;
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
     * Parse the dscan.info HTML viewer's ships block. Each item
     * appears as:
     *
     *   <li class="list-group-item shipclass\d+" data-sclid="\d+">
     *     <span class="badge label label-default">{count}</span>
     *     <b>{ship_type_name}</b>
     *
     * Returns a {ship_type_name: count} dict ordered by count DESC.
     *
     * @return array<string, int>
     */
    public static function parseShipsFromHtml(string $html): array
    {
        // Constrain matching to the <ul ... id="ships"> block to avoid
        // catching the shipclasses rollup section.
        if (preg_match('/<ul[^>]+id="ships"[^>]*>(.*?)<\/ul>/su', $html, $section)) {
            $block = $section[1];
        } else {
            $block = $html; // fallback — match anywhere
        }
        $out = [];
        if (preg_match_all(
            '/<li[^>]*class="list-group-item\s+shipclass\d+"[^>]*data-sclid="\d+"[^>]*>\s*<span[^>]*class="badge[^"]*"[^>]*>(\d+)<\/span>\s*<b>([^<]+)<\/b>/s',
            $block,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $m) {
                $count = (int) $m[1];
                $name = trim((string) $m[2]);
                if ($name === '') continue;
                $out[$name] = ($out[$name] ?? 0) + $count;
            }
        }
        arsort($out);
        return $out;
    }

    /**
     * The dscan viewer's "Ships" panel header carries a total badge
     * like `<span class="badge label label-primary">154</span>`.
     */
    public static function parseTotalShipsBadge(string $html): ?int
    {
        if (preg_match(
            '/<h3[^>]*class="panel-title"[^>]*>\s*Ships\s*<span[^>]*class="badge[^"]*"[^>]*>(\d+)<\/span>/s',
            $html,
            $m,
        )) {
            return (int) $m[1];
        }
        return null;
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
