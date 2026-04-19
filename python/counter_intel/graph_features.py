"""Counter-Intel Step 2 — graph-analytics features per viewer bloc.

Follows the Neo4j fraud-triage pattern:

  1. Community detection (Leiden, weighted by total_weight) on the
     INTERNAL subgraph (characters currently affiliated with an alliance
     in the viewer's own bloc, restricted to CI_CO_OCCURS_WITH edges
     where BOTH endpoints are internal). Produces ci_ring_id_90d +
     ci_ring_size_90d. Investigates "who lives in a tight community with
     whom" inside the bloc.

  2. Internal-scoped betweenness. Re-runs gds.betweenness on the
     internal-only subgraph so the score reflects bridging WITHIN the
     bloc's own fleet graph (the dossier's global betweenness on
     ci_character_anomalies_rolling covers the whole graph, including
     hostile intermediates).

  3. Seed-anchored similarity expansion. Seed set = internal characters
     flagged by raw signals (hostile affiliation history or high
     hostile-overlap count). For every internal, we count how many of
     its CI_SIMILAR_TO peers are in the seed set and track max
     similarity score. Persists as similarity_to_flagged_max +
     similarity_to_flagged_count.

These are review inputs, not verdicts. The dossier surfaces them as
"Shares a recurring co-flight ring with N flagged pilots" / "Acts as
an internal connector" / "Structurally similar to M pilots already
under review".
"""

from __future__ import annotations

from datetime import date, datetime, timezone

import pymysql
from neo4j import Driver

from counter_intel.config import Config
from counter_intel.db import neo_session
from counter_intel.log import get

log = get("counter_intel.graph_features")

# Seed thresholds. Kept deliberately conservative — we want the expansion
# step to add, not replace, the raw signal set.
SEED_MIN_HOSTILE_ALLIANCE_HISTORY = 2
SEED_MIN_HOSTILE_COOCCURRENCE = None  # resolved at runtime as p90 of internals


