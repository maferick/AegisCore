"""Counter-Intel Dossier — Commit 3: GDS similarity + graph scores.

Runs three GDS passes over the :CICharacter subgraph projected by
counter_intel.projection:

  1. Z-normalize the 13-dim feature vector per :CICharacter and store
     as `feature_vector` list property (done in cypher before GDS).
  2. gds.knn.write on feature_vector → `[:CI_SIMILAR_TO {score}]`
     edges. topK + similarityCutoff tunable via env.
  3. gds.betweenness + gds.pageRank on the :CI_CO_OCCURS_WITH subgraph,
     write back as `betweenness` / `pagerank` node properties.

All outputs are viewer-agnostic structural scores. Cross-partition
friendly/hostile metrics are deferred to commit 4 (anomaly compute)
where they resolve per-viewer-bloc.

Idempotent — reruns overwrite the CI_SIMILAR_TO set and the
betweenness/pagerank props.
"""

from __future__ import annotations

from neo4j import Driver

from counter_intel.config import Config
from counter_intel.db import neo_session
from counter_intel.log import get

log = get("counter_intel.similarity")


FEATURE_DIMS = [
    "battles", "active_days", "avg_gang_size", "solo_ratio",
    "role_fc_pct", "role_logi_pct", "role_bomber_pct",
    "role_command_pct", "role_tackle_pct", "role_dps_pct",
    "cooccurrence_density", "same_side_ratio", "affiliation_churn_rate",
]


def run(driver: Driver, cfg: Config, top_k: int = 100, sim_cutoff: float = 0.60) -> dict:
    stats: dict = {}

    with neo_session(driver, cfg) as sess:
        # 1) Compute mean + stddev per feature over sufficient-history
        #    nodes. Returned to Python so we can inject into the
        #    z-score cypher as parameters.
        agg = _feature_stats(sess)
        stats["features_stats"] = {d: (round(m, 4), round(s, 4)) for d, (m, s) in agg.items()}
        log.info("feature stats computed", {"n_dims": len(agg)})

        # 2) Write normalized z-scored feature_vector onto each node.
        n_written = _write_feature_vectors(sess, agg)
        stats["feature_vectors_written"] = n_written
        log.info("feature vectors written", {"n": n_written})

        # 3) Drop any existing GDS graphs with our names so reruns are
        #    clean.
        for gname in ("ci_similarity", "ci_cooccurs"):
            sess.run("CALL gds.graph.drop($name, false) YIELD graphName RETURN graphName", name=gname)

        # 4) Project similarity graph + run knn.write.
        n_similar = _knn(sess, top_k=top_k, sim_cutoff=sim_cutoff)
        stats["similar_edges"] = n_similar
        log.info("gds.knn complete", {"edges": n_similar})

        # 5) Graph-theoretic scores on the co-occurs subgraph.
        _graph_scores(sess)
        log.info("graph scores written")

    return stats


def _feature_stats(sess) -> dict[str, tuple[float, float]]:
    """Compute mean + stddev for each of the 13 features across
    sufficient-history :CICharacter nodes. Returns {dim: (mean, stddev)}.
    Empty cohort raises — upstream code should prevent this."""
    agg: dict[str, tuple[float, float]] = {}
    for d in FEATURE_DIMS:
        rec = sess.run(
            f"""
            MATCH (c:CICharacter {{has_sufficient_history: 1}})
            WITH c.{d} AS v
            WITH collect(v) AS vs
            WITH vs, size(vs) AS n
            WITH vs, n, reduce(s = 0.0, x IN vs | s + x) / n AS mean
            WITH vs, n, mean,
                 reduce(s = 0.0, x IN vs | s + (x - mean) * (x - mean)) / n AS var
            RETURN mean, sqrt(var) AS stddev
            """
        ).single()
        mean = float(rec["mean"] or 0.0)
        stddev = float(rec["stddev"] or 0.0)
        if stddev < 1e-6:
            stddev = 1e-6
        agg[d] = (mean, stddev)
    return agg


def _write_feature_vectors(sess, agg: dict[str, tuple[float, float]]) -> int:
    """Write z-scored feature_vector list onto every sufficient-history
    :CICharacter node. Cold-start nodes skip — they aren't scored."""
    z_expressions = []
    params = {}
    for i, d in enumerate(FEATURE_DIMS):
        mean, stddev = agg[d]
        params[f"m_{i}"] = mean
        params[f"s_{i}"] = stddev
        z_expressions.append(f"(c.{d} - $m_{i}) / $s_{i}")
    vec_expr = "[" + ", ".join(z_expressions) + "]"
    rec = sess.run(
        f"""
        MATCH (c:CICharacter {{has_sufficient_history: 1}})
        SET c.feature_vector = {vec_expr}
        RETURN count(c) AS n
        """,
        **params,
    ).single()
    return int(rec["n"])


def _knn(sess, top_k: int, sim_cutoff: float) -> int:
    """gds.graph.project + gds.knn.write. Returns edge count written."""
    # Project sufficient-history nodes only. Native projection needs
    # both node and rel spec but we can stream nodes via cypher query.
    sess.run(
        """
        CALL gds.graph.project.cypher(
          'ci_similarity',
          'MATCH (c:CICharacter {has_sufficient_history: 1}) RETURN id(c) AS id, c.feature_vector AS feature_vector',
          'MATCH (a:CICharacter)-[r:CI_CO_OCCURS_WITH]->(b:CICharacter) RETURN id(a) AS source, id(b) AS target'
        )
        """
    )
    rec = sess.run(
        """
        CALL gds.knn.write('ci_similarity', {
          nodeProperties: ['feature_vector'],
          topK: $top_k,
          similarityCutoff: $cutoff,
          writeRelationshipType: 'CI_SIMILAR_TO',
          writeProperty: 'score'
        })
        YIELD relationshipsWritten
        RETURN relationshipsWritten
        """,
        top_k=top_k, cutoff=sim_cutoff,
    ).single()
    return int(rec["relationshipsWritten"])


def _graph_scores(sess) -> None:
    """Project the co-occurs subgraph, run pageRank + betweenness, write
    back to :CICharacter nodes."""
    sess.run(
        """
        CALL gds.graph.project(
          'ci_cooccurs',
          {CICharacter: {properties: []}},
          {CI_CO_OCCURS_WITH: {orientation: 'UNDIRECTED'}}
        )
        """
    )
    # pageRank — structural importance in the co-occurs network.
    sess.run(
        """
        CALL gds.pageRank.write('ci_cooccurs', {
          writeProperty: 'pagerank',
          maxIterations: 20,
          dampingFactor: 0.85
        }) YIELD nodePropertiesWritten
        RETURN nodePropertiesWritten
        """
    )
    # Betweenness — bridging behaviour. Sampled (500 nodes) because
    # exact is O(V·E); gds.betweenness supports sampling via
    # samplingSize.
    sess.run(
        """
        CALL gds.betweenness.write('ci_cooccurs', {
          writeProperty: 'betweenness',
          samplingSize: 500
        }) YIELD nodePropertiesWritten
        RETURN nodePropertiesWritten
        """
    )
