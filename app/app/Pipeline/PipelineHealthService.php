<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Domains\KillmailsBattleTheaters\Services\AllegianceGraphService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Pipeline-health snapshot for /admin/pipeline-health.
 *
 * One cheap probe per metric, all wrapped in try/catch so a dead
 * backend never 500s the page. Cached for a handful of seconds so
 * the auto-refresh polling doesn't re-hit the DB every tick.
 *
 * Scope: the operator-facing "is my ingest stuck / is enrichment
 * keeping up / is the stream drifting" dashboard. Infrastructure
 * liveness (MariaDB / Redis / Neo4j up) already lives in
 * {@see \App\System\SystemStatusService}; this complements rather
 * than replaces it.
 */
class PipelineHealthService
{
    private const CACHE_KEY = 'aegiscore:pipeline-health:v1';

    private const CACHE_TTL_SECONDS = 15;

    public function __construct(
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(): array
    {
        $store = $this->cache ?? app('cache.store');
        try {
            $cached = $store->get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Cache failure falls through to a fresh probe.
        }

        return $this->fresh();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fresh(): array
    {
        $metrics = [
            'ingest_lag' => $this->probeIngestLag(),
            'content_lag' => $this->probeContentLag(),
            'r2z2_cursor' => $this->probeR2z2Cursor(),
            'shells' => $this->probeShellCount(),
            'enrich_backlog' => $this->probeEnrichBacklog(),
            'cluster_lag' => $this->probeClusterLag(),
            'horizon_queues' => $this->probeHorizonQueues(),
            'failed_jobs' => $this->probeFailedJobs(),
            'neo4j_edges' => $this->probeNeo4jEdges(),
            'market_history' => $this->probeMarketHistory(),
            'esi_backlog' => $this->probeEsiBacklog(),
            'opensearch_docs' => $this->probeOpenSearchDocs(),
        ];

        try {
            ($this->cache ?? app('cache.store'))->put(self::CACHE_KEY, $metrics, self::CACHE_TTL_SECONDS);
        } catch (Throwable) {
            // Best-effort cache; ignore.
        }

        return $metrics;
    }

    // -------------------------------------------------------------- //
    // Stream + content freshness
    // -------------------------------------------------------------- //

    private function probeIngestLag(): array
    {
        try {
            $latest = DB::table('killmails')->max('created_at');
            if ($latest === null) {
                return self::level('No killmails ingested yet', 'warn');
            }
            $sec = (int) abs((int) now()->diffInSeconds(Carbon::parse($latest)));
            $level = $sec < 300 ? 'ok' : ($sec < 1800 ? 'warn' : 'down');
            return self::level(self::humanDuration($sec).' ago (last insert)', $level, [
                'latest_created_at' => $latest,
            ]);
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    private function probeContentLag(): array
    {
        try {
            $latest = DB::table('killmails')->max('killed_at');
            if ($latest === null) {
                return self::level('No killmails yet', 'warn');
            }
            $sec = (int) abs((int) now()->diffInSeconds(Carbon::parse($latest)));
            // EVE downtime is ~11:00 UTC/daily; tolerate a 25-minute
            // quiet window without flagging.
            $level = $sec < 600 ? 'ok' : ($sec < 1800 ? 'warn' : 'down');
            return self::level(self::humanDuration($sec).' ago (latest killed_at)', $level, [
                'latest_killed_at' => $latest,
            ]);
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    private function probeR2z2Cursor(): array
    {
        try {
            $row = DB::table('killmail_ingest_state')
                ->where('source', 'r2z2')
                ->where('state_key', 'last_sequence')
                ->first();
            if ($row === null) {
                return self::level('no state row', 'down');
            }
            $cursor = (int) $row->state_value;

            $head = null;
            try {
                $resp = Http::timeout(2)->get('https://r2z2.zkillboard.com/ephemeral/sequence.json');
                if ($resp->ok()) {
                    $head = (int) ($resp->json('sequence') ?? 0);
                }
            } catch (Throwable) {
                // head fetch is best-effort; fall through with null
            }

            if ($head === null) {
                return self::level('cursor='.number_format($cursor).' (head unreachable)', 'warn', [
                    'cursor' => $cursor,
                ]);
            }

            $drift = $cursor - $head;
            // Ahead by a handful is normal (we saved the clamped value);
            // behind by >500 usually means the stream is choking.
            $level = ($drift > -200 && $drift < 200) ? 'ok' : ($drift > -1000 && $drift < 1000 ? 'warn' : 'down');
            $label = $drift >= 0 ? '+'.$drift.' ahead' : $drift.' behind';
            return self::level("cursor={$cursor} head={$head} ({$label})", $level, [
                'cursor' => $cursor,
                'head' => $head,
                'drift' => $drift,
                'updated_at' => $row->updated_at,
            ]);
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    private function probeShellCount(): array
    {
        try {
            $cutoff = now()->subDays(7)->toDateString();
            $shells = DB::table('killmails')
                ->where('killed_at', '>=', $cutoff)
                ->where('attacker_count', 0)
                ->count();
            // Thousands of shells in a week means the parser broke;
            // dozens is routine (NPC cleanups, rare empty kills).
            $level = $shells < 100 ? 'ok' : ($shells < 2000 ? 'warn' : 'down');
            return self::level(number_format($shells).' shell rows in last 7d', $level, [
                'count' => $shells,
            ]);
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    // -------------------------------------------------------------- //
    // Enrichment + clustering
    // -------------------------------------------------------------- //

    private function probeEnrichBacklog(): array
    {
        try {
            $total = DB::table('killmails')->whereNull('enriched_at')->count();
            $rows = DB::select(<<<'SQL'
                SELECT DATE_FORMAT(killed_at, '%Y-%m') AS m,
                       COUNT(*) AS n
                FROM killmails
                WHERE enriched_at IS NULL
                GROUP BY m
                ORDER BY n DESC
                LIMIT 3
            SQL);
            $parts = [];
            foreach ($rows as $r) {
                $parts[] = "{$r->m}={$r->n}";
            }
            $detail = number_format($total).' pending'.($parts ? ' ('.implode(', ', $parts).')' : '');

            $currentMonth = now()->format('Y-m');
            $currentPending = 0;
            foreach ($rows as $r) {
                if ($r->m === $currentMonth) {
                    $currentPending = (int) $r->n;
                }
            }
            // Current-month backlog > 5k means enrichment is falling
            // behind live ingest.
            $level = $currentPending < 2000 ? 'ok' : ($currentPending < 10000 ? 'warn' : 'down');
            return self::level($detail, $level, [
                'total' => $total,
                'by_month' => array_map(fn ($r) => ['month' => $r->m, 'n' => (int) $r->n], $rows),
            ]);
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    private function probeClusterLag(): array
    {
        try {
            $latest = DB::table('battle_theaters')->max('end_time');
            $total = DB::table('battle_theaters')->count();
            if ($latest === null) {
                return self::level('no theaters yet', 'warn');
            }
            $sec = (int) abs((int) now()->diffInSeconds(Carbon::parse($latest)));
            // Clusterer runs every 5min; anything under 10min is OK,
            // under 30min is a soft warning, beyond that is stuck.
            $level = $sec < 600 ? 'ok' : ($sec < 1800 ? 'warn' : 'down');
            return self::level(
                'latest theater '.self::humanDuration($sec).' ago · '.number_format($total).' total',
                $level,
                ['total' => $total, 'latest_end_time' => $latest],
            );
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    // -------------------------------------------------------------- //
    // Horizon / queues
    // -------------------------------------------------------------- //

    private function probeHorizonQueues(): array
    {
        try {
            $conn = Redis::connection();
            // Horizon stores queue lengths under its own prefix. Sample
            // the default queue plus any "killmail"-namespaced queue so
            // the dashboard shows pressure without enumerating every
            // supervisor.
            $keys = [];
            foreach (['queues:default', 'queues:killmails', 'queues:ingest'] as $q) {
                $len = 0;
                try {
                    $len = (int) $conn->llen($q);
                } catch (Throwable) {
                    // keep going
                }
                $keys[$q] = $len;
            }
            $total = array_sum($keys);
            $detail = [];
            foreach ($keys as $k => $v) {
                if ($v > 0) {
                    $detail[] = str_replace('queues:', '', $k).'='.$v;
                }
            }
            $level = $total < 500 ? 'ok' : ($total < 5000 ? 'warn' : 'down');
            return self::level(
                $total === 0 ? 'all queues drained' : number_format($total).' queued · '.implode(', ', $detail),
                $level,
                ['queues' => $keys, 'total' => $total],
            );
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    private function probeFailedJobs(): array
    {
        try {
            $cutoff = now()->subDay();
            $count = DB::table('failed_jobs')
                ->where('failed_at', '>=', $cutoff)
                ->count();
            $level = $count === 0 ? 'ok' : ($count < 50 ? 'warn' : 'down');
            return self::level(
                $count === 0 ? 'no failures in 24h' : number_format($count).' failures in last 24h',
                $level,
                ['count' => $count],
            );
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    // -------------------------------------------------------------- //
    // Derived-store health (Neo4j / OpenSearch / Market / ESI)
    // -------------------------------------------------------------- //

    private function probeNeo4jEdges(): array
    {
        try {
            $svc = app(AllegianceGraphService::class);
            $reflect = new \ReflectionClass($svc);
            $method = $reflect->getMethod('client');
            $method->setAccessible(true);
            $client = $method->invoke($svc);
            $result = $client->run('MATCH ()-[r:ALLIED_WITH]-() RETURN count(r) as n');
            $allied = (int) ($result->first()?->get('n') ?? 0);
            $result = $client->run('MATCH ()-[r:OPPOSED]-() RETURN count(r) as n');
            $opposed = (int) ($result->first()?->get('n') ?? 0);
            // Symmetric relationships double-count, so halve.
            $allied = (int) ($allied / 2);
            $opposed = (int) ($opposed / 2);
            return self::level(
                number_format($allied).' allied · '.number_format($opposed).' opposed',
                'ok',
                ['allied' => $allied, 'opposed' => $opposed],
            );
        } catch (Throwable $e) {
            return self::level('Neo4j unreachable', 'warn', ['error' => $e->getMessage()]);
        }
    }

    private function probeMarketHistory(): array
    {
        try {
            $row = DB::table('market_history')
                ->where('region_id', 10000002)
                ->selectRaw('MAX(trade_date) as latest, COUNT(*) as n')
                ->first();
            if ($row === null || $row->latest === null) {
                return self::level('no market rows', 'down');
            }
            $days = now()->startOfDay()->diffInDays(Carbon::parse($row->latest)->startOfDay(), absolute: true);
            // EVE Ref daily dump plus live poller → current day or
            // day-before should always be present.
            $level = $days <= 1 ? 'ok' : ($days <= 3 ? 'warn' : 'down');
            return self::level(
                'Jita latest='.$row->latest.' · '.(int) $days.'d old',
                $level,
                ['latest' => $row->latest, 'rows' => (int) $row->n],
            );
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    private function probeEsiBacklog(): array
    {
        try {
            $resolved = (int) DB::table('esi_entity_names')->count();
            // Pending characters = distinct killmail attacker/victim
            // chars not yet in the name cache. Rough upper bound:
            // sample the last 1k killmails to keep this cheap.
            // MariaDB rejects LIMIT inside IN-subquery, so fetch the
            // recent killmail_id list first and pass as an array.
            $recentKmIds = DB::table('killmails')
                ->orderByDesc('killed_at')
                ->limit(1000)
                ->pluck('killmail_id')
                ->all();
            $pendingN = 0;
            if ($recentKmIds !== []) {
                $pendingN = (int) DB::table('killmail_attackers as a')
                    ->leftJoin('esi_entity_names as n', 'n.entity_id', '=', 'a.character_id')
                    ->whereIn('a.killmail_id', $recentKmIds)
                    ->whereNotNull('a.character_id')
                    ->whereNull('n.entity_id')
                    ->distinct()
                    ->count('a.character_id');
            }
            $level = $pendingN < 200 ? 'ok' : ($pendingN < 1000 ? 'warn' : 'down');
            return self::level(
                number_format($resolved).' resolved · '.number_format($pendingN).' pending (recent 1k kms)',
                $level,
                ['resolved' => $resolved, 'pending' => $pendingN],
            );
        } catch (Throwable $e) {
            return self::level('probe failed: '.$e->getMessage(), 'down');
        }
    }

    private function probeOpenSearchDocs(): array
    {
        try {
            $host = (string) config('aegiscore.opensearch.host', 'http://opensearch:9200');
            $resp = Http::timeout(2)->get(rtrim($host, '/').'/killmails/_count');
            if (! $resp->ok()) {
                return self::level('HTTP '.$resp->status(), 'down');
            }
            $count = (int) ($resp->json('count') ?? 0);
            return self::level(number_format($count).' docs indexed', 'ok', ['count' => $count]);
        } catch (Throwable $e) {
            return self::level('OpenSearch unreachable', 'warn', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------- //

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function level(string $detail, string $level, array $extra = []): array
    {
        return array_merge(['detail' => $detail, 'level' => $level], $extra);
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return (int) floor($seconds / 60).'m';
        }
        if ($seconds < 86400) {
            return sprintf('%dh %dm', floor($seconds / 3600), floor(($seconds % 3600) / 60));
        }
        return (int) floor($seconds / 86400).'d';
    }
}