def compute(conn: pymysql.connections.Connection, driver: Driver, cfg: Config,
            viewer_bloc_id: int, window_end: date | None = None) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    log.info("graph features starting", {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    friendly_aids = _load_friendly_alliances(conn, viewer_bloc_id)
    internal_cids = _load_internal_characters(conn, friendly_aids)
    log.info("internal subject set", {"n": len(internal_cids)})
    if not internal_cids:
        return {"written": 0, "internal": 0}

    # Seed set from anomalies + features. Uses anomaly-table counters
    # already computed upstream; if anomalies haven't run yet for this
    # viewer bloc, fall back to history-only seeding.
    seed_cids = _resolve_seed_set(conn, viewer_bloc_id, internal_cids, window_end, cfg.window_days)
    log.info("seed set resolved", {"n": len(seed_cids)})

    # GDS pipeline on internal subgraph. We tag the internal nodes with
    # a transient label so native projection (which supports the
    # UNDIRECTED orientation Leiden requires) can filter to them.
    graph_name = f"ci_internal_b{viewer_bloc_id}"
    transient_label = f"CIInternal_b{viewer_bloc_id}"
    with neo_session(driver, cfg) as sess:
        _drop_graph(sess, graph_name)
        _tag_internal_nodes(sess, transient_label, internal_cids)
        try:
            _project_internal_subgraph(sess, graph_name, transient_label)
            rings = _run_leiden(sess, graph_name)
            bridges = _run_internal_betweenness(sess, graph_name)
        finally:
            _drop_graph(sess, graph_name)
            _untag_internal_nodes(sess, transient_label)
        flagged = _similarity_to_flagged(sess, internal_cids, seed_cids)

    rows = _build_rows(
        internal_cids=internal_cids,
        seed_cids=seed_cids,
        rings=rings,
        bridges=bridges,
        flagged=flagged,
        viewer_bloc_id=viewer_bloc_id,
        window_end=window_end,
        window_days=cfg.window_days,
    )
    _purge_stale(conn, viewer_bloc_id, internal_cids, window_end, cfg.window_days)
    _persist(conn, rows)
    log.info("graph features written", {"n": len(rows)})
    return {"written": len(rows), "internal": len(internal_cids), "seeds": len(seed_cids)}


# ----- subject + seed resolution --------------------------------------


def _load_friendly_alliances(conn, viewer_bloc_id: int) -> set[int]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT entity_id FROM coalition_entity_labels
             WHERE entity_type='alliance' AND is_active=1 AND bloc_id=%s
            """,
            (viewer_bloc_id,),
        )
        return {int(r["entity_id"]) for r in cur.fetchall()}


def _load_internal_characters(conn, friendly_aids: set[int]) -> set[int]:
    if not friendly_aids:
        return set()
    aph = ",".join(["%s"] * len(friendly_aids))
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT DISTINCT cch.character_id
              FROM character_corporation_history cch
              JOIN corporation_alliance_history cah ON cah.corporation_id = cch.corporation_id
             WHERE cch.is_deleted = 0
               AND (cch.end_date IS NULL OR cch.end_date > NOW())
               AND cah.alliance_id IN ({aph})
               AND (cah.end_date IS NULL OR cah.end_date > NOW())
               AND cah.start_date <= NOW()
            """,
            list(friendly_aids),
        )
        return {int(r["character_id"]) for r in cur.fetchall()}


def _resolve_seed_set(conn, viewer_bloc_id: int, internal_cids: set[int],
                      window_end: date, window_days: int) -> set[int]:
    """Seed = internal characters meeting at least one signal:
      - hostile_alliance_count_history ≥ SEED_MIN_HOSTILE_ALLIANCE_HISTORY
      - hostile_cooccurrence_count ≥ p90 of internals (runtime-computed)
    """
    if not internal_cids:
        return set()
    cid_list = sorted(internal_cids)
    seeds: set[int] = set()
    # Signal 1: history.
    batch = 10000
    for i in range(0, len(cid_list), batch):
        chunk = cid_list[i:i + batch]
        ph = ",".join(["%s"] * len(chunk))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT character_id
                  FROM ci_character_anomalies_rolling
                 WHERE viewer_bloc_id=%s
                   AND window_end_date=%s
                   AND window_days=%s
                   AND character_id IN ({ph})
                   AND hostile_alliance_count_history >= %s
                """,
                [viewer_bloc_id, window_end, window_days] + chunk + [SEED_MIN_HOSTILE_ALLIANCE_HISTORY],
            )
            for r in cur.fetchall():
                seeds.add(int(r["character_id"]))

    # Signal 2: hostile_cooccurrence_count p90.
    coocc_counts: list[int] = []
    for i in range(0, len(cid_list), batch):
        chunk = cid_list[i:i + batch]
        ph = ",".join(["%s"] * len(chunk))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT hostile_cooccurrence_count AS n
                  FROM ci_character_anomalies_rolling
                 WHERE viewer_bloc_id=%s AND window_end_date=%s AND window_days=%s
                   AND character_id IN ({ph})
                """,
                [viewer_bloc_id, window_end, window_days] + chunk,
            )
            for r in cur.fetchall():
                coocc_counts.append(int(r["n"] or 0))
    if coocc_counts:
        coocc_counts.sort()
        p90 = coocc_counts[int(len(coocc_counts) * 0.9)]
        # Don't let p90 collapse to 0 when distribution is skewed — require
        # at least one nonzero match.
        threshold = max(1, p90)
        for i in range(0, len(cid_list), batch):
            chunk = cid_list[i:i + batch]
            ph = ",".join(["%s"] * len(chunk))
            with conn.cursor() as cur:
                cur.execute(
                    f"""
                    SELECT character_id
                      FROM ci_character_anomalies_rolling
                     WHERE viewer_bloc_id=%s AND window_end_date=%s AND window_days=%s
                       AND character_id IN ({ph})
                       AND hostile_cooccurrence_count >= %s
                    """,
                    [viewer_bloc_id, window_end, window_days] + chunk + [threshold],
                )
                for r in cur.fetchall():
                    seeds.add(int(r["character_id"]))
        log.info("seed p90 hostile_cooccurrence threshold", {"threshold": threshold})
    return seeds


# ----- GDS pipeline ---------------------------------------------------


def _drop_graph(sess, name: str) -> None:
    sess.run("CALL gds.graph.drop($name, false) YIELD graphName RETURN graphName", name=name)


