<?php

declare(strict_types=1);

namespace App\Domains\Killmails\Services;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheater;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetch zKillboard's total_value for a battle theater on demand.
 *
 * Our killmails.total_value uses EveRef historical pricing, which tends
 * to under-value faction/deadspace fit modules + capital hulls vs
 * zKill's Jita-anchored live feed. For battle overview pages we also
 * display zKill's number so the operator sees both valuations side by
 * side.
 *
 * One HTTP call per theater render (system+time window batch endpoint).
 * Cached 24h keyed by public_slug + window_end since stats rarely
 * change after the fight ends.
 */
final class ZkillTheaterValueService
{
    private const CACHE_TTL_SECONDS = 86400;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const USER_AGENT = 'AegisCore/0.1 (+ops@example.com; WinterCo battle reports)';
    /** Min per-km zkill coverage to use the DB aggregate vs the related/ fallback. */
    private const COVERAGE_THRESHOLD = 0.95;

    /**
     * Per-km zkill_total_value aggregate across all kms in the theater.
     * Returns null if coverage is too low (caller falls back to
     * /api/related/'s 1h × primary system summary).
     *
     * @return array{sum_isk: float, covered: int, total: int}|null
     */
    public function aggregateForTheater(BattleTheater $theater): ?array
    {
        $row = \Illuminate\Support\Facades\DB::table('battle_theater_killmails as btk')
            ->join('killmails as k', 'k.killmail_id', '=', 'btk.killmail_id')
            ->where('btk.theater_id', $theater->id)
            ->selectRaw('
                COUNT(*) AS total_km,
                SUM(CASE WHEN k.zkill_total_value IS NOT NULL THEN 1 ELSE 0 END) AS covered_km,
                COALESCE(SUM(k.zkill_total_value), 0) AS sum_zkill
            ')
            ->first();
        if ($row === null || (int) $row->total_km === 0) {
            return null;
        }
        $covered = (int) $row->covered_km;
        $total = (int) $row->total_km;
        if ($covered / $total < self::COVERAGE_THRESHOLD) {
            return null;
        }
        return [
            'sum_isk' => (float) $row->sum_zkill,
            'covered' => $covered,
            'total' => $total,
        ];
    }

    public function totalForTheater(BattleTheater $theater): ?float
    {
        if ($theater->primary_system_id <= 0 || $theater->start_time === null) {
            return null;
        }
        // Skip the HTTP call from CLI contexts (scheduled backfills
        // iterate thousands of theaters; 8s timeout × N kills the job
        // at exit 255 on rate-limit stalls). Web renders still fetch
        // + cache normally.
        if (app()->runningInConsole()) {
            return null;
        }
        // zKill /api/related/ requires an hour-aligned timestamp. Pick
        // the first full hour after start_time (e.g. start 00:28 → probe
        // 02:00 via the middle of the battle). If the fight is shorter
        // than one hour, just round down the start_time.
        $durationMinutes = $theater->end_time !== null
            ? (int) abs($theater->start_time->diffInMinutes($theater->end_time))
            : 0;
        $mid = $theater->start_time->copy()->addMinutes((int) ($durationMinutes / 2));
        $hourAligned = $mid->copy()->minute(0)->second(0)->format('YmdHi');

        $cacheKey = sprintf('zkill.theater.total.%s', $theater->public_slug ?: "{$theater->id}.{$hourAligned}");
        $cached = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($theater, $hourAligned): ?float {
            return $this->fetch($theater->primary_system_id, $hourAligned);
        });
        // Some cache drivers (Redis w/ serializer) return a stringified
        // float on read — cast defensively to satisfy the ?float return.
        return $cached === null ? null : (float) $cached;
    }

    private function fetch(int $systemId, string $hourAlignedTs): ?float
    {
        // zKill deprecated /kills/systemID/.../startTime/.../endTime/...
        // in favor of /related/{systemID}/{YYYYMMDDHHMM}/ which returns
        // teamA/teamB summaries with total_price per side. One request
        // covers the whole battle.
        $url = sprintf(
            'https://zkillboard.com/api/related/%d/%s/',
            $systemId,
            $hourAlignedTs,
        );
        try {
            $resp = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept-Encoding' => 'gzip'])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->retry(2, 500)
                ->get($url);
            if (! $resp->successful()) {
                Log::warning('zkill value fetch failed', ['url' => $url, 'status' => $resp->status()]);
                return null;
            }
            $body = $resp->json();
            $summary = $body['summary'] ?? null;
            if (! is_array($summary)) {
                return null;
            }
            $total = 0.0;
            foreach (['teamA', 'teamB'] as $teamKey) {
                $team = $summary[$teamKey] ?? null;
                if (! is_array($team)) {
                    continue;
                }
                $total += (float) ($team['totals']['total_price'] ?? 0.0);
            }
            return $total > 0 ? $total : null;
        } catch (\Throwable $e) {
            Log::warning('zkill value fetch exception', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
