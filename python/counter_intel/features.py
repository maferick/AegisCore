"""Per-character feature extraction for the Counter-Intel Dossier.

Produces one row per (character_id, window_end_date, window_days) into
`ci_character_features_rolling`. Designed to be re-runnable daily;
upsert path overwrites the window_end_date row idempotently.

Heuristics called out in the planning lock:
  - Cold-start: pilots with < min_battles_in_window still get a row
    but has_sufficient_history=0.
  - Role percentages from killmail_pilot_role counts in window only.
  - Co-occurrence counters derived from killmail_attackers self-join;
    single-query for efficiency despite 35M rows (index on
    (killmail_id) + character_id helps).
  - Hour histogram: 24 bins of fractions, JSON-encoded.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.features")


@dataclass
class FeatureRow:
    character_id: int
    window_end_date: date
    window_days: int
    has_sufficient_history: bool
    battles: int
    active_days: int
    killmails_attacker: int
    killmails_victim: int
    avg_gang_size: float
    solo_ratio: float
    role_pcts: dict[str, float]
    dominant_role: str | None
    hour_histogram: list[float]
    distinct_cofliers: int
    cooccurrence_density: float
    same_side_ratio: float
    distinct_corps_in_window: int
    distinct_alliances_in_window: int
    affiliation_churn_rate: float
    distinct_corps_all_time: int
    distinct_alliances_all_time: int
    avg_damage_share: float
    days_since_last_activity: int


def extract_and_persist(conn: pymysql.connections.Connection, cfg: Config, window_end: date | None = None) -> dict:
    """Run one pass. Writes every active character's feature row. Returns
    a small stats dict for the CLI to print + log."""
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    window_start = window_end - timedelta(days=cfg.window_days - 1)
    window_start_dt = datetime.combine(window_start, datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)

    log.info(
        "features pass starting",
        {"window_start": window_start.isoformat(), "window_end": window_end.isoformat(), "window_days": cfg.window_days},
    )

    # 1) Candidate set — every character who appears as attacker or
    #    victim in the window. Empty-history pilots outside window get
    #    skipped entirely (no row).
    candidate_ids = _candidate_characters(conn, window_start_dt, window_end_dt)
    log.info("candidates loaded", {"count": len(candidate_ids)})
    if not candidate_ids:
        return {"candidates": 0, "written": 0}

    # 2) Batch per-character aggregates in one pass each to avoid
    #    N-queries. Each helper returns dict keyed by character_id.
    activity = _aggregate_activity(conn, window_start_dt, window_end_dt, candidate_ids)
    roles = _aggregate_roles(conn, window_start_dt, window_end_dt, candidate_ids)
    hour_hist = _aggregate_hour_histogram(conn, window_start_dt, window_end_dt, candidate_ids)
    social = _aggregate_social(conn, window_start_dt, window_end_dt, candidate_ids)
    churn = _aggregate_affiliation_churn(conn, window_start_dt, window_end_dt, candidate_ids)
    damage = _aggregate_damage_share(conn, window_start_dt, window_end_dt, candidate_ids)

    rows: list[FeatureRow] = []
    today = datetime.now(timezone.utc).date()
    for cid in candidate_ids:
        a = activity.get(cid, {})
        battles = int(a.get("battles", 0))
        active_days = int(a.get("active_days", 0))
        atk = int(a.get("km_attacker", 0))
        vic = int(a.get("km_victim", 0))
        avg_gang = float(a.get("avg_gang_size") or 0.0)
        solo_kills = int(a.get("solo_kills", 0))
        last_activity = a.get("last_activity_at")
        days_since = (today - last_activity.date()).days if isinstance(last_activity, datetime) else 9999
        solo_ratio = (solo_kills / atk) if atk > 0 else 0.0

        r = roles.get(cid, {})
        total_roled = sum(r.values()) or 1
        role_pcts = {k: (v / total_roled) for k, v in r.items()}
        for rk in ("fc", "logi", "bomber", "command", "tackle", "mainline_dps"):
            role_pcts.setdefault(rk, 0.0)
        dominant = max(role_pcts.items(), key=lambda kv: kv[1])[0] if total_roled > 0 else None
        if dominant and role_pcts[dominant] < 0.15:
            # No single role >= 15% = genuinely mixed. Don't pin a
            # dominant_role so the similarity stage sees it as
            # "unsettled" and can fallback to neighborhood-only peers.
            dominant = None

        hh = hour_hist.get(cid) or [0.0] * 24

        s = social.get(cid, {})
        distinct_coflyers = int(s.get("distinct_coflyers", 0))
        co_density = (distinct_coflyers / battles) if battles > 0 else 0.0
        same_side_ratio = atk / (atk + vic) if (atk + vic) > 0 else 0.0

        c = churn.get(cid, {})
        distinct_corps_win = int(c.get("corps_in_window", 0))
        distinct_alliances_win = int(c.get("alliances_in_window", 0))
        # Normalized churn: 1.0 = switched corp every ~30 days in window.
        churn_rate = min(1.0, distinct_corps_win / max(1.0, cfg.window_days / 30.0))
        distinct_corps_all = int(c.get("corps_all_time", 0))
        distinct_alliances_all = int(c.get("alliances_all_time", 0))

        d = damage.get(cid, {})
        avg_dmg = float(d.get("avg_damage_share") or 0.0)

        # Eligibility = raw killmail appearances. Theater clustering only
        # weights edge relationships (dampener in projection), never
        # gates who is scored. A pilot with 5 killmails in the window
        # is visible to counter-intel regardless of whether the theater
        # clusterer has swept that period yet.
        appearances = atk + vic
        has_enough = appearances >= cfg.min_appearances_90d

        rows.append(FeatureRow(
            character_id=cid,
            window_end_date=window_end,
            window_days=cfg.window_days,
            has_sufficient_history=has_enough,
            battles=battles,
            active_days=active_days,
            killmails_attacker=atk,
            killmails_victim=vic,
            avg_gang_size=avg_gang,
            solo_ratio=solo_ratio,
            role_pcts=role_pcts,
            dominant_role=dominant,
            hour_histogram=hh,
            distinct_cofliers=distinct_coflyers,
            cooccurrence_density=co_density,
            same_side_ratio=same_side_ratio,
            distinct_corps_in_window=distinct_corps_win,
            distinct_alliances_in_window=distinct_alliances_win,
            affiliation_churn_rate=churn_rate,
            distinct_corps_all_time=distinct_corps_all,
            distinct_alliances_all_time=distinct_alliances_all,
            avg_damage_share=avg_dmg,
            days_since_last_activity=min(9999, days_since),
        ))

    log.info("rows built", {"total": len(rows), "sufficient": sum(1 for r in rows if r.has_sufficient_history)})
    _persist_rows(conn, rows)
    return {"candidates": len(candidate_ids), "written": len(rows)}


# ----- aggregation helpers --------------------------------------------


def _candidate_characters(conn, ws: datetime, we: datetime) -> list[int]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT DISTINCT character_id AS cid FROM (
                SELECT ka.character_id
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                 WHERE ka.character_id IS NOT NULL
                   AND k.killed_at BETWEEN %s AND %s
                UNION
                SELECT victim_character_id AS character_id
                  FROM killmails
                 WHERE victim_character_id IS NOT NULL
                   AND killed_at BETWEEN %s AND %s
            ) u
            """,
            (ws, we, ws, we),
        )
        return [int(r["cid"]) for r in cur.fetchall() if r["cid"] is not None]


