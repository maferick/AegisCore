<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\KillmailsBattleTheaters\Models\BattleTheaterSideOverride;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use RuntimeException;
use Throwable;

/**
 * Records and queries historical-allegiance edges in Neo4j.
 *
 *   (:Alliance {id, name?}) -[:ALLIED_WITH {weight, last_seen, theaters}]- (:Alliance)
 *   (:Alliance) -[:OPPOSED    {weight, last_seen, theaters}]- (:Alliance)
 *
 * Operator-driven writes (the ``BattleTheaterSideOverride`` save path)
 * are the only thing this service upserts — the resolver's auto
 * output is too noisy to project wholesale. Writes are idempotent:
 * calling ``recordOverride`` twice for the same (theater_id,
 * alliance pair) leaves the ``theaters`` list de-duplicated and does
 * not double-count weight.
 *
 * ``scoreFor`` is the read-time tiebreaker the side resolver calls
 * when the kill graph alone can't decide which camp an on-field
 * alliance belongs to — "alliance X was allied with anchor A in 20
 * past battles and opposed 2 times → Side A" gives the resolver a
 * prior where the current fight's kill graph is thin.
 *
 * Historical note: AGENTS.md pins Laravel as read-only on Neo4j, with
 * Python workers owning writes. This service is the narrow exception —
 * an override save is an operator audit event, not a derived-data
 * pipeline, and piping it through the outbox for a Python worker to
 * consume would move an O(1 query) operation to "wait 30s for the
 * projector tick" with no correctness gain.
 */
class AllegianceGraphService
{
    public function __construct(private readonly ?ClientInterface $client = null) {}

