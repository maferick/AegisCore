"""Bloc Intelligence — alliance-pair behavior extractor (v1).

Walks the last N days of killmails once. Per killmail:
  - Build alliance-pilot-count histogram from killmail_attackers.
  - Identify attacker-side alliances with >= MIN_PILOTS_PER_OBS pilots.
  - Identify victim alliance (single).
  - For every pair of attacker alliances (both >= threshold), emit a
    SAME-side pair event.
  - For every attacker alliance >= threshold vs the victim alliance,
    emit an OPPOSED-side pair event.
  - Apply sqrt(attacker_count)-dampening so 500-pilot fleet blobs
    don't eclipse 10-pilot gang fights.
  - Apply exponential recency decay (half-life configurable).

Conditional-alignment triggers (conditional_delta per (pair, T)) are
out of scope for v1 — the correct formulation needs a second pass over
both-present events split by T's presence, and preserving that stream
without O(alliances^3) blowup requires targeted per-pair requery. That
arrives in a follow-up commit.

Writes to MariaDB: alliance_pair_behavior_rolling.
Viewer-agnostic — coalition_entity_labels feeds UI overlay at render.
"""

from __future__ import annotations

import math
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone

import pymysql
import pymysql.cursors

from bloc_intel.config import Config
from bloc_intel.log import get

log = get("bloc_intel.extractor")


def compute(conn: pymysql.connections.Connection, cfg: Config,
            window_end: date | None = None) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    ws = datetime.combine(window_end - timedelta(days=cfg.window_days - 1),
                          datetime.min.time(), tzinfo=timezone.utc)
    we = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)
    log.info("extractor starting", {
        "window_start": ws.isoformat(),
        "window_end": we.isoformat(),
        "window_days": cfg.window_days,
        "half_life_days": cfg.decay_half_life_days,
        "min_pilots_per_observation": cfg.min_pilots_per_observation,
    })

    pair_stats = _extract_pair_stats(conn, cfg, ws, we)
    log.info("pair aggregation complete", {"pairs_pre_threshold": len(pair_stats)})

    kept = _finalize_pair_rows(pair_stats, cfg, window_end)
    log.info("pair rows ready", {"kept": len(kept)})

    _persist_pair_rows(conn, kept, window_end, cfg.window_days)
    log.info("persisted", {"pairs": len(kept)})
    return {"pairs_written": len(kept)}


# ----- extraction ------------------------------------------------------


def _extract_pair_stats(conn, cfg: Config, ws: datetime, we: datetime) -> dict:
    """Single streamed pass. Returns
    pair_stats[(a,b)] = {counters + first_t/last_t}."""
    half_life_seconds = cfg.decay_half_life_days * 86400.0
    anchor_ts = we.timestamp()

    pair_stats: dict[tuple[int, int], dict] = {}

    cur = conn.cursor(pymysql.cursors.SSCursor)
    try:
        cur.execute(
            """
            SELECT k.killmail_id, k.killed_at, k.attacker_count,
                   k.victim_alliance_id
              FROM killmails k
             WHERE k.killed_at BETWEEN %s AND %s
               AND k.attacker_count > 0
             ORDER BY k.killed_at
            """,
            (ws, we),
        )
        km_rows = [(int(r[0]), r[1], int(r[2] or 1), int(r[3]) if r[3] else None)
                   for r in cur]
    finally:
        cur.close()

    if not km_rows:
        log.warning("no killmails in window")
        return pair_stats
    log.info("killmails in window", {"n": len(km_rows)})

    chunk = 20000
    processed = 0
    for i in range(0, len(km_rows), chunk):
        slice_rows = km_rows[i:i + chunk]
        slice_ids = [r[0] for r in slice_rows]
        km_meta = {r[0]: r for r in slice_rows}
        attackers_by_km = _fetch_attacker_alliances(conn, slice_ids)

        for kid, attacker_allies in attackers_by_km.items():
            _, killed_at, attacker_count, victim_alliance_id = km_meta[kid]
            if not attacker_allies:
                continue
            attendee_w = 1.0 / math.sqrt(max(1, attacker_count))
            age_seconds = anchor_ts - killed_at.replace(tzinfo=timezone.utc).timestamp()
            decay = 0.5 ** (age_seconds / half_life_seconds) if half_life_seconds > 0 else 1.0
            w = attendee_w * decay

            eligible = [aid for aid, n in attacker_allies.items()
                        if n >= cfg.min_pilots_per_observation and aid]
            if len(eligible) > 30:
                eligible.sort(key=lambda aid: -attacker_allies[aid])
                eligible = eligible[:30]

            # SAME-side pairs.
            for idx_a in range(len(eligible)):
                for idx_b in range(idx_a + 1, len(eligible)):
                    a, b = sorted((eligible[idx_a], eligible[idx_b]))
                    _accumulate(pair_stats, (a, b), killed_at, True, w)

            # OPPOSED pairs: each eligible attacker vs victim alliance.
            if victim_alliance_id and victim_alliance_id not in eligible:
                for a_raw in eligible:
                    a, b = sorted((a_raw, victim_alliance_id))
                    _accumulate(pair_stats, (a, b), killed_at, False, w)

        processed += len(slice_ids)
        if processed % 200000 == 0 or processed == len(km_rows):
            log.info("processed killmails", {
                "done": processed, "total": len(km_rows), "pairs": len(pair_stats)
            })

    return pair_stats


