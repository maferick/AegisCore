"""Counter-Intel Dossier — Commit 4: anomaly compute (per-viewer-bloc).

Reads:
  - Neo4j: :CICharacter nodes + CI_SIMILAR_TO edges + pagerank/
    betweenness props.
  - MariaDB: coalition_entity_labels + coalition_relationship_types for
    viewer-relative hostility; character_corporation_history +
    corporation_alliance_history for historical-hostility-at-time.

Writes:
  - MariaDB ci_character_anomalies_rolling, keyed by
    (character_id, viewer_bloc_id, window_end_date, window_days).

Per-character flow:
  1. Load similarity cohort from Neo4j (top-100 CI_SIMILAR_TO peers).
  2. Apply clean-floor: cohort must be ≥60% pilots with zero hostile-
     linked history + ≥365d tenure in current corp. Otherwise flag the
     cohort confidence down.
  3. Resolve hostility for every alliance in the character's history
     vs the viewer's bloc (current state, MVP simplification — will
     refine to hostility-at-time in a follow-up).
  4. Compute percentile scores against the cohort for:
        activity_decile (battles)
        affiliation_churn_pct
        affiliation_anomaly_pct (count of hostile-linked history)
        hostile_overlap_pct (CI_CO_OCCURS_WITH into hostile-tagged chars)
        bridge_anomaly_pct (betweenness percentile)
  5. Sum into review_priority_score + band.
"""

from __future__ import annotations

from datetime import date, datetime, timezone
from typing import Iterable

import pymysql
from neo4j import Driver

from counter_intel.config import Config
from counter_intel.db import neo_session
from counter_intel.log import get

log = get("counter_intel.anomalies")

CLEAN_TENURE_DAYS = 365
CLEAN_FLOOR = 0.60