def _aggregate_activity(conn, ws: datetime, we: datetime, ids: list[int]) -> dict[int, dict]:
    out: dict[int, dict] = {}
    if not ids:
        return out
    chunk = 5000
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            # Attacker side
            cur.execute(
                f"""
                SELECT ka.character_id AS cid,
                       COUNT(DISTINCT ka.killmail_id) AS km_attacker,
                       COUNT(DISTINCT DATE(k.killed_at)) AS active_days,
                       AVG(k.attacker_count) AS avg_gang_size,
                       SUM(CASE WHEN k.attacker_count = 1 THEN 1 ELSE 0 END) AS solo_kills,
                       MAX(k.killed_at) AS last_activity_at
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                 WHERE ka.character_id IN ({ph})
                   AND k.killed_at BETWEEN %s AND %s
                 GROUP BY ka.character_id
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                cid = int(r["cid"])
                out[cid] = {
                    "km_attacker": int(r["km_attacker"] or 0),
                    "active_days": int(r["active_days"] or 0),
                    "avg_gang_size": float(r["avg_gang_size"] or 0.0),
                    "solo_kills": int(r["solo_kills"] or 0),
                    "last_activity_at": r["last_activity_at"],
                }
            # Victim side (merge).
            cur.execute(
                f"""
                SELECT victim_character_id AS cid,
                       COUNT(*) AS km_victim,
                       MAX(killed_at) AS last_activity_at
                  FROM killmails
                 WHERE victim_character_id IN ({ph})
                   AND killed_at BETWEEN %s AND %s
                 GROUP BY victim_character_id
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                cid = int(r["cid"])
                slot = out.setdefault(cid, {})
                slot["km_victim"] = int(r["km_victim"] or 0)
                prev = slot.get("last_activity_at")
                curr = r["last_activity_at"]
                if curr and (prev is None or curr > prev):
                    slot["last_activity_at"] = curr
            # Battles participated from battle_theater_participants.
            cur.execute(
                f"""
                SELECT p.character_id AS cid, COUNT(DISTINCT p.theater_id) AS battles
                  FROM battle_theater_participants p
                  JOIN battle_theaters bt ON bt.id = p.theater_id
                 WHERE p.character_id IN ({ph})
                   AND bt.end_time BETWEEN %s AND %s
                 GROUP BY p.character_id
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                cid = int(r["cid"])
                slot = out.setdefault(cid, {})
                slot["battles"] = int(r["battles"] or 0)
    return out


def _aggregate_roles(conn, ws: datetime, we: datetime, ids: list[int]) -> dict[int, dict]:
    out: dict[int, dict] = {}
    if not ids:
        return out
    chunk = 5000
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT kpr.character_id AS cid, kpr.role_key, COUNT(*) AS n
                  FROM killmail_pilot_role kpr
                  JOIN killmails k ON k.killmail_id = kpr.killmail_id
                 WHERE kpr.character_id IN ({ph})
                   AND k.killed_at BETWEEN %s AND %s
                   AND kpr.role_key IN ('fc','logi','bomber','command','tackle','mainline_dps')
                 GROUP BY kpr.character_id, kpr.role_key
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                cid = int(r["cid"])
                out.setdefault(cid, {})[str(r["role_key"])] = int(r["n"])
    return out


def _aggregate_hour_histogram(conn, ws: datetime, we: datetime, ids: list[int]) -> dict[int, list[float]]:
    """HOUR(killed_at) bucketed across attacker appearances, normalized
    to sum to 1 per character."""
    out: dict[int, list[float]] = {}
    if not ids:
        return out
    chunk = 5000
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT ka.character_id AS cid, HOUR(k.killed_at) AS h, COUNT(*) AS n
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                 WHERE ka.character_id IN ({ph})
                   AND k.killed_at BETWEEN %s AND %s
                 GROUP BY ka.character_id, HOUR(k.killed_at)
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                cid = int(r["cid"])
                hist = out.setdefault(cid, [0.0] * 24)
                hist[int(r["h"])] = float(r["n"])
    # Normalize.
    for cid, hist in out.items():
        total = sum(hist)
        if total > 0:
            out[cid] = [x / total for x in hist]
    return out


def _aggregate_social(conn, ws: datetime, we: datetime, ids: list[int]) -> dict[int, dict]:
    """Distinct co-flyer count per character (other characters on the
    same killmails as attackers). Heavy; chunked + single pass."""
    out: dict[int, dict] = {}
    if not ids:
        return out
    chunk = 2000
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT ka.character_id AS cid,
                       COUNT(DISTINCT ka2.character_id) AS distinct_coflyers
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                  JOIN killmail_attackers ka2 ON ka2.killmail_id = ka.killmail_id
                                              AND ka2.character_id IS NOT NULL
                                              AND ka2.character_id <> ka.character_id
                 WHERE ka.character_id IN ({ph})
                   AND k.killed_at BETWEEN %s AND %s
                 GROUP BY ka.character_id
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                cid = int(r["cid"])
                out[cid] = {"distinct_coflyers": int(r["distinct_coflyers"] or 0)}
    return out


def _aggregate_affiliation_churn(conn, ws: datetime, we: datetime, ids: list[int]) -> dict[int, dict]:
    """Distinct corps + alliances the character was in during window +
    across their full cached history."""
    out: dict[int, dict] = {}
    if not ids:
        return out
    chunk = 5000
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            # In-window (history rows overlapping the window).
            cur.execute(
                f"""
                SELECT ch.character_id AS cid,
                       COUNT(DISTINCT ch.corporation_id) AS corps_in_window
                  FROM character_corporation_history ch
                 WHERE ch.character_id IN ({ph})
                   AND ch.is_deleted = 0
                   AND ch.start_date <= %s
                   AND (ch.end_date IS NULL OR ch.end_date >= %s)
                 GROUP BY ch.character_id
                """,
                batch + [we, ws],
            )
            for r in cur.fetchall():
                out.setdefault(int(r["cid"]), {})["corps_in_window"] = int(r["corps_in_window"] or 0)
            # All-time corp count.
            cur.execute(
                f"""
                SELECT ch.character_id AS cid,
                       COUNT(DISTINCT ch.corporation_id) AS corps_all_time
                  FROM character_corporation_history ch
                 WHERE ch.character_id IN ({ph})
                   AND ch.is_deleted = 0
                 GROUP BY ch.character_id
                """,
                batch,
            )
            for r in cur.fetchall():
                out.setdefault(int(r["cid"]), {})["corps_all_time"] = int(r["corps_all_time"] or 0)
    # Alliance counts (cross-join via corporation_alliance_history).
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT ch.character_id AS cid,
                       COUNT(DISTINCT cah.alliance_id) AS alliances_all_time
                  FROM character_corporation_history ch
                  JOIN corporation_alliance_history cah
                    ON cah.corporation_id = ch.corporation_id
                   AND (cah.start_date <= IFNULL(ch.end_date, NOW()))
                   AND (cah.end_date IS NULL OR cah.end_date >= ch.start_date)
                 WHERE ch.character_id IN ({ph})
                   AND ch.is_deleted = 0
                   AND cah.alliance_id IS NOT NULL
                 GROUP BY ch.character_id
                """,
                batch,
            )
            for r in cur.fetchall():
                out.setdefault(int(r["cid"]), {})["alliances_all_time"] = int(r["alliances_all_time"] or 0)
    # In-window alliance distinct count (simpler approximation).
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT ka.character_id AS cid,
                       COUNT(DISTINCT ka.alliance_id) AS alliances_in_window
                  FROM killmail_attackers ka
                  JOIN killmails k ON k.killmail_id = ka.killmail_id
                 WHERE ka.character_id IN ({ph})
                   AND ka.alliance_id IS NOT NULL
                   AND k.killed_at BETWEEN %s AND %s
                 GROUP BY ka.character_id
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                out.setdefault(int(r["cid"]), {})["alliances_in_window"] = int(r["alliances_in_window"] or 0)
    return out


def _aggregate_damage_share(conn, ws: datetime, we: datetime, ids: list[int]) -> dict[int, dict]:
    """Mean damage_share from battle_character_role_features rows
    overlapping the window."""
    out: dict[int, dict] = {}
    if not ids:
        return out
    chunk = 5000
    for i in range(0, len(ids), chunk):
        batch = ids[i:i + chunk]
        ph = ",".join(["%s"] * len(batch))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT f.character_id AS cid,
                       AVG(f.damage_share) AS avg_damage_share
                  FROM battle_character_role_features f
                  JOIN battle_theaters bt ON bt.id = f.battle_id
                 WHERE f.character_id IN ({ph})
                   AND bt.end_time BETWEEN %s AND %s
                 GROUP BY f.character_id
                """,
                batch + [ws, we],
            )
            for r in cur.fetchall():
                out[int(r["cid"])] = {"avg_damage_share": float(r["avg_damage_share"] or 0.0)}
    return out


# ----- persist ---------------------------------------------------------


def _persist_rows(conn, rows: list[FeatureRow]) -> None:
    if not rows:
        return
    with conn.cursor() as cur:
        cur.executemany(
            """
            INSERT INTO ci_character_features_rolling
                (character_id, window_end_date, window_days,
                 has_sufficient_history,
                 battles, active_days, killmails_attacker, killmails_victim,
                 avg_gang_size, solo_ratio,
                 role_fc_pct, role_logi_pct, role_bomber_pct,
                 role_command_pct, role_tackle_pct, role_dps_pct,
                 dominant_role, hour_histogram,
                 distinct_cofliers, cooccurrence_density, same_side_ratio,
                 distinct_corps_in_window, distinct_alliances_in_window,
                 affiliation_churn_rate,
                 distinct_corps_all_time, distinct_alliances_all_time,
                 avg_damage_share, days_since_last_activity, computed_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
            ON DUPLICATE KEY UPDATE
                has_sufficient_history = VALUES(has_sufficient_history),
                battles = VALUES(battles),
                active_days = VALUES(active_days),
                killmails_attacker = VALUES(killmails_attacker),
                killmails_victim = VALUES(killmails_victim),
                avg_gang_size = VALUES(avg_gang_size),
                solo_ratio = VALUES(solo_ratio),
                role_fc_pct = VALUES(role_fc_pct),
                role_logi_pct = VALUES(role_logi_pct),
                role_bomber_pct = VALUES(role_bomber_pct),
                role_command_pct = VALUES(role_command_pct),
                role_tackle_pct = VALUES(role_tackle_pct),
                role_dps_pct = VALUES(role_dps_pct),
                dominant_role = VALUES(dominant_role),
                hour_histogram = VALUES(hour_histogram),
                distinct_cofliers = VALUES(distinct_cofliers),
                cooccurrence_density = VALUES(cooccurrence_density),
                same_side_ratio = VALUES(same_side_ratio),
                distinct_corps_in_window = VALUES(distinct_corps_in_window),
                distinct_alliances_in_window = VALUES(distinct_alliances_in_window),
                affiliation_churn_rate = VALUES(affiliation_churn_rate),
                distinct_corps_all_time = VALUES(distinct_corps_all_time),
                distinct_alliances_all_time = VALUES(distinct_alliances_all_time),
                avg_damage_share = VALUES(avg_damage_share),
                days_since_last_activity = VALUES(days_since_last_activity),
                computed_at = NOW()
            """,
            [
                (
                    r.character_id,
                    r.window_end_date,
                    r.window_days,
                    int(r.has_sufficient_history),
                    r.battles,
                    r.active_days,
                    r.killmails_attacker,
                    r.killmails_victim,
                    r.avg_gang_size,
                    r.solo_ratio,
                    r.role_pcts.get("fc", 0.0),
                    r.role_pcts.get("logi", 0.0),
                    r.role_pcts.get("bomber", 0.0),
                    r.role_pcts.get("command", 0.0),
                    r.role_pcts.get("tackle", 0.0),
                    r.role_pcts.get("mainline_dps", 0.0),
                    r.dominant_role,
                    json.dumps(r.hour_histogram),
                    r.distinct_cofliers,
                    r.cooccurrence_density,
                    r.same_side_ratio,
                    r.distinct_corps_in_window,
                    r.distinct_alliances_in_window,
                    r.affiliation_churn_rate,
                    r.distinct_corps_all_time,
                    r.distinct_alliances_all_time,
                    r.avg_damage_share,
                    r.days_since_last_activity,
                )
                for r in rows
            ],
        )
    conn.commit()