def _tag_internal_nodes(sess, label: str, internal_cids: set[int]) -> None:
    """Add a transient node label to every internal character so native
    GDS projection can filter to them. Chunked because UNWIND with 100k+
    ids is slow on a single transaction."""
    # Clear any old tag first.
    sess.run(f"MATCH (c:`{label}`) REMOVE c:`{label}`")
    ids = sorted(internal_cids)
    batch = 5000
    for i in range(0, len(ids), batch):
        sess.run(
            f"""
            UNWIND $ids AS cid
            MATCH (c:CICharacter {{character_id: cid}})
            SET c:`{label}`
            """,
            ids=ids[i:i + batch],
        )


def _untag_internal_nodes(sess, label: str) -> None:
    sess.run(f"MATCH (c:`{label}`) REMOVE c:`{label}`")


def _project_internal_subgraph(sess, name: str, transient_label: str) -> None:
    """Native GDS projection on the transient label + CI_CO_OCCURS_WITH
    edges, UNDIRECTED, weighted by total_weight. Native projection is
    required because Leiden rejects non-UNDIRECTED orientations."""
    sess.run(
        f"""
        CALL gds.graph.project(
          $name,
          ['{transient_label}'],
          {{
            CI_CO_OCCURS_WITH: {{
              orientation: 'UNDIRECTED',
              properties: {{
                weight: {{ property: 'total_weight', defaultValue: 0.0 }}
              }}
            }}
          }}
        )
        """,
        name=name,
    )


def _run_leiden(sess, graph_name: str) -> dict[int, tuple[int, int]]:
    """Leiden community detection, weighted. Returns
    character_id → (ring_id, ring_size)."""
    sess.run(
        """
        CALL gds.leiden.mutate($gn, {
          relationshipWeightProperty: 'weight',
          mutateProperty: 'ring_id'
        }) YIELD communityCount, modularity RETURN communityCount, modularity
        """,
        gn=graph_name,
    ).single()
    rec = sess.run(
        """
        CALL gds.graph.nodeProperty.stream($gn, 'ring_id')
        YIELD nodeId, propertyValue
        WITH nodeId, propertyValue AS ring_id
        MATCH (c) WHERE id(c) = nodeId
        RETURN c.character_id AS cid, ring_id
        """,
        gn=graph_name,
    )
    ring_of: dict[int, int] = {}
    for r in rec:
        ring_of[int(r["cid"])] = int(r["ring_id"])
    # Ring sizes.
    size_of: dict[int, int] = {}
    for rid in ring_of.values():
        size_of[rid] = size_of.get(rid, 0) + 1
    return {cid: (rid, size_of[rid]) for cid, rid in ring_of.items()}


def _run_internal_betweenness(sess, graph_name: str) -> dict[int, float]:
    """gds.betweenness over the internal subgraph (sampled)."""
    sess.run(
        """
        CALL gds.betweenness.mutate($gn, {
          mutateProperty: 'bridge_internal',
          samplingSize: 500
        }) YIELD nodePropertiesWritten RETURN nodePropertiesWritten
        """,
        gn=graph_name,
    ).single()
    rec = sess.run(
        """
        CALL gds.graph.nodeProperty.stream($gn, 'bridge_internal')
        YIELD nodeId, propertyValue
        WITH nodeId, propertyValue AS bw
        MATCH (c) WHERE id(c) = nodeId
        RETURN c.character_id AS cid, bw
        """,
        gn=graph_name,
    )
    return {int(r["cid"]): float(r["bw"] or 0.0) for r in rec}


def _similarity_to_flagged(sess, internal_cids: set[int], seed_cids: set[int]) -> dict[int, tuple[int, float]]:
    """For every internal character, count CI_SIMILAR_TO peers in the seed
    set and the max similarity score across them. Returns
    cid → (count, max_score)."""
    if not seed_cids:
        return {}
    seed_list = sorted(seed_cids)
    out: dict[int, tuple[int, float]] = {}
    rec = sess.run(
        """
        MATCH (c:CICharacter)-[s:CI_SIMILAR_TO]-(peer:CICharacter)
        WHERE c.character_id IN $internals
          AND peer.character_id IN $seeds
        RETURN c.character_id AS cid,
               count(DISTINCT peer) AS n_flagged,
               max(s.score) AS max_score
        """,
        internals=sorted(internal_cids),
        seeds=seed_list,
    )
    for r in rec:
        out[int(r["cid"])] = (int(r["n_flagged"] or 0), float(r["max_score"] or 0.0))
    return out