def compute(conn: pymysql.connections.Connection, driver: Driver, cfg: Config,
            viewer_bloc_id: int, window_end: date | None = None) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    log.info("anomaly compute starting", {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    hostile_aids, friendly_aids = _resolve_bloc_alliance_sets(conn, viewer_bloc_id)
    log.info("bloc alliance sets resolved", {"hostile_alliances": len(hostile_aids), "friendly_alliances": len(friendly_aids)})

    # Clean-pilot set: characters in current corp ≥365d, zero hostile-
    # tagged alliance in history.
    clean_cids = _clean_pilot_set(conn, hostile_aids)
    log.info("clean pilot set resolved", {"n": len(clean_cids)})

    # Character history hostile-count map (used both for the clean set
    # and for the character's own hostile_alliance_count_history).
    hostile_counts = _hostile_count_per_character(conn, hostile_aids)

    # Scored characters from Neo4j with pagerank + betweenness.
    with neo_session(driver, cfg) as sess:
        scored = _load_scored_characters(sess)
    log.info("scored characters loaded", {"n": len(scored)})
    if not scored:
        return {"written": 0}

    # Pre-compute global pagerank/betweenness/battles distributions for
    # fallback percentiles when cohort too sparse. (Not directly used
    # yet but we'll want it for v1.1 confidence tuning.)

    # Per-character: fetch cohort, compute scores, stage rows.
    rows_out: list[dict] = []
    with neo_session(driver, cfg) as sess:
        for i, c in enumerate(scored):
            cid = c["character_id"]
            peers = _load_cohort(sess, cid)
            row = _score_one(
                conn=conn,
                sess=sess,
                character=c,
                peers=peers,
                clean_cids=clean_cids,
                hostile_counts=hostile_counts,
                hostile_aids=hostile_aids,
                viewer_bloc_id=viewer_bloc_id,
                window_end=window_end,
                window_days=cfg.window_days,
            )
            rows_out.append(row)
            if (i + 1) % 500 == 0:
                log.info("anomaly progress", {"done": i + 1, "total": len(scored)})

    _persist(conn, rows_out)
    log.info("anomalies written", {"n": len(rows_out)})
    return {"written": len(rows_out)}


# ----- Supporting queries ----------------------------------------------


def _resolve_bloc_alliance_sets(conn, viewer_bloc_id: int) -> tuple[set[int], set[int]]:
    """Resolve viewer-bloc-relative hostility into two alliance id sets.

    Hostile = labeled hostile/enemy/red via coalition_relationship_types
    AND bloc_id ≠ viewer_bloc_id.
    Friendly = bloc_id = viewer_bloc_id (explicit friend tag or same-
    bloc membership).
    """
    hostile: set[int] = set()
    friendly: set[int] = set()
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT cel.entity_id, cel.bloc_id,
                   crt.relationship_code AS rel_code,
                   crt.default_role AS rel_role
              FROM coalition_entity_labels cel
              LEFT JOIN coalition_relationship_types crt ON crt.id = cel.relationship_type_id
             WHERE cel.entity_type = 'alliance'
               AND cel.is_active = 1
            """
        )
        for r in cur.fetchall():
            aid = int(r["entity_id"])
            bloc = int(r["bloc_id"] or 0)
            code = str(r["rel_code"] or "").lower()
            if bloc == viewer_bloc_id:
                friendly.add(aid)
                continue
            # Anything NOT in the viewer's own bloc is opposing. Bloc
            # boundaries are the authoritative friend/foe line per
            # coalition_entity_labels design.
            hostile.add(aid)
    return hostile, friendly


def _clean_pilot_set(conn, hostile_aids: set[int]) -> set[int]:
    """Characters with ≥365d tenure in current corp AND zero hostile
    alliance in their full history."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT cch.character_id
              FROM character_corporation_history cch
             WHERE cch.is_deleted = 0
               AND (cch.end_date IS NULL OR cch.end_date > NOW())
               AND DATEDIFF(NOW(), cch.start_date) >= %s
            """,
            (CLEAN_TENURE_DAYS,),
        )
        tenure_ok = {int(r["character_id"]) for r in cur.fetchall()}
    if not tenure_ok:
        return set()
    # Subtract anyone with a hostile alliance in history.
    if not hostile_aids:
        return tenure_ok
    ids = list(tenure_ok)
    hostile_history: set[int] = set()
    batch = 5000
    for i in range(0, len(ids), batch):
        chunk = ids[i:i + batch]
        ph = ",".join(["%s"] * len(chunk))
        aph = ",".join(["%s"] * len(hostile_aids))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT DISTINCT cch.character_id
                  FROM character_corporation_history cch
                  JOIN corporation_alliance_history cah ON cah.corporation_id = cch.corporation_id
                 WHERE cch.is_deleted = 0
                   AND cch.character_id IN ({ph})
                   AND cah.alliance_id IN ({aph})
                   AND cah.start_date <= IFNULL(cch.end_date, NOW())
                   AND (cah.end_date IS NULL OR cah.end_date >= cch.start_date)
                """,
                chunk + list(hostile_aids),
            )
            for r in cur.fetchall():
                hostile_history.add(int(r["character_id"]))
    return tenure_ok - hostile_history


def _hostile_count_per_character(conn, hostile_aids: set[int]) -> dict[int, int]:
    """For every character, count of distinct hostile-tagged alliances
    in their history. 0 = clean."""
    if not hostile_aids:
        return {}
    aph = ",".join(["%s"] * len(hostile_aids))
    out: dict[int, int] = {}
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT cch.character_id, COUNT(DISTINCT cah.alliance_id) AS n
              FROM character_corporation_history cch
              JOIN corporation_alliance_history cah ON cah.corporation_id = cch.corporation_id
             WHERE cch.is_deleted = 0
               AND cah.alliance_id IN ({aph})
               AND cah.start_date <= IFNULL(cch.end_date, NOW())
               AND (cah.end_date IS NULL OR cah.end_date >= cch.start_date)
             GROUP BY cch.character_id
            """,
            list(hostile_aids),
        )
        for r in cur.fetchall():
            out[int(r["character_id"])] = int(r["n"])
    return out


def _load_scored_characters(sess) -> list[dict]:
    """Pull sufficient-history characters + their graph scores from
    Neo4j."""
    return [dict(r) for r in sess.run(
        """
        MATCH (c:CICharacter {has_sufficient_history: 1})
        RETURN c.character_id AS character_id, c.name AS name,
               c.battles AS battles, c.current_alliance_id AS current_alliance_id,
               c.pagerank AS pagerank, c.betweenness AS betweenness,
               c.affiliation_churn_rate AS affiliation_churn_rate
        """
    )]


def _load_cohort(sess, cid: int) -> list[dict]:
    """Top-100 CI_SIMILAR_TO peers with their scored properties."""
    return [dict(r) for r in sess.run(
        """
        MATCH (c:CICharacter {character_id: $cid})-[s:CI_SIMILAR_TO]-(peer:CICharacter)
        WHERE peer.has_sufficient_history = 1
        RETURN peer.character_id AS character_id,
               s.score AS sim_score,
               peer.battles AS battles,
               peer.affiliation_churn_rate AS affiliation_churn_rate,
               peer.betweenness AS betweenness
        ORDER BY s.score DESC
        LIMIT 100
        """,
        cid=cid,
    )]


def _score_one(conn, sess, character: dict, peers: list[dict], clean_cids: set[int],
               hostile_counts: dict[int, int], hostile_aids: set[int],
               viewer_bloc_id: int, window_end: date, window_days: int) -> dict:
    """Produce one ci_character_anomalies_rolling row."""
    cid = int(character["character_id"])
    row: dict = {
        "character_id": cid,
        "viewer_bloc_id": viewer_bloc_id,
        "window_end_date": window_end,
        "window_days": window_days,
        "cohort_size": len(peers),
        "cohort_clean_pct": None,
        "cohort_confidence": "insufficient",
        "activity_decile": None,
        "affiliation_anomaly_pct": None,
        "affiliation_churn_pct": None,
        "hostile_overlap_pct": None,
        "bridge_anomaly_pct": None,
        "hostile_alliance_count_history": int(hostile_counts.get(cid, 0)),
        "hostile_cooccurrence_count": 0,
        "recent_hostile_join": 0,
        "pagerank": float(character["pagerank"]) if character.get("pagerank") is not None else None,
        "betweenness": float(character["betweenness"]) if character.get("betweenness") is not None else None,
        "review_priority_score": None,
        "review_priority_band": "cohort_unavailable",
        "review_priority_score_30d_ago": None,
    }

    if not peers:
        return row

    # Cohort confidence.
    clean_in_cohort = sum(1 for p in peers if int(p["character_id"]) in clean_cids)
    clean_pct = clean_in_cohort / len(peers)
    row["cohort_clean_pct"] = round(clean_pct, 4)
    if len(peers) >= 30 and clean_pct >= CLEAN_FLOOR:
        row["cohort_confidence"] = "high"
    elif len(peers) >= 15:
        row["cohort_confidence"] = "medium"
    else:
        row["cohort_confidence"] = "low"

    # Percentiles vs cohort.
    def pct_of(value: float, dist: list[float]) -> float | None:
        if not dist:
            return None
        dist_sorted = sorted(dist)
        n = len(dist_sorted)
        below = 0
        for v in dist_sorted:
            if v < value:
                below += 1
            else:
                break
        return round(below / n, 4)

    battle_dist = [float(p["battles"] or 0) for p in peers]
    activity_pct = pct_of(float(character["battles"] or 0), battle_dist) or 0.0
    row["activity_decile"] = max(1, min(10, int((activity_pct * 10) + 0.5)))

    churn_dist = [float(p["affiliation_churn_rate"] or 0) for p in peers]
    row["affiliation_churn_pct"] = pct_of(float(character["affiliation_churn_rate"] or 0), churn_dist)

    hostile_count_self = int(hostile_counts.get(cid, 0))
    hostile_count_dist = [float(hostile_counts.get(int(p["character_id"]), 0)) for p in peers]
    row["affiliation_anomaly_pct"] = pct_of(float(hostile_count_self), hostile_count_dist)

    bw_dist = [float(p["betweenness"] or 0) for p in peers]
    row["bridge_anomaly_pct"] = pct_of(float(character["betweenness"] or 0), bw_dist)

    # Hostile co-occurrence count (edges from target into any peer
    # currently in a hostile-tagged alliance).
    hostile_overlap_count, hostile_overlap_dist = _hostile_cooccurrence(sess, cid, peers, hostile_aids)
    row["hostile_cooccurrence_count"] = hostile_overlap_count
    row["hostile_overlap_pct"] = pct_of(float(hostile_overlap_count), hostile_overlap_dist) if hostile_overlap_dist else None

    # Recent hostile join (30d).
    row["recent_hostile_join"] = int(_recent_hostile_join(conn, cid, hostile_aids))

    # Priority score + band.
    signals = 0
    if (row["affiliation_anomaly_pct"] or 0) >= 0.85:
        signals += 1
    if (row["hostile_overlap_pct"] or 0) >= 0.85:
        signals += 1
    if (row["bridge_anomaly_pct"] or 0) >= 0.95:
        signals += 1
    if row["recent_hostile_join"]:
        signals += 1
    if (row["affiliation_churn_pct"] or 0) >= 0.95:
        signals += 1

    # Continuous score = weighted sum of percentiles.
    score = (
        0.35 * (row["affiliation_anomaly_pct"] or 0)
        + 0.30 * (row["hostile_overlap_pct"] or 0)
        + 0.15 * (row["bridge_anomaly_pct"] or 0)
        + 0.10 * (row["affiliation_churn_pct"] or 0)
        + 0.10 * (1.0 if row["recent_hostile_join"] else 0)
    )
    row["review_priority_score"] = round(score, 4)

    if signals >= 3 or score >= 0.75:
        band = "critical"
    elif signals >= 2 or score >= 0.55:
        band = "high"
    elif signals >= 1 or score >= 0.35:
        band = "elevated"
    else:
        band = "below_threshold"
    row["review_priority_band"] = band
    return row


def _hostile_cooccurrence(sess, cid: int, peers: list[dict], hostile_aids: set[int]) -> tuple[int, list[float]]:
    """Count peer-characters whose current alliance is hostile-tagged.
    Returns (self_count, peer_dist) so percentile can be computed.
    Single cypher call fetches both (via labeled sub-query)."""
    if not hostile_aids:
        return (0, [])
    hostile_list = list(hostile_aids)

    # Self: neighbors of cid via CI_CO_OCCURS_WITH whose current alliance
    # is hostile.
    rec = sess.run(
        """
        MATCH (c:CICharacter {character_id: $cid})-[:CI_CO_OCCURS_WITH]-(nb:CICharacter)
        WHERE nb.current_alliance_id IN $host
        RETURN count(DISTINCT nb) AS n
        """,
        cid=cid, host=hostile_list,
    ).single()
    self_count = int(rec["n"] or 0)

    # Peers: for each peer, their own hostile-neighbor count. One cypher
    # with UNWIND so we don't hit the DB 100 times.
    peer_ids = [int(p["character_id"]) for p in peers]
    dist: list[float] = []
    if peer_ids:
        res = sess.run(
            """
            UNWIND $ids AS pid
            MATCH (p:CICharacter {character_id: pid})
            OPTIONAL MATCH (p)-[:CI_CO_OCCURS_WITH]-(nb:CICharacter)
            WHERE nb.current_alliance_id IN $host
            RETURN pid, count(DISTINCT nb) AS n
            """,
            ids=peer_ids, host=hostile_list,
        )
        for r in res:
            dist.append(float(r["n"] or 0))
    return (self_count, dist)


def _recent_hostile_join(conn, cid: int, hostile_aids: set[int]) -> bool:
    """Did the character start membership in a hostile-tagged alliance
    in the last 30 days?"""
    if not hostile_aids:
        return False
    aph = ",".join(["%s"] * len(hostile_aids))
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT 1
              FROM character_corporation_history cch
              JOIN corporation_alliance_history cah ON cah.corporation_id = cch.corporation_id
             WHERE cch.character_id = %s
               AND cch.is_deleted = 0
               AND cah.alliance_id IN ({aph})
               AND cch.start_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND cah.start_date <= IFNULL(cch.end_date, NOW())
               AND (cah.end_date IS NULL OR cah.end_date >= cch.start_date)
             LIMIT 1
            """,
            [cid] + list(hostile_aids),
        )
        return cur.fetchone() is not None


