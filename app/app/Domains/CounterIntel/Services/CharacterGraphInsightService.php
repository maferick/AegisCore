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
    // 6 hours. Source data refreshes daily (ci-projection cron is
    // 04:55 UTC). The previous 15-min TTL was 16× shorter than the
    // upstream cadence and re-ran the cypher 60+ times/day per
    // popular cid for no fresher answer. 6h still gives ~4 cache
    // misses per day — leaves headroom for ad-hoc projection runs
    // without burning Neo4j read traffic.
    private const CACHE_TTL_SECONDS = 21600;
    private const QUERY_TIMEOUT_SECONDS = 4;

    public function __construct(private readonly ?ClientInterface $client = null) {}

    /**
     * @return list<array{character_id: int, name: ?string, alliance_id: ?int, total_weight: float, distinct_interactions: int, last_seen_at: ?string, current_relationship: string}>
     */
    public function flightCrew(int $cid, int $limit = 8): array
    {
        $r = $this->safeCache("ci.insight.fc.{$cid}.v4.{$limit}", function () use ($cid, $limit): array {
            $c = $this->client();
            if ($c === null) return [];
            // For "who does this pilot fly with?" the honest signal is
            // raw shared-killmail count. Every co-flight counts the
            // same whether it was a 500-pilot blob or a 3-man roam.
            // The weighted/dampened total_weight exists for counter-
            // intel anomaly detection, which is a different use case
            // and a different consumer.
            $res = $c->run(
                'MATCH (me:CICharacter {character_id: $cid})-[r:CI_CO_OCCURS_WITH]-(peer:CICharacter)
                 RETURN peer.character_id AS cid, peer.name AS name, peer.current_alliance_id AS aid,
                        r.event_count AS ec, r.distinct_interactions AS di,
                        r.total_weight AS tw, r.last_seen_at AS last
                 ORDER BY r.event_count DESC, r.distinct_interactions DESC
                 LIMIT $lim',
                ['cid' => $cid, 'lim' => $limit],
            );
            $rows = $this->hydrateFlightCrew($res);
            return $this->tagRelationship($cid, $rows);
        });
        // safeCache returns null on Neo4j errors (transient thread-pool
        // exhaustion, network blips, etc.). Strict array return type
        // means we coerce null → [] for the dossier consumers.
        return is_array($r) ? $r : [];
    }

    /**
     * Flight-crew hydration carries event_count separately from the
     * session count so the UI can render the raw "N shared kills" and
     * still keep the weighted / session fields for later consumers.
     *
     * @param  mixed  $res
     * @return list<array<string, mixed>>
     */
    private function hydrateFlightCrew($res): array
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
                'event_count' => (int) ($row->get('ec') ?? 0),
                'distinct_interactions' => (int) ($row->get('di') ?? 0),
                'total_weight' => (float) ($row->get('tw') ?? 0),
                'last_seen_at' => $last ? (string) $last : null,
            ];
        }
        return $out;
    }

    /**
     * For each peer row, compare their current alliance's bloc with
     * the viewer's current alliance bloc. Annotates rows with a
     * `current_relationship` key: 'same_bloc' | 'hostile_bloc' |
     * 'unlabeled'. Lets the UI flag "flew with X · now hostile" so
     * stale-ally data from CI_CO_OCCURS_WITH doesn't read as a
     * live-ally claim after a bloc flip.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function tagRelationship(int $viewerCid, array $rows): array
    {
        if ($rows === []) return $rows;
        // Resolve viewer's current alliance. Try the linked-characters
        // table first (fast path for operators' own chars), then fall
        // back to character_corporation_history → corporation_alliance_
        // history so lookup pages work for any cid in our ingest.
        $viewerAlliance = \Illuminate\Support\Facades\DB::table('characters')
            ->where('character_id', $viewerCid)
            ->value('alliance_id');
        if (! $viewerAlliance) {
            $corpRow = \Illuminate\Support\Facades\DB::table('character_corporation_history')
                ->where('character_id', $viewerCid)
                ->where('is_deleted', 0)
                ->whereNull('end_date')
                ->orderByDesc('start_date')
                ->first();
            if ($corpRow !== null) {
                $allyRow = \Illuminate\Support\Facades\DB::table('corporation_alliance_history')
                    ->where('corporation_id', $corpRow->corporation_id)
                    ->whereNull('end_date')
                    ->orderByDesc('start_date')
                    ->first();
                if ($allyRow?->alliance_id) {
                    $viewerAlliance = (int) $allyRow->alliance_id;
                }
            }
        }
        $viewerBlocId = null;
        if ($viewerAlliance) {
            $viewerBlocId = \Illuminate\Support\Facades\DB::table('coalition_entity_labels')
                ->where('entity_type', 'alliance')
                ->where('entity_id', $viewerAlliance)
                ->where('is_active', 1)
                ->value('bloc_id');
            $viewerBlocId = $viewerBlocId !== null ? (int) $viewerBlocId : null;
        }

        $peerAllianceIds = array_values(array_unique(array_filter(array_column($rows, 'alliance_id'))));
        $blocByAid = [];
        if ($peerAllianceIds !== []) {
            \Illuminate\Support\Facades\DB::table('coalition_entity_labels')
                ->where('entity_type', 'alliance')
                ->whereIn('entity_id', $peerAllianceIds)
                ->where('is_active', 1)
                ->select('entity_id', 'bloc_id')
                ->get()
                ->each(function ($r) use (&$blocByAid): void {
                    $blocByAid[(int) $r->entity_id] = (int) $r->bloc_id;
                });
        }

        // Bloc-intel pair-behavior fallback. For peers whose alliance
        // has no ground-truth bloc label, aggregate
        // alliance_pair_behavior_rolling rows between EVERY alliance in
        // the viewer's bloc and the peer's alliance. A small WC-member
        // alliance might not have a direct pair row with OnlyFleets.,
        // but the bloc as a whole does. Use confidence-weighted mean
        // affinity/hostility so a 3-observation outlier can't flip a
        // strong cross-bloc signal.
        $pairMetrics = [];
        if ($peerAllianceIds !== [] && $viewerBlocId !== null) {
            $viewerBlocAllianceIds = \Illuminate\Support\Facades\DB::table('coalition_entity_labels')
                ->where('entity_type', 'alliance')
                ->where('bloc_id', $viewerBlocId)
                ->where('is_active', 1)
                ->pluck('entity_id')
                ->map(fn ($v) => (int) $v)
                ->all();
            if ($viewerBlocAllianceIds !== []) {
                \Illuminate\Support\Facades\DB::table('alliance_pair_behavior_rolling')
                    ->where(function ($q) use ($peerAllianceIds, $viewerBlocAllianceIds): void {
                        $q->where(function ($qq) use ($peerAllianceIds, $viewerBlocAllianceIds): void {
                            $qq->whereIn('alliance_a_id', $viewerBlocAllianceIds)->whereIn('alliance_b_id', $peerAllianceIds);
                        })->orWhere(function ($qq) use ($peerAllianceIds, $viewerBlocAllianceIds): void {
                            $qq->whereIn('alliance_b_id', $viewerBlocAllianceIds)->whereIn('alliance_a_id', $peerAllianceIds);
                        });
                    })
                    ->select('alliance_a_id', 'alliance_b_id', 'affinity_score', 'hostility_score', 'confidence')
                    ->get()
                    ->each(function ($r) use (&$pairMetrics, $viewerBlocAllianceIds): void {
                        $blocFlip = array_flip($viewerBlocAllianceIds);
                        $peerAid = (int) (isset($blocFlip[(int) $r->alliance_a_id]) ? $r->alliance_b_id : $r->alliance_a_id);
                        $pairMetrics[$peerAid] ??= ['aff_num' => 0.0, 'hos_num' => 0.0, 'conf_sum' => 0.0];
                        $w = (float) $r->confidence;
                        $pairMetrics[$peerAid]['aff_num'] += (float) $r->affinity_score * $w;
                        $pairMetrics[$peerAid]['hos_num'] += (float) $r->hostility_score * $w;
                        $pairMetrics[$peerAid]['conf_sum'] += $w;
                    });
                foreach ($pairMetrics as &$pm) {
                    if ($pm['conf_sum'] <= 0) { $pm = null; continue; }
                    $pm = [
                        'affinity' => $pm['aff_num'] / $pm['conf_sum'],
                        'hostility' => $pm['hos_num'] / $pm['conf_sum'],
                        'confidence' => min(1.0, $pm['conf_sum']),
                    ];
                }
                unset($pm);
            }
        }

        foreach ($rows as &$row) {
            $aid = (int) ($row['alliance_id'] ?? 0);
            $peerBloc = $aid > 0 ? ($blocByAid[$aid] ?? null) : null;
            if ($viewerBlocId !== null && $peerBloc !== null) {
                $row['current_relationship'] = $peerBloc === $viewerBlocId ? 'same_bloc' : 'hostile_bloc';
                continue;
            }
            // No ground-truth bloc either side. Try bloc-intel pair.
            $pm = $pairMetrics[$aid] ?? null;
            if ($pm !== null && $pm['confidence'] >= 0.4) {
                if ($pm['hostility'] >= 0.5) {
                    $row['current_relationship'] = 'hostile_bloc';
                    continue;
                }
                if ($pm['affinity'] >= 0.7) {
                    $row['current_relationship'] = 'same_bloc';
                    continue;
                }
            }
            $row['current_relationship'] = 'unlabeled';
        }
        unset($row);
        return $rows;
    }

    /**
     * @return list<array{character_id: int, name: ?string, alliance_id: ?int, total_weight: float, distinct_interactions: int, last_seen_at: ?string}>
     */
    public function archEnemies(int $cid, int $limit = 8): array
    {
        $r = $this->safeCache("ci.insight.ae.{$cid}.v4.{$limit}", function () use ($cid, $limit): array {
            // Killmail-level top victims is the primary signal here —
            // more consistent than Neo4j CI_FOUGHT_AGAINST, which only
            // fires when the same pair repeats across 2+ sessions at
            // ≥0.5 weight. Blob warfare + FC-vs-FC dynamics routinely
            // produce zero qualifying pairs for real fleet commanders,
            // leaving the panel with 1 result despite hundreds of
            // enemy alliance kills.
            // Group by victim character only — DO NOT group by
            // k.victim_alliance_id. That column is the alliance
            // snapshot at kill time, which would split a single peer
            // into N rows whenever they switched alliance during the
            // 90-day window AND would surface a historical label
            // long after the peer defected. Current alliance is
            // resolved below via character_corporation_history so the
            // panel reflects "where the peer is now".
            $rows = \Illuminate\Support\Facades\DB::select(<<<'SQL'
                SELECT k.victim_character_id AS cid,
                       en.name AS name,
                       COUNT(*) AS n_kms,
                       MAX(k.killed_at) AS last
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                  LEFT JOIN esi_entity_names en ON en.entity_id = k.victim_character_id AND en.category='character'
                 WHERE ka.character_id = ?
                   AND k.victim_character_id IS NOT NULL
                   AND k.victim_character_id <> ka.character_id
                   AND k.killed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                 GROUP BY k.victim_character_id, en.name
                 ORDER BY n_kms DESC
                 LIMIT ?
            SQL, [$cid, $limit]);

            // Resolve current alliance per peer character from the
            // affiliation cache. Falls back to NULL when we don't
            // have a tracked corp/alliance for the peer; downstream
            // tagRelationship() handles the unlabeled case.
            $peerCids = array_values(array_unique(array_map(static fn ($r) => (int) $r->cid, $rows)));
            $currentAllianceByCid = $this->resolveCurrentAllianceMap($peerCids);

            $out = [];
            foreach ($rows as $r) {
                $peerCid = (int) $r->cid;
                $out[] = [
                    'character_id' => $peerCid,
                    'name' => $r->name ? (string) $r->name : null,
                    'alliance_id' => $currentAllianceByCid[$peerCid] ?? null,
                    'total_weight' => 0.0,
                    'distinct_interactions' => (int) $r->n_kms,
                    'last_seen_at' => $r->last ? (string) $r->last : null,
                ];
            }
            return $this->tagRelationship($cid, $out);
        });
        // safeCache may return null on cache or query exceptions —
        // strict array return contract.
        return is_array($r) ? $r : [];
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

    /**
     * Map character_id → current alliance_id derived from the live
     * affiliation cache (character_corporation_history → corporation
     * _alliance_history, latest non-deleted active row). Used by the
     * arch-enemies panel so the rendered label reflects where the
     * peer is *now*, not where they were when the killmail fired.
     *
     * @param  list<int>  $cids
     * @return array<int, int>
     */
    private function resolveCurrentAllianceMap(array $cids): array
    {
        if ($cids === []) return [];

        // Active corporation per character — newest start_date row
        // with end_date IS NULL.
        $corpRows = \Illuminate\Support\Facades\DB::table('character_corporation_history')
            ->whereIn('character_id', $cids)
            ->where('is_deleted', 0)
            ->whereNull('end_date')
            ->select('character_id', 'corporation_id', 'start_date')
            ->orderBy('character_id')
            ->orderByDesc('start_date')
            ->get();

        $corpByCid = [];
        foreach ($corpRows as $r) {
            $cid = (int) $r->character_id;
            // Keep the first occurrence per character — the orderByDesc
            // start_date above guarantees that's the latest active row.
            if (! isset($corpByCid[$cid])) {
                $corpByCid[$cid] = (int) $r->corporation_id;
            }
        }
        if ($corpByCid === []) return [];

        // Active alliance per corporation.
        $corpIds = array_values(array_unique(array_values($corpByCid)));
        $allianceRows = \Illuminate\Support\Facades\DB::table('corporation_alliance_history')
            ->whereIn('corporation_id', $corpIds)
            ->whereNull('end_date')
            ->select('corporation_id', 'alliance_id', 'start_date')
            ->orderBy('corporation_id')
            ->orderByDesc('start_date')
            ->get();

        $aidByCorp = [];
        foreach ($allianceRows as $r) {
            $corp = (int) $r->corporation_id;
            if (isset($aidByCorp[$corp])) continue;
            if ($r->alliance_id) {
                $aidByCorp[$corp] = (int) $r->alliance_id;
            }
        }

        $out = [];
        foreach ($corpByCid as $cid => $corp) {
            if (isset($aidByCorp[$corp])) {
                $out[$cid] = $aidByCorp[$corp];
            }
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