# ----- row assembly + persist ----------------------------------------


def _build_rows(*, internal_cids: set[int], seed_cids: set[int],
                rings: dict[int, tuple[int, int]], bridges: dict[int, float],
                flagged: dict[int, tuple[int, float]],
                viewer_bloc_id: int, window_end: date, window_days: int) -> list[dict]:
    # Internal-bridge percentile.
    bw_sorted = sorted(bridges.values())
    def pct(v: float) -> float | None:
        if not bw_sorted:
            return None
        below = 0
        for x in bw_sorted:
            if x < v:
                below += 1
            else:
                break
        return round(below / len(bw_sorted), 4)

    rows: list[dict] = []
    for cid in internal_cids:
        ring_id, ring_size = rings.get(cid, (None, 0))
        bw = bridges.get(cid)
        bw_pct = pct(bw) if bw is not None else None
        count, max_score = flagged.get(cid, (0, 0.0))
        rows.append({
            "character_id": cid,
            "viewer_bloc_id": viewer_bloc_id,
            "window_end_date": window_end,
            "window_days": window_days,
            "ring_id": ring_id,
            "ring_size": ring_size,
            "bridge_internal_score": round(bw, 4) if bw is not None else None,
            "bridge_internal_pct": bw_pct,
            "similarity_to_flagged_max": round(max_score, 4) if max_score else None,
            "similarity_to_flagged_count": count,
            "is_seed": 1 if cid in seed_cids else 0,
        })
    return rows


def _purge_stale(conn, viewer_bloc_id: int, internal_cids: set[int],
                 window_end: date, window_days: int) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT character_id
              FROM ci_character_graph_features_rolling
             WHERE viewer_bloc_id=%s AND window_end_date=%s AND window_days=%s
            """,
            (viewer_bloc_id, window_end, window_days),
        )
        existing = {int(r["character_id"]) for r in cur.fetchall()}
    stale = [c for c in existing if c not in internal_cids]
    if not stale:
        return
    batch = 5000
    for i in range(0, len(stale), batch):
        chunk = stale[i:i + batch]
        ph = ",".join(["%s"] * len(chunk))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                DELETE FROM ci_character_graph_features_rolling
                 WHERE viewer_bloc_id=%s AND window_end_date=%s AND window_days=%s
                   AND character_id IN ({ph})
                """,
                [viewer_bloc_id, window_end, window_days] + chunk,
            )
    conn.commit()
    log.info("purged stale graph feature rows", {"n": len(stale)})


def _persist(conn, rows: list[dict]) -> None:
    if not rows:
        return
    with conn.cursor() as cur:
        cur.executemany(
            """
            INSERT INTO ci_character_graph_features_rolling
              (character_id, viewer_bloc_id, window_end_date, window_days,
               ring_id, ring_size,
               bridge_internal_score, bridge_internal_pct,
               similarity_to_flagged_max, similarity_to_flagged_count,
               is_seed, computed_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW())
            ON DUPLICATE KEY UPDATE
              ring_id=VALUES(ring_id),
              ring_size=VALUES(ring_size),
              bridge_internal_score=VALUES(bridge_internal_score),
              bridge_internal_pct=VALUES(bridge_internal_pct),
              similarity_to_flagged_max=VALUES(similarity_to_flagged_max),
              similarity_to_flagged_count=VALUES(similarity_to_flagged_count),
              is_seed=VALUES(is_seed),
              computed_at=NOW()
            """,
            [
                (
                    r["character_id"], r["viewer_bloc_id"], r["window_end_date"], r["window_days"],
                    r["ring_id"], r["ring_size"],
                    r["bridge_internal_score"], r["bridge_internal_pct"],
                    r["similarity_to_flagged_max"], r["similarity_to_flagged_count"],
                    r["is_seed"],
                )
                for r in rows
            ],
        )
    conn.commit()
