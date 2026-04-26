"""Phase 2 — hostile micro-network triangulation.

For each character in a viewer bloc's anomaly window, identify the
strongest recurring hostile triangle: a set of 3+ hostile pilots whose
pairwise co-occurrence opposite the target across distinct battle days
passes a threshold.

Algorithm (deterministic, explainable, no Neo4j required):

  1. Pull target's top-K opposing-side hostile pilots, ranked by
     distinct shared days.
  2. For every pair (B, C) inside that top-K, count distinct days the
     pair both appeared opposite the target.
  3. Greedy triangle build: start with the highest-weight pair, then
     extend to a 3rd member that shares >= MIN_PAIR_DAYS with both.
     Repeat extending greedily up to MAX_TRIANGLE_SIZE.
  4. Persist the strongest triangle per character.

Conservative thresholds. The point is to surface obvious recurring
clusters, not to fish for weak coincidence.

Run: counter_intel phase2-triangulation --viewer-bloc-id N [--window-end YYYY-MM-DD]
"""

from __future__ import annotations

import json
from datetime import date, datetime, timedelta, timezone
from itertools import combinations

import pymysql

from counter_intel.config import Config
from counter_intel.log import get
from counter_intel.phase1 import _hostile_alliance_set

log = get("counter_intel.phase2_triangulation")

# Tuning. Conservative defaults — calibration spec will revisit.
TOP_K_OPPONENTS = 10
MIN_PAIR_DAYS = 2
MIN_PAIR_FOR_TRIANGLE = 2
MAX_TRIANGLE_SIZE = 5


def run(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date | None = None,
) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    window_start = window_end - timedelta(days=cfg.window_days - 1)
    window_start_dt = datetime.combine(window_start, datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)

    cids = _candidates(conn, viewer_bloc_id, window_end, cfg.window_days)
    log.info(
        "phase2 triangulation starting",
        {"candidates": len(cids), "viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()},
    )
    if not cids:
        return {"candidates": 0, "computed": 0, "triangles_found": 0}

    hostile_alliances = _hostile_alliance_set(conn, viewer_bloc_id)
    if not hostile_alliances:
        log.warning("no hostile alliance set — skipping triangulation")
        return {"candidates": len(cids), "computed": 0, "triangles_found": 0}

    found = 0
    computed = 0
    for cid in cids:
        triangle = _compute_triangle(
            conn, cid, window_start_dt, window_end_dt, hostile_alliances,
        )
        computed += 1
        if triangle is None:
            _delete_existing(conn, cid, viewer_bloc_id, window_end)
            _update_anomaly_count(conn, cid, viewer_bloc_id, window_end, cfg.window_days, 0, None)
        else:
            _persist(conn, cid, viewer_bloc_id, window_end, cfg.window_days, triangle)
            _update_anomaly_count(
                conn, cid, viewer_bloc_id, window_end, cfg.window_days,
                triangle["size"], triangle["size"],
            )
            found += 1
        if computed % 200 == 0:
            conn.commit()
            log.info("triangulation progress", {"computed": computed, "found": found, "total": len(cids)})
    conn.commit()
    log.info("triangulation done", {"computed": computed, "found": found})
    return {"candidates": len(cids), "computed": computed, "triangles_found": found}


def _candidates(
    conn: pymysql.connections.Connection,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int,
) -> list[int]:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT character_id FROM ci_character_anomalies_rolling "
            "WHERE viewer_bloc_id = %s AND window_end_date = %s AND window_days = %s",
            (viewer_bloc_id, window_end, window_days),
        )
        return [int(r["character_id"]) for r in cur.fetchall()]


def _compute_triangle(
    conn: pymysql.connections.Connection,
    target_id: int,
    window_start_dt: datetime,
    window_end_dt: datetime,
    hostile_alliances: set[int],
) -> dict | None:
    # 1. Top-K opposing hostile pilots with their (cid → set of distinct days).
    days_per_opp = _opponent_days(conn, target_id, window_start_dt, window_end_dt, hostile_alliances)
    if len(days_per_opp) < 3:
        return None

    # Take top-K by distinct day count.
    top = sorted(days_per_opp.items(), key=lambda kv: -len(kv[1]))[:TOP_K_OPPONENTS]
    if len(top) < 3:
        return None
    cid_to_days = {cid: days for cid, days in top}

    # 2. Pairwise shared-day counts among the top-K.
    pair_days: dict[tuple[int, int], int] = {}
    for a, b in combinations(cid_to_days.keys(), 2):
        shared = len(cid_to_days[a] & cid_to_days[b])
        if shared >= MIN_PAIR_DAYS:
            pair_days[tuple(sorted([a, b]))] = shared

    if not pair_days:
        return None

    # 3. Greedy triangle build. Start with the strongest pair.
    seed_pair = max(pair_days.items(), key=lambda kv: kv[1])[0]
    members = list(seed_pair)
    weight = pair_days[seed_pair]

    while len(members) < MAX_TRIANGLE_SIZE:
        # Find any candidate cid with shared days against EVERY current
        # member >= MIN_PAIR_FOR_TRIANGLE.
        candidates = []
        for cid in cid_to_days.keys():
            if cid in members:
                continue
            ok = True
            extra_weight = 0
            for m in members:
                key = tuple(sorted([cid, m]))
                if pair_days.get(key, 0) < MIN_PAIR_FOR_TRIANGLE:
                    ok = False
                    break
                extra_weight += pair_days[key]
            if ok:
                candidates.append((cid, extra_weight))
        if not candidates:
            break
        cid, extra_weight = max(candidates, key=lambda kv: kv[1])
        members.append(cid)
        weight += extra_weight

    if len(members) < 3:
        return None

    # Lower bound of shared days = min pair count among any current pair
    # of members.
    min_shared = min(
        pair_days.get(tuple(sorted([a, b])), 0)
        for a, b in combinations(members, 2)
    )

    return {
        "members": members,
        "size": len(members),
        "shared_battle_days": int(min_shared),
        "weight": float(weight),
    }


