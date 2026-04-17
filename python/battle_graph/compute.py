"""Neo4j write, GDS project, algorithm execution, readback, cleanup.

Projection names and node/edge tags all include run_id so concurrent
runs on different (battle, alliance, profile) tuples can't collide in
Neo4j. Cleanup is compensating (run on every exit path) rather than
transactional because Neo4j doesn't offer cross-query atomicity for
GDS operations.
"""

from __future__ import annotations

from dataclasses import dataclass

from neo4j import Session

from battle_graph.log import get
from battle_graph.profiles import AlgoProfile

log = get(__name__)

_NODE_BATCH = 1000
_EDGE_BATCH = 2000


@dataclass
class MetricsRow:
    character_id: int
    weighted_degree: float | None = None
    pagerank: float | None = None
    betweenness: float | None = None
    clustering_coefficient: float | None = None
    community_id_raw: int | None = None
    community_size: int | None = None
    community_rank_by_size: int | None = None


def projection_name(run_id: int) -> str:
    return f"battle_graph_run_{run_id}"


def write_graph(
    session: Session,
    run_id: int,
    battle_id: int,
    alliance_id: int,
    pilots: list[int],
    edges: list[dict],
) -> None:
    # Nodes — MERGE so partial retries don't duplicate.
    for i in range(0, len(pilots), _NODE_BATCH):
        chunk = pilots[i:i + _NODE_BATCH]
        session.run(
            """
            UNWIND $pilots AS cid
            MERGE (c:Character {character_id: cid, run_id: $run_id})
            SET c.battle_id = $battle_id,
                c.alliance_id = $alliance_id
            """,
            pilots=chunk,
            run_id=run_id,
            battle_id=battle_id,
            alliance_id=alliance_id,
        )

    # Edges — CREATE (not MERGE) because cleanup has already dropped
    # any prior state tagged with this run_id and the batch is
    # internally de-duplicated. MERGE on relationships with property
    # matching was ~60× slower on a 572k-edge projection (6 min →
    # ~10 s observed).
    for i in range(0, len(edges), _EDGE_BATCH):
        chunk = edges[i:i + _EDGE_BATCH]
        session.run(
            """
            UNWIND $edges AS e
            MATCH (a:Character {character_id: e.a, run_id: $run_id})
            MATCH (b:Character {character_id: e.b, run_id: $run_id})
            CREATE (a)-[r:CO_ENGAGED {
                run_id: $run_id,
                battle_id: $battle_id,
                weight: e.weight,
                same_bucket: e.same_bucket,
                victim_overlap: e.victim_overlap,
                phase_cooccur: e.phase_cooccur
            }]->(b)
            """,
            edges=chunk,
            run_id=run_id,
            battle_id=battle_id,
        )


def project_gds(session: Session, run_id: int) -> None:
    name = projection_name(run_id)
    # Cypher projection scoped to this run via the run_id property.
    session.run(
        """
        CALL gds.graph.project.cypher(
            $name,
            'MATCH (c:Character {run_id: $run_id}) RETURN id(c) AS id',
            'MATCH (a:Character {run_id: $run_id})-[r:CO_ENGAGED {run_id: $run_id}]-(b:Character {run_id: $run_id})
             RETURN id(a) AS source, id(b) AS target, r.weight AS weight',
            { parameters: { run_id: $run_id } }
        )
        YIELD graphName
        RETURN graphName
        """,
        name=name,
        run_id=run_id,
    )


def drop_gds(session: Session, run_id: int) -> None:
    name = projection_name(run_id)
    session.run(
        "CALL gds.graph.drop($name, false) YIELD graphName RETURN graphName",
        name=name,
    )


