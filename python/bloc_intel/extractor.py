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

    pair_stats, cond_stats = _extract_pair_stats(conn, cfg, ws, we)
    log.info("pair aggregation complete", {
        "pairs_pre_threshold": len(pair_stats),
        "conditional_triples_pre_threshold": len(cond_stats),
    })

    kept = _finalize_pair_rows(pair_stats, cfg, window_end)
    log.info("pair rows ready", {"kept": len(kept)})

    kept_triples = _finalize_conditional_rows(cond_stats, pair_stats, cfg, window_end)
    log.info("conditional triples ready", {"kept": len(kept_triples)})

    _persist_pair_rows(conn, kept, window_end, cfg.window_days)
    _persist_conditional_rows(conn, kept_triples, window_end, cfg.window_days)
    log.info("persisted", {"pairs": len(kept), "triples": len(kept_triples)})
    return {"pairs_written": len(kept), "conditional_triples_written": len(kept_triples)}


# ----- extraction ------------------------------------------------------


def _extract_pair_stats(conn, cfg: Config, ws: datetime, we: datetime) -> tuple[dict, dict]:
    """Single streamed pass. Returns
    (pair_stats[(a,b)], conditional_stats[(a,b,t)]).

    Conditional stats are kept for every (pair, trigger) where all three
    are eligible attackers on the same killmail. Pruned by absolute
    observation volume at finalize time — we can't early-drop because
    a triple with few early observations might still be useful after
    the full window is processed.
    """
    half_life_seconds = cfg.decay_half_life_days * 86400.0
    anchor_ts = we.timestamp()

    pair_stats: dict[tuple[int, int], dict] = {}
    cond_stats: dict[tuple[int, int, int], dict] = {}

    # Factional-Warfare taint = only counts when FW pilots are on BOTH
    # sides of the kill. Attacker-side FW ratio alone mis-fires on
    # "WC fleet with militia alt kills a non-FW ratter". Victim-side
    # signal comes from killmails.victim_faction_id (backfilled from
    # EVE Ref archives via killmail_backfill backfill-victim-faction).
    #
    # Rule:
    #   victim_faction_id IS NULL                 → taint 0 (clean)
    #   victim_faction_id IS NOT NULL ∧ fw_ratio < 0.5 → taint 0.5 (partial)
    #   victim_faction_id IS NOT NULL ∧ fw_ratio ≥ 0.5 → taint 1.0 (pure FW)
    #
    # Event weight multiplied by (1 - taint) with a 0.1 floor so full-
    # FW events still contribute 10%.
    cur = conn.cursor(pymysql.cursors.SSCursor)
    try:
        cur.execute(
            """
            SELECT k.killmail_id, k.killed_at, k.attacker_count,
                   k.victim_alliance_id, k.victim_faction_id,
                   COALESCE(SUM(CASE WHEN ka.faction_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS n_fw_attackers
              FROM killmails k
              LEFT JOIN killmail_attackers ka ON ka.killmail_id = k.killmail_id
             WHERE k.killed_at BETWEEN %s AND %s
               AND k.attacker_count > 0
             GROUP BY k.killmail_id
             ORDER BY k.killed_at
            """,
            (ws, we),
        )
        km_rows = [(int(r[0]), r[1], int(r[2] or 1),
                    int(r[3]) if r[3] else None,
                    int(r[4]) if r[4] else None,
                    int(r[5] or 0))
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
            (_, killed_at, attacker_count, victim_alliance_id,
             victim_faction_id, n_fw_attackers) = km_meta[kid]
            if not attacker_allies:
                continue
            fw_ratio = min(1.0, n_fw_attackers / max(1, attacker_count))
            if victim_faction_id is None:
                taint = 0.0
            elif fw_ratio >= 0.5:
                taint = 1.0  # pure militia-vs-militia
            else:
                taint = 0.5  # victim enlisted but attackers mostly non-FW
            fw_dampen = max(0.1, 1.0 - taint)
            attendee_w = 1.0 / math.sqrt(max(1, attacker_count))
            age_seconds = anchor_ts - killed_at.replace(tzinfo=timezone.utc).timestamp()
            decay = 0.5 ** (age_seconds / half_life_seconds) if half_life_seconds > 0 else 1.0
            w = attendee_w * decay * fw_dampen

            eligible = [aid for aid, n in attacker_allies.items()
                        if n >= cfg.min_pilots_per_observation and aid]
            if len(eligible) > 30:
                eligible.sort(key=lambda aid: -attacker_allies[aid])
                eligible = eligible[:30]

            # SAME-side pairs. Track per-pair state + per-(pair, trigger)
            # conditional state where trigger is any *other* eligible
            # attacker on this km.
            n_elig = len(eligible)
            for idx_a in range(n_elig):
                for idx_b in range(idx_a + 1, n_elig):
                    a, b = sorted((eligible[idx_a], eligible[idx_b]))
                    _accumulate(pair_stats, (a, b), killed_at, True, w)
                    # Conditional: every other eligible is a "trigger".
                    for idx_t in range(n_elig):
                        if idx_t == idx_a or idx_t == idx_b:
                            continue
                        t = eligible[idx_t]
                        _accumulate_conditional(cond_stats, (a, b, t),
                                                same_side=True, weight=w)

            # OPPOSED pairs: each eligible attacker vs victim alliance.
            # Conditional trigger for opposed: any *other* eligible
            # attacker on this km.
            if victim_alliance_id and victim_alliance_id not in eligible:
                for idx_a in range(n_elig):
                    a_raw = eligible[idx_a]
                    a, b = sorted((a_raw, victim_alliance_id))
                    _accumulate(pair_stats, (a, b), killed_at, False, w)
                    for idx_t in range(n_elig):
                        if idx_t == idx_a:
                            continue
                        t = eligible[idx_t]
                        _accumulate_conditional(cond_stats, (a, b, t),
                                                same_side=False, weight=w)

        processed += len(slice_ids)
        if processed % 200000 == 0 or processed == len(km_rows):
            log.info("processed killmails", {
                "done": processed,
                "total": len(km_rows),
                "pairs": len(pair_stats),
                "triples": len(cond_stats),
            })

    return pair_stats, cond_stats


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


def _accumulate_conditional(cond_stats: dict, key: tuple[int, int, int],
                            same_side: bool, weight: float) -> None:
    st = cond_stats.get(key)
    if st is None:
        st = {
            "with_both_n": 0.0,
            "with_same_side": 0.0,
        }
        cond_stats[key] = st
    st["with_both_n"] += weight
    if same_side:
        st["with_same_side"] += weight


def _finalize_conditional_rows(cond_stats: dict, pair_stats: dict,
                               cfg: Config, window_end: date) -> list[dict]:
    """Derive conditional_delta per (pair, trigger) using the identity:
      without_n    = pair.weighted_n_obs     - cond.with_both_n
      without_same = pair.weighted_same_side - cond.with_same_side

    Prune triples with thin observations on either side + triples whose
    pair doesn't meet the absolute-observation floor."""
    rows: list[dict] = []
    for (a, b, t), st in cond_stats.items():
        pair = pair_stats.get((a, b))
        if pair is None:
            continue
        if pair["n_obs"] < cfg.min_pair_n_obs:
            continue

        with_n = float(st["with_both_n"])
        with_same = float(st["with_same_side"])
        without_n = float(pair["weighted_n_obs"]) - with_n
        without_same = float(pair["weighted_same_side"]) - with_same
        # Numerical guard.
        if without_n < 0:
            without_n = 0.0
        if without_same < 0:
            without_same = 0.0

        if with_n < cfg.min_conditional_trigger_obs or without_n < cfg.min_conditional_trigger_obs:
            continue

        rate_with = with_same / with_n if with_n > 0 else None
        rate_without = without_same / without_n if without_n > 0 else None
        if rate_with is None or rate_without is None:
            continue
        delta = round(rate_with - rate_without, 4)
        if abs(delta) < 0.1:
            continue  # below noise floor for current sample sizes

        conf = _confidence(min(with_n, without_n))
        rows.append({
            "alliance_a_id": a,
            "alliance_b_id": b,
            "trigger_alliance_id": t,
            "window_end_date": window_end,
            "n_obs_with_trigger": round(with_n, 4),
            "n_obs_without_trigger": round(without_n, 4),
            "same_side_rate_with": round(rate_with, 4),
            "same_side_rate_without": round(rate_without, 4),
            "conditional_delta": delta,
            "confidence": conf,
        })
    return rows


def _confidence(n_obs: float) -> float:
    if n_obs <= 0:
        return 0.0
    return round(min(1.0, math.log10(n_obs) / 2.0), 4)


# ----- persist ---------------------------------------------------------


def _persist_conditional_rows(conn, rows: list[dict], window_end: date, window_days: int) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "DELETE FROM alliance_pair_conditional_triggers_rolling WHERE window_end_date=%s AND window_days=%s",
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
                INSERT INTO alliance_pair_conditional_triggers_rolling
                  (alliance_a_id, alliance_b_id, trigger_alliance_id,
                   window_end_date, window_days,
                   n_obs_with_trigger, n_obs_without_trigger,
                   same_side_rate_with, same_side_rate_without,
                   conditional_delta, confidence, computed_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW())
                """,
                [
                    (
                        r["alliance_a_id"], r["alliance_b_id"], r["trigger_alliance_id"],
                        window_end, window_days,
                        r["n_obs_with_trigger"], r["n_obs_without_trigger"],
                        r["same_side_rate_with"], r["same_side_rate_without"],
                        r["conditional_delta"], r["confidence"],
                    )
                    for r in chunk
                ],
            )
    conn.commit()


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
