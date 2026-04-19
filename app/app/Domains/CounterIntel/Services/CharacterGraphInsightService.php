<?php

declare(strict_types=1);

namespace App\Domains\CounterIntel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Throwable;

/**
 * Per-character read-only views over the counter-intel Neo4j graph.
 *
 * Surfaces three panels on the portal character card:
 *   - Flight crew    → top CI_CO_OCCURS_WITH peers by total_weight
 *   - Arch-enemies   → top CI_FOUGHT_AGAINST peers by total_weight
 *   - Structural rank→ pagerank + betweenness (+ percentile vs the
 *                      sufficient-history population)
 *
 * All queries are best-effort — if Neo4j is unreachable the portal
 * card renders without the panel, never 500s.
 */
final class CharacterGraphInsightService
{
    private const CACHE_TTL_SECONDS = 900;  // 15 min — graph refreshes daily.
    private const QUERY_TIMEOUT_SECONDS = 4;

    public function __construct(private readonly ?ClientInterface $client = null) {}

    /**
     * @return list<array{character_id: int, name: ?string, alliance_id: ?int, total_weight: float, distinct_interactions: int, last_seen_at: ?string}>
     */
    public function flightCrew(int $cid, int $limit = 8): array
    {
        return $this->safeCache("ci.insight.fc.{$cid}.{$limit}", function () use ($cid, $limit): array {
            $c = $this->client();
            if ($c === null) return [];
            $res = $c->run(
                'MATCH (me:CICharacter {character_id: $cid})-[r:CI_CO_OCCURS_WITH]-(peer:CICharacter)
                 RETURN peer.character_id AS cid, peer.name AS name, peer.current_alliance_id AS aid,
                        r.total_weight AS tw, r.distinct_interactions AS di, r.last_seen_at AS last
                 ORDER BY r.total_weight DESC
                 LIMIT $lim',
                ['cid' => $cid, 'lim' => $limit],
            );
            return $this->hydrate($res);
        });
    }

    /**
     * @return list<array{character_id: int, name: ?string, alliance_id: ?int, total_weight: float, distinct_interactions: int, last_seen_at: ?string}>
     */
    public function archEnemies(int $cid, int $limit = 8): array
    {
        return $this->safeCache("ci.insight.ae.{$cid}.{$limit}", function () use ($cid, $limit): array {
            $c = $this->client();
            if ($c === null) return [];
            $res = $c->run(
                'MATCH (me:CICharacter {character_id: $cid})-[r:CI_FOUGHT_AGAINST]-(peer:CICharacter)
                 RETURN peer.character_id AS cid, peer.name AS name, peer.current_alliance_id AS aid,
                        r.total_weight AS tw, r.distinct_interactions AS di, r.last_seen_at AS last
                 ORDER BY r.total_weight DESC
                 LIMIT $lim',
                ['cid' => $cid, 'lim' => $limit],
            );
            return $this->hydrate($res);
        });
    }

    /**
     * Structural rank vs the whole sufficient-history population:
     *   pagerank  — importance in the co-fighting graph
     *   betweenness — bridging between fleet clusters
     * Returns null if Neo4j doesn't have this character yet.
     *
     * @return array{pagerank: float, pagerank_pct: float, betweenness: float, betweenness_pct: float}|null
     */
    public function structuralRank(int $cid): ?array
    {
        return $this->safeCache("ci.insight.rank.{$cid}", function () use ($cid): ?array {
            $c = $this->client();
            if ($c === null) return null;
            $res = $c->run(
                'MATCH (me:CICharacter {character_id: $cid})
                 WITH me.pagerank AS pr, me.betweenness AS bw
                 OPTIONAL MATCH (other:CICharacter {has_sufficient_history: 1})
                 WITH pr, bw,
                      count(other) AS n,
                      sum(CASE WHEN other.pagerank < pr THEN 1 ELSE 0 END) AS pr_below,
                      sum(CASE WHEN other.betweenness < bw THEN 1 ELSE 0 END) AS bw_below
                 RETURN pr, bw, n, pr_below, bw_below',
                ['cid' => $cid],
            );
            $row = $res->first() ?? null;
            if ($row === null) return null;
            $pr = (float) ($row->get('pr') ?? 0);
            $bw = (float) ($row->get('bw') ?? 0);
            $n = (int) ($row->get('n') ?? 0);
            if ($n === 0) return null;
            $prPct = round(((int) $row->get('pr_below')) / $n * 100, 1);
            $bwPct = round(((int) $row->get('bw_below')) / $n * 100, 1);
            return [
                'pagerank' => $pr,
                'pagerank_pct' => $prPct,
                'betweenness' => $bw,
                'betweenness_pct' => $bwPct,
                'cohort_size' => $n,
            ];
        });
    }

    /**
     * @param  mixed  $res
     * @return list<array<string, mixed>>
     */
    private function hydrate($res): array
    {
        $out = [];
        foreach ($res as $row) {
            $peerCid = (int) ($row->get('cid') ?? 0);
            if ($peerCid <= 0) continue;
            $aid = $row->get('aid');
            $last = $row->get('last');
            $out[] = [
                'character_id' => $peerCid,
                'name' => $row->get('name') ? (string) $row->get('name') : null,
                'alliance_id' => $aid ? (int) $aid : null,
                'total_weight' => (float) ($row->get('tw') ?? 0),
                'distinct_interactions' => (int) ($row->get('di') ?? 0),
                'last_seen_at' => $last ? (string) $last : null,
            ];
        }
        return $out;
    }

    private function safeCache(string $key, callable $fn): mixed
    {
        try {
            return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($fn) {
                try {
                    return $fn();
                } catch (Throwable $e) {
                    Log::warning('ci.insight: query failed', ['error' => $e->getMessage()]);
                    return null;
                }
            });
        } catch (Throwable $e) {
            Log::warning('ci.insight: cache failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function client(): ?ClientInterface
    {
        if ($this->client !== null) return $this->client;
        $host = (string) config('aegiscore.neo4j.host');
        $user = (string) config('aegiscore.neo4j.user');
        $password = (string) config('aegiscore.neo4j.password');
        if ($host === '' || $user === '' || $password === '') return null;
        try {
            return ClientBuilder::create()
                ->withDriver('default', $host, Authenticate::basic($user, $password))
                ->withDefaultDriver('default')
                ->build();
        } catch (Throwable $e) {
            Log::warning('ci.insight: client init failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
