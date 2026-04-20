"""Project alliance_pair_behavior_rolling → Neo4j.

Creates :Alliance nodes (id + name) and ALLIANCE_RELATES_TO edges
between them carrying the pair metrics. Edge direction uses the
canonical (a < b) ordering from MariaDB; Cypher queries should
match on the relationship without direction:

    MATCH (a:Alliance)-[r:ALLIANCE_RELATES_TO]-(b:Alliance)

Every pass wipes prior ALLIANCE_RELATES_TO edges for the same
(window_end, window_days) to keep the graph aligned with the latest
extractor output. Alliance nodes persist across runs.

Entry point: `python -m bloc_intel project-neo4j`.
"""

from __future__ import annotations

from contextlib import contextmanager
from datetime import date, datetime, timezone
from typing import Iterator

import pymysql
import pymysql.cursors
from neo4j import Driver, GraphDatabase

from bloc_intel.config import Config
from bloc_intel.log import get

log = get("bloc_intel.projection")


@contextmanager
def neo_driver(cfg: Config) -> Iterator[Driver]:
    driver = GraphDatabase.driver(cfg.neo4j_uri, auth=(cfg.neo4j_user, cfg.neo4j_password))
    try:
        yield driver
    finally:
        driver.close()


def project(conn: pymysql.connections.Connection, cfg: Config,
            window_end: date | None = None) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()

    rows, names = _load(conn, cfg, window_end)
    log.info("loaded pair rows + alliance names", {
        "pair_rows": len(rows),
        "alliances": len(names),
        "window_end": window_end.isoformat(),
        "window_days": cfg.window_days,
    })
    if not rows:
        return {"pairs_written": 0, "alliances_written": 0}

    with neo_driver(cfg) as driver:
        with driver.session(database=cfg.neo4j_database) as sess:
            _ensure_constraint(sess)
            merged_alliances = _merge_alliances(sess, names)
            written = _write_edges(sess, rows, window_end, cfg.window_days)

    log.info("projection complete", {
        "alliances_written": merged_alliances,
        "pairs_written": written,
    })
    return {"pairs_written": written, "alliances_written": merged_alliances}


def _load(conn, cfg: Config, window_end: date) -> tuple[list[dict], dict[int, str]]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT alliance_a_id, alliance_b_id,
                   n_obs, n_same_side, n_opposed,
                   weighted_n_obs, weighted_same_side, weighted_opposed,
                   affinity_score, hostility_score,
                   avoidance_windows, avoidance_ratio,
                   parallel_ops_events, parallel_ops_strength,
                   confidence, first_seen_at, last_seen_at
              FROM alliance_pair_behavior_rolling
             WHERE window_end_date = %s AND window_days = %s
            """,
            (window_end, cfg.window_days),
        )
        rows = [dict(r) for r in cur.fetchall()]

    alliance_ids: set[int] = set()
    for r in rows:
        alliance_ids.add(int(r["alliance_a_id"]))
        alliance_ids.add(int(r["alliance_b_id"]))
    names: dict[int, str] = {}
    if alliance_ids:
        ph = ",".join(["%s"] * len(alliance_ids))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT entity_id, name
                  FROM esi_entity_names
                 WHERE category = 'alliance'
                   AND entity_id IN ({ph})
                """,
                list(alliance_ids),
            )
            for r in cur.fetchall():
                names[int(r["entity_id"])] = str(r["name"])
    return rows, names


def _ensure_constraint(sess) -> None:
    sess.run(
        "CREATE CONSTRAINT alliance_id_uniq IF NOT EXISTS "
        "FOR (a:Alliance) REQUIRE a.id IS UNIQUE"
    )


def _merge_alliances(sess, names: dict[int, str]) -> int:
    # Chunked MERGE: one UNWIND per 500 alliances keeps the Bolt
    # frame under the default 32 MB cap by a wide margin.
    payload = [{"id": aid, "name": name} for aid, name in names.items()]
    written = 0
    for i in range(0, len(payload), 500):
        chunk = payload[i:i + 500]
        sess.run(
            """
            UNWIND $rows AS r
            MERGE (a:Alliance {id: r.id})
              ON CREATE SET a.name = r.name
              ON MATCH  SET a.name = r.name
            """,
            rows=chunk,
        )
        written += len(chunk)
    return written


def _write_edges(sess, rows: list[dict], window_end: date, window_days: int) -> int:
    # Wipe the window's edges first — extractor pass already
    # regenerated the MariaDB rows, so stale Neo4j edges for the
    # same window would show obsolete metrics.
    sess.run(
        """
        MATCH ()-[r:ALLIANCE_RELATES_TO]->()
         WHERE r.window_end = $we AND r.window_days = $wd
        DELETE r
        """,
        we=window_end.isoformat(),
        wd=window_days,
    )
    payload = []
    for r in rows:
        payload.append({
            "a": int(r["alliance_a_id"]),
            "b": int(r["alliance_b_id"]),
            "n_obs": float(r["n_obs"] or 0),
            "weighted_n_obs": float(r["weighted_n_obs"] or 0),
            "weighted_same_side": float(r["weighted_same_side"] or 0),
            "weighted_opposed": float(r["weighted_opposed"] or 0),
            "affinity": float(r["affinity_score"]) if r["affinity_score"] is not None else None,
            "hostility": float(r["hostility_score"]) if r["hostility_score"] is not None else None,
            "avoidance_ratio": float(r["avoidance_ratio"]) if r["avoidance_ratio"] is not None else None,
            "avoidance_windows": int(r["avoidance_windows"] or 0),
            "parallel_ops_strength": float(r["parallel_ops_strength"]) if r["parallel_ops_strength"] is not None else None,
            "parallel_ops_events": int(r["parallel_ops_events"] or 0),
            "confidence": float(r["confidence"]) if r["confidence"] is not None else None,
            "first_seen_at": r["first_seen_at"].isoformat() if r["first_seen_at"] else None,
            "last_seen_at": r["last_seen_at"].isoformat() if r["last_seen_at"] else None,
        })
    written = 0
    batch = 1000
    for i in range(0, len(payload), batch):
        chunk = payload[i:i + batch]
        sess.run(
            """
            UNWIND $rows AS r
            MATCH (a:Alliance {id: r.a})
            MATCH (b:Alliance {id: r.b})
            MERGE (a)-[e:ALLIANCE_RELATES_TO {window_end: $we, window_days: $wd}]->(b)
              SET e.n_obs = r.n_obs,
                  e.weighted_n_obs = r.weighted_n_obs,
                  e.weighted_same_side = r.weighted_same_side,
                  e.weighted_opposed = r.weighted_opposed,
                  e.affinity = r.affinity,
                  e.hostility = r.hostility,
                  e.avoidance_ratio = r.avoidance_ratio,
                  e.avoidance_windows = r.avoidance_windows,
                  e.parallel_ops_strength = r.parallel_ops_strength,
                  e.parallel_ops_events = r.parallel_ops_events,
                  e.confidence = r.confidence,
                  e.first_seen_at = r.first_seen_at,
                  e.last_seen_at = r.last_seen_at,
                  e.updated_at = datetime()
            """,
            rows=chunk,
            we=window_end.isoformat(),
            wd=window_days,
        )
        written += len(chunk)
    return written