def _opponent_days(
    conn: pymysql.connections.Connection,
    target_id: int,
    window_start_dt: datetime,
    window_end_dt: datetime,
    hostile_alliances: set[int],
) -> dict[int, set[str]]:
    """Returns {opponent_cid: set of YYYY-MM-DD strings} for hostile
    pilots that appeared opposite the target. Uses the same UNION
    pattern as phase1 asymmetric."""
    if not hostile_alliances:
        return {}
    if len(hostile_alliances) > 4000:
        opponent_filter = (
            "AND opp_alliance IN ("
            "  SELECT entity_id FROM coalition_entity_labels "
            "  WHERE entity_type='alliance' AND is_active=1 AND bloc_id <> 0 "
            ") "
        )
        params_extra: tuple = ()
    else:
        ph = ",".join(["%s"] * len(hostile_alliances))
        opponent_filter = f"AND opp_alliance IN ({ph}) "
        params_extra = tuple(hostile_alliances)

    sql = (
        "SELECT opp_cid, DATE(killed_at) AS d "
        "FROM ( "
        "  SELECT k.killed_at, k.victim_character_id AS opp_cid, k.victim_alliance_id AS opp_alliance "
        "    FROM killmail_attackers ka JOIN killmails k ON k.killmail_id = ka.killmail_id "
        "   WHERE ka.character_id = %s AND k.killed_at BETWEEN %s AND %s "
        "     AND k.victim_character_id IS NOT NULL "
        "  UNION ALL "
        "  SELECT k.killed_at, ka.character_id AS opp_cid, ka.alliance_id AS opp_alliance "
        "    FROM killmails k JOIN killmail_attackers ka ON ka.killmail_id = k.killmail_id "
        "   WHERE k.victim_character_id = %s AND k.killed_at BETWEEN %s AND %s "
        "     AND ka.character_id IS NOT NULL "
        ") opp "
        f"WHERE opp_cid <> %s {opponent_filter}"
    )
    params = (
        target_id, window_start_dt, window_end_dt,
        target_id, window_start_dt, window_end_dt,
        target_id,
    ) + params_extra
    out: dict[int, set[str]] = {}
    with conn.cursor() as cur:
        cur.execute(sql, params)
        for row in cur.fetchall():
            cid = int(row["opp_cid"])
            if cid not in out:
                out[cid] = set()
            out[cid].add(str(row["d"]))
    return out


def _persist(
    conn: pymysql.connections.Connection,
    target_id: int,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int,
    triangle: dict,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO ci_hostile_triangulation
                (character_id, viewer_bloc_id, window_end_date, window_days,
                 triangle_size, shared_battle_days, weight, member_ids_json, computed_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW())
            ON DUPLICATE KEY UPDATE
                window_days = VALUES(window_days),
                triangle_size = VALUES(triangle_size),
                shared_battle_days = VALUES(shared_battle_days),
                weight = VALUES(weight),
                member_ids_json = VALUES(member_ids_json),
                computed_at = NOW()
            """,
            (
                target_id, viewer_bloc_id, window_end, window_days,
                triangle["size"], triangle["shared_battle_days"], triangle["weight"],
                json.dumps(triangle["members"]),
            ),
        )


def _delete_existing(
    conn: pymysql.connections.Connection,
    target_id: int,
    viewer_bloc_id: int,
    window_end: date,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "DELETE FROM ci_hostile_triangulation "
            "WHERE character_id = %s AND viewer_bloc_id = %s AND window_end_date = %s",
            (target_id, viewer_bloc_id, window_end),
        )


def _update_anomaly_count(
    conn: pymysql.connections.Connection,
    target_id: int,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int,
    count: int,
    top_size: int | None,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE ci_character_anomalies_rolling
               SET hostile_triangle_count = %s,
                   hostile_triangle_top_size = %s
             WHERE character_id = %s AND viewer_bloc_id = %s
               AND window_end_date = %s AND window_days = %s
            """,
            (count, top_size, target_id, viewer_bloc_id, window_end, window_days),
        )