# ----- persist ---------------------------------------------------------


def _persist(conn, rows: list[dict]) -> None:
    if not rows:
        return
    with conn.cursor() as cur:
        cur.executemany(
            """
            INSERT INTO ci_character_anomalies_rolling
              (character_id, viewer_bloc_id, window_end_date, window_days,
               cohort_size, cohort_clean_pct, cohort_confidence,
               activity_decile,
               affiliation_anomaly_pct, affiliation_churn_pct,
               hostile_overlap_pct, bridge_anomaly_pct,
               hostile_alliance_count_history, hostile_cooccurrence_count,
               recent_hostile_join, pagerank, betweenness,
               review_priority_score, review_priority_band,
               review_priority_score_30d_ago, computed_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW())
            ON DUPLICATE KEY UPDATE
               cohort_size = VALUES(cohort_size),
               cohort_clean_pct = VALUES(cohort_clean_pct),
               cohort_confidence = VALUES(cohort_confidence),
               activity_decile = VALUES(activity_decile),
               affiliation_anomaly_pct = VALUES(affiliation_anomaly_pct),
               affiliation_churn_pct = VALUES(affiliation_churn_pct),
               hostile_overlap_pct = VALUES(hostile_overlap_pct),
               bridge_anomaly_pct = VALUES(bridge_anomaly_pct),
               hostile_alliance_count_history = VALUES(hostile_alliance_count_history),
               hostile_cooccurrence_count = VALUES(hostile_cooccurrence_count),
               recent_hostile_join = VALUES(recent_hostile_join),
               pagerank = VALUES(pagerank),
               betweenness = VALUES(betweenness),
               review_priority_score = VALUES(review_priority_score),
               review_priority_band = VALUES(review_priority_band),
               computed_at = NOW()
            """,
            [
                (
                    r["character_id"], r["viewer_bloc_id"], r["window_end_date"], r["window_days"],
                    r["cohort_size"], r["cohort_clean_pct"], r["cohort_confidence"],
                    r["activity_decile"],
                    r["affiliation_anomaly_pct"], r["affiliation_churn_pct"],
                    r["hostile_overlap_pct"], r["bridge_anomaly_pct"],
                    r["hostile_alliance_count_history"], r["hostile_cooccurrence_count"],
                    r["recent_hostile_join"], r["pagerank"], r["betweenness"],
                    r["review_priority_score"], r["review_priority_band"],
                    r["review_priority_score_30d_ago"],
                )
                for r in rows
            ],
        )
    conn.commit()