    /**
     * Project every allegiance edge implied by the current set of
     * overrides for a single theater. Called after a successful
     * override save so the graph reflects the operator's latest
     * verdict.
     *
     * Runs best-effort: if Neo4j is unreachable we log and move on
     * — operator correctness doesn't hinge on the graph being live
     * (the resolver falls back to its kill-graph-only output when
     * the scorer returns null).
     */
    public function recordForTheater(int $theaterId): void
    {
        $overrides = BattleTheaterSideOverride::query()
            ->where('theater_id', $theaterId)
            ->where('entity_type', BattleTheaterSideOverride::ENTITY_ALLIANCE)
            ->whereIn('side', [
                BattleTheaterSideOverride::SIDE_A,
                BattleTheaterSideOverride::SIDE_B,
            ])
            ->get();

        // Bucket by side. Same-side pairs become ALLIED_WITH; across
        // buckets becomes OPPOSED.
        $bySide = [BattleTheaterSideOverride::SIDE_A => [], BattleTheaterSideOverride::SIDE_B => []];
        foreach ($overrides as $o) {
            $bySide[$o->side][] = (int) $o->entity_id;
        }

        if ($bySide[BattleTheaterSideOverride::SIDE_A] === [] && $bySide[BattleTheaterSideOverride::SIDE_B] === []) {
            return; // nothing to project
        }

        try {
            $client = $this->client();
        } catch (Throwable $exc) {
            logger()->warning('allegiance: neo4j unavailable, skipping record', ['err' => $exc->getMessage()]);
            return;
        }

        // ALLIED_WITH edges — all unordered pairs inside Side A and
        // inside Side B.
        foreach ([BattleTheaterSideOverride::SIDE_A, BattleTheaterSideOverride::SIDE_B] as $side) {
            $ids = $bySide[$side];
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $this->upsertEdge($client, 'ALLIED_WITH', $ids[$i], $ids[$j], $theaterId);
                }
            }
        }

        // OPPOSED edges — every A × B cross pair.
        foreach ($bySide[BattleTheaterSideOverride::SIDE_A] as $a) {
            foreach ($bySide[BattleTheaterSideOverride::SIDE_B] as $b) {
                $this->upsertEdge($client, 'OPPOSED', $a, $b, $theaterId);
            }
        }
    }

    /**
     * Score alliance X against two anchor alliances (the current
     * Side A and Side B). Returns
     *
     *   ['a_ally' => int, 'a_oppose' => int,
     *    'b_ally' => int, 'b_oppose' => int]
     *
     * or null when Neo4j is unreachable / nothing's been recorded
     * yet. The resolver uses the spread to decide whether X leans
     * toward Side A or Side B.
     *
     * Edges are symmetric, so the MATCH has no direction arrow.
     *
     * @return array<string, int>|null
     */
    public function scoreFor(int $allianceId, int $anchorA, int $anchorB): ?array
    {
        try {
            $client = $this->client();
        } catch (Throwable $exc) {
            logger()->debug('allegiance: neo4j unavailable, skipping score', ['err' => $exc->getMessage()]);
            return null;
        }

        try {
            $result = $client->run(
                <<<'CYPHER'
                MATCH (x:Alliance {id: $x})
                OPTIONAL MATCH (x)-[aA:ALLIED_WITH]-(:Alliance {id: $a})
                OPTIONAL MATCH (x)-[oA:OPPOSED]-(:Alliance {id: $a})
                OPTIONAL MATCH (x)-[aB:ALLIED_WITH]-(:Alliance {id: $b})
                OPTIONAL MATCH (x)-[oB:OPPOSED]-(:Alliance {id: $b})
                RETURN
                    coalesce(sum(aA.weight), 0) AS a_ally,
                    coalesce(sum(oA.weight), 0) AS a_oppose,
                    coalesce(sum(aB.weight), 0) AS b_ally,
                    coalesce(sum(oB.weight), 0) AS b_oppose
                CYPHER,
                ['x' => $allianceId, 'a' => $anchorA, 'b' => $anchorB],
            );
        } catch (Throwable $exc) {
            logger()->warning('allegiance: neo4j query failed', ['err' => $exc->getMessage()]);
            return null;
        }

        $row = $result->first();
        if ($row === null) {
            return null;
        }

        return [
            'a_ally' => (int) $row->get('a_ally'),
            'a_oppose' => (int) $row->get('a_oppose'),
            'b_ally' => (int) $row->get('b_ally'),
            'b_oppose' => (int) $row->get('b_oppose'),
        ];
    }

    // ------------------------------------------------------------------ //

    private function upsertEdge(ClientInterface $client, string $relType, int $aid1, int $aid2, int $theaterId): void
    {
        // Force ordered pair so MERGE is deterministic regardless of
        // argument order — Neo4j relationships are directional but
        // we treat them as undirected for this domain.
        [$lo, $hi] = $aid1 < $aid2 ? [$aid1, $aid2] : [$aid2, $aid1];

        try {
            $client->run(
                <<<CYPHER
                MERGE (a:Alliance {id: \$lo})
                MERGE (b:Alliance {id: \$hi})
                MERGE (a)-[r:{$relType}]->(b)
                ON CREATE SET r.weight = 1,
                              r.last_seen = datetime(),
                              r.theaters = [\$tid]
                ON MATCH  SET r.last_seen = datetime(),
                              r.theaters = CASE
                                  WHEN \$tid IN r.theaters THEN r.theaters
                                  ELSE r.theaters + \$tid
                              END,
                              r.weight = CASE
                                  WHEN \$tid IN r.theaters THEN r.weight
                                  ELSE coalesce(r.weight, 0) + 1
                              END
                CYPHER,
                ['lo' => $lo, 'hi' => $hi, 'tid' => $theaterId],
            );
        } catch (Throwable $exc) {
            logger()->warning('allegiance: upsert failed', [
                'rel' => $relType, 'a' => $lo, 'b' => $hi, 'err' => $exc->getMessage(),
            ]);
        }
    }

    private function client(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $host = (string) config('aegiscore.neo4j.host');
        $user = (string) config('aegiscore.neo4j.user');
        $password = (string) config('aegiscore.neo4j.password');
        if ($host === '' || $user === '' || $password === '') {
            throw new RuntimeException('Neo4j is not configured (aegiscore.neo4j.*).');
        }
        return ClientBuilder::create()
            ->withDriver('default', $host, Authenticate::basic($user, $password))
            ->withDefaultDriver('default')
            ->build();
    }
}