def _fetch_attacker_alliances(conn, km_ids: list[int]) -> dict[int, dict[int, int]]:
    if not km_ids:
        return {}
    ph = ",".join(["%s"] * len(km_ids))
    out: dict[int, dict[int, int]] = defaultdict(lambda: defaultdict(int))
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT killmail_id, alliance_id, COUNT(*) AS n
              FROM killmail_attackers
             WHERE killmail_id IN ({ph})
               AND alliance_id IS NOT NULL
             GROUP BY killmail_id, alliance_id
            """,
            km_ids,
        )
        for r in cur.fetchall():
            out[int(r["killmail_id"])][int(r["alliance_id"])] = int(r["n"])
    return out


def _accumulate(pair_stats: dict, key: tuple[int, int], killed_at: datetime,
                same_side: bool, weight: float) -> None:
    st = pair_stats.get(key)
    if st is None:
        st = {
            "n_obs": 0.0,
            "n_same_side": 0.0,
            "n_opposed": 0.0,
            "weighted_n_obs": 0.0,
            "weighted_same_side": 0.0,
            "weighted_opposed": 0.0,
            "first_t": killed_at,
            "last_t": killed_at,
        }
        pair_stats[key] = st
    st["n_obs"] += 1.0
    st["weighted_n_obs"] += weight
    if same_side:
        st["n_same_side"] += 1.0
        st["weighted_same_side"] += weight
    else:
        st["n_opposed"] += 1.0
        st["weighted_opposed"] += weight
    if killed_at < st["first_t"]:
        st["first_t"] = killed_at
    if killed_at > st["last_t"]:
        st["last_t"] = killed_at


# ----- finalize --------------------------------------------------------


def _finalize_pair_rows(pair_stats: dict, cfg: Config, window_end: date) -> list[dict]:
    rows: list[dict] = []
    for (a, b), st in pair_stats.items():
        if st["n_obs"] < cfg.min_pair_n_obs:
            continue
        wn = st["weighted_n_obs"] or 0.0
        ws_ = st["weighted_same_side"] or 0.0
        wo = st["weighted_opposed"] or 0.0
        affinity = round(ws_ / wn, 4) if wn > 0 else None
        hostility = round(wo / wn, 4) if wn > 0 else None
        conf = _confidence(st["n_obs"])
        rows.append({
            "alliance_a_id": a,
            "alliance_b_id": b,
            "window_end_date": window_end,
            "n_obs": round(st["n_obs"], 4),
            "n_same_side": round(st["n_same_side"], 4),
            "n_opposed": round(st["n_opposed"], 4),
            "weighted_n_obs": round(wn, 4),
            "weighted_same_side": round(ws_, 4),
            "weighted_opposed": round(wo, 4),
            "affinity_score": affinity,
            "hostility_score": hostility,
            "confidence": conf,
            "first_seen_at": st["first_t"],
            "last_seen_at": st["last_t"],
        })
    return rows


def _confidence(n_obs: float) -> float:
    if n_obs <= 0:
        return 0.0
    return round(min(1.0, math.log10(n_obs) / 2.0), 4)


# ----- persist ---------------------------------------------------------


def _persist_pair_rows(conn, rows: list[dict], window_end: date, window_days: int) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "DELETE FROM alliance_pair_behavior_rolling WHERE window_end_date=%s AND window_days=%s",
            (window_end, window_days),
        )
    if not rows:
        conn.commit()
        return
    batch = 1000
    with conn.cursor() as cur:
        for i in range(0, len(rows), batch):
            chunk = rows[i:i + batch]
            cur.executemany(
                """
                INSERT INTO alliance_pair_behavior_rolling
                  (alliance_a_id, alliance_b_id, window_end_date, window_days,
                   n_obs, n_same_side, n_opposed,
                   weighted_n_obs, weighted_same_side, weighted_opposed,
                   affinity_score, hostility_score, confidence,
                   first_seen_at, last_seen_at, computed_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW())
                """,
                [
                    (
                        r["alliance_a_id"], r["alliance_b_id"],
                        window_end, window_days,
                        r["n_obs"], r["n_same_side"], r["n_opposed"],
                        r["weighted_n_obs"], r["weighted_same_side"], r["weighted_opposed"],
                        r["affinity_score"], r["hostility_score"], r["confidence"],
                        r["first_seen_at"], r["last_seen_at"],
                    )
                    for r in chunk
                ],
            )
    conn.commit()