def run_algorithms(
    session: Session,
    run_id: int,
    algo: AlgoProfile,
    tier: str,
) -> dict[int, MetricsRow]:
    """Run the algorithms permitted for this tier + algo profile, read
    results back, and return a dict keyed by character_id.

    Tier policy (Spec 2 § 4):
      - small:  caller must have returned before reaching here
      - medium: weighted degree + PageRank + Louvain
      - large:  weighted degree + PageRank + Louvain
      - huge:   weighted degree only by default; PageRank and Louvain
                only if algo-profile flags are on
    Clustering coefficient and betweenness are independently toggled
    and default off.
    """
    out: dict[int, MetricsRow] = {}
    name = projection_name(run_id)

    # Weighted degree — always runs.
    res = session.run(
        """
        CALL gds.degree.stream($name, { relationshipWeightProperty: 'weight' })
        YIELD nodeId, score
        RETURN gds.util.asNode(nodeId).character_id AS cid, score
        """,
        name=name,
    )
    for r in res:
        cid = int(r["cid"])
        row = out.setdefault(cid, MetricsRow(character_id=cid))
        row.weighted_degree = float(r["score"])

    # PageRank
    run_pr = algo.run_pagerank and (tier != "huge" or algo.run_pagerank)
    if run_pr:
        res = session.run(
            """
            CALL gds.pageRank.stream($name, {
                relationshipWeightProperty: 'weight',
                dampingFactor: $damping,
                maxIterations: $max_iter
            })
            YIELD nodeId, score
            RETURN gds.util.asNode(nodeId).character_id AS cid, score
            """,
            name=name,
            damping=algo.pagerank_damping,
            max_iter=algo.pagerank_max_iterations,
        )
        for r in res:
            cid = int(r["cid"])
            row = out.setdefault(cid, MetricsRow(character_id=cid))
            row.pagerank = float(r["score"])

    if algo.run_betweenness:
        res = session.run(
            """
            CALL gds.betweenness.stream($name)
            YIELD nodeId, score
            RETURN gds.util.asNode(nodeId).character_id AS cid, score
            """,
            name=name,
        )
        for r in res:
            cid = int(r["cid"])
            row = out.setdefault(cid, MetricsRow(character_id=cid))
            row.betweenness = float(r["score"])

    if algo.run_clustering_coefficient:
        res = session.run(
            """
            CALL gds.localClusteringCoefficient.stream($name)
            YIELD nodeId, localClusteringCoefficient
            RETURN gds.util.asNode(nodeId).character_id AS cid,
                   localClusteringCoefficient AS score
            """,
            name=name,
        )
        for r in res:
            cid = int(r["cid"])
            row = out.setdefault(cid, MetricsRow(character_id=cid))
            row.clustering_coefficient = float(r["score"])

    # Louvain. GDS 2.x removed the `randomSeed` config key; determinism
    # across runs is not guaranteed by GDS, which is why Spec 2 only
    # promises stability at the community_rank_by_size layer after
    # size-desc relabel + lowest-char tie-break (see relabel below).
    run_lv = algo.run_louvain and (tier != "huge" or algo.run_louvain)
    if run_lv:
        res = session.run(
            """
            CALL gds.louvain.stream($name, {
                relationshipWeightProperty: 'weight',
                maxIterations: $max_iter,
                tolerance: $tol
            })
            YIELD nodeId, communityId
            RETURN gds.util.asNode(nodeId).character_id AS cid,
                   communityId
            """,
            name=name,
            max_iter=algo.louvain_max_iterations,
            tol=algo.louvain_tolerance,
        )
        raw: dict[int, int] = {}
        for r in res:
            cid = int(r["cid"])
            raw[cid] = int(r["communityId"])

        # Relabel by size DESC with deterministic tie-break (lowest
        # member char id). Consumers key off community_rank_by_size
        # because raw Louvain labels aren't stable across runs.
        size_of: dict[int, int] = {}
        lowest_of: dict[int, int] = {}
        for cid, comm in raw.items():
            size_of[comm] = size_of.get(comm, 0) + 1
            if comm not in lowest_of or cid < lowest_of[comm]:
                lowest_of[comm] = cid
        ordered = sorted(
            size_of.keys(),
            key=lambda c: (-size_of[c], lowest_of[c]),
        )
        rank_of = {c: rank for rank, c in enumerate(ordered)}

        for cid, comm in raw.items():
            row = out.setdefault(cid, MetricsRow(character_id=cid))
            row.community_id_raw = comm
            row.community_size = size_of[comm]
            row.community_rank_by_size = rank_of[comm]

    return out


def cleanup_neo4j(session: Session, run_id: int) -> None:
    # Best-effort — either step may fail on a broken Neo4j; swallow
    # to keep the run row final-state consistent.
    try:
        drop_gds(session, run_id)
    except Exception as exc:
        log.warning("drop_gds failed", run_id=run_id, error=str(exc))
    # Batched delete via APOC — a bulk DETACH DELETE of a 1000-node /
    # 900k-edge run blew past Neo4j's per-tx memory cap (2026-04-17:
    # 892k relationships, capped at 512M). Batching to 500 keeps each
    # tx small enough to always commit.
    try:
        session.run(
            """
            CALL apoc.periodic.iterate(
                'MATCH (c:Character {run_id: $run_id}) RETURN c',
                'DETACH DELETE c',
                {batchSize: 500, parallel: false, params: {run_id: $run_id}}
            )
            """,
            run_id=run_id,
        )
    except Exception as exc:
        log.warning("node delete failed", run_id=run_id, error=str(exc))
