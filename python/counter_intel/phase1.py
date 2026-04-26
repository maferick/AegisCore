"""Phase 1 Counter-Intel signal expansion.

Extends ci_character_features_rolling and ci_character_anomalies_rolling
with signals from the spy-detection spec that are derivable from data
already present (killmails + character history + Neo4j CI graph) without
adding new ingestion sources.

Bloc-agnostic signals → ci_character_features_rolling:
  - dormancy_max_gap_days, dormancy_reactivated_at, dormancy_days_to_corp_change
  - corp_tenure_min_days, corp_tenure_stdev_days
  - small_gang_loss_count, solo_loss_count, pod_loss_count, ship_loss_count
  - pod_survival_rate, cheap_loss_rate, battle_only_score

Bloc-relative signals → ci_character_anomalies_rolling (per viewer_bloc):
  - asymmetric_top_pair_* (directional handler/asset proxy)
  - community_hostile_pct + community_neighbor_count
  - hostile_triangle_count + hostile_triangle_top_size

Only updates rows that already exist for the given window. Run after
features.py + anomalies.py have produced base rows.
"""

from __future__ import annotations

import statistics
from datetime import date, datetime, timedelta, timezone
from typing import Iterable

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase1")


# Cheap loss threshold — losses below this are common "noise" (frigates,
# pods, exploration ships, T1 bombers). Spec section 5.2: spy may feed
# cheap losses to gain credibility. We measure the rate.
CHEAP_LOSS_ISK_THRESHOLD = 50_000_000

# Dormancy gap that counts as a real reactivation. < 90d gaps are normal
# vacation / RL / EVE downtime; > 90d signals a parked account.
DORMANCY_GAP_THRESHOLD_DAYS = 90

# "Small gang" definition for §2 spec. Solo + small gang losses indicate
# normal main-character footprint (travel, exploration, frigate dueling).
SMALL_GANG_ATTACKER_CAP = 5

# Capsule group id (re-use Dashboard's constant).
POD_GROUP_ID = 29


def run_bloc_agnostic(
    conn: pymysql.connections.Connection,
    cfg: Config,
    window_end: date | None = None,
) -> dict:
    """Compute and persist bloc-agnostic Phase 1 signals."""
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    window_start = window_end - timedelta(days=cfg.window_days - 1)

    cids = _candidates(conn, window_end, cfg.window_days)
    log.info("phase1 bloc-agnostic starting", {"candidates": len(cids), "window_end": window_end.isoformat()})
    if not cids:
        return {"candidates": 0, "updated": 0}

    updated = 0
    for cid in cids:
        signals = _compute_one_bloc_agnostic(conn, cid, window_start, window_end)
        if signals is None:
            continue
        _persist_bloc_agnostic(conn, cid, window_end, cfg.window_days, signals)
        updated += 1
        if updated % 500 == 0:
            conn.commit()
            log.info("phase1 bloc-agnostic progress", {"updated": updated, "total": len(cids)})
    conn.commit()
    log.info("phase1 bloc-agnostic done", {"updated": updated})
    return {"candidates": len(cids), "updated": updated}


def run_bloc_relative(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date | None = None,
) -> dict:
    """Compute and persist bloc-relative Phase 1 signals."""
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    window_start = window_end - timedelta(days=cfg.window_days - 1)

    cids = _bloc_anomaly_candidates(conn, viewer_bloc_id, window_end, cfg.window_days)
    log.info(
        "phase1 bloc-relative starting",
        {"candidates": len(cids), "viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()},
    )
    if not cids:
        return {"candidates": 0, "updated": 0}

    hostile_alliances = _hostile_alliance_set(conn, viewer_bloc_id)
    log.info("hostile alliance set resolved", {"count": len(hostile_alliances)})

    updated = 0
    for cid in cids:
        signals = _compute_one_bloc_relative(conn, cid, window_start, window_end, hostile_alliances)
        if signals is None:
            continue
        _persist_bloc_relative(conn, cid, viewer_bloc_id, window_end, cfg.window_days, signals)
        updated += 1
        if updated % 250 == 0:
            conn.commit()
            log.info("phase1 bloc-relative progress", {"updated": updated, "total": len(cids)})
    conn.commit()
    log.info("phase1 bloc-relative done", {"updated": updated})
    return {"candidates": len(cids), "updated": updated, "hostile_alliances": len(hostile_alliances)}


# ----- candidate lists ---------------------------------------------------


def _candidates(conn: pymysql.connections.Connection, window_end: date, window_days: int) -> list[int]:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT character_id FROM ci_character_features_rolling "
            "WHERE window_end_date = %s AND window_days = %s",
            (window_end, window_days),
        )
        return [int(r["character_id"]) for r in cur.fetchall()]


def _bloc_anomaly_candidates(
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


def _hostile_alliance_set(conn: pymysql.connections.Connection, viewer_bloc_id: int) -> set[int]:
    """Every alliance not in the viewer's bloc is treated as 'hostile'
    for the purposes of this signal. Mirrors the resolveAllianceHostility
    convention in CounterIntelDossierService (anything outside viewer
    bloc → hostile)."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT entity_id FROM coalition_entity_labels "
            "WHERE entity_type='alliance' AND is_active=1 AND bloc_id <> %s",
            (viewer_bloc_id,),
        )
        return {int(r["entity_id"]) for r in cur.fetchall()}


# ----- bloc-agnostic per-character ---------------------------------------


def _compute_one_bloc_agnostic(
    conn: pymysql.connections.Connection,
    character_id: int,
    window_start: date,
    window_end: date,
) -> dict | None:
    out: dict = {}
    out.update(_dormancy_signals(conn, character_id))
    out.update(_corp_tenure_signals(conn, character_id))
    out.update(_loss_profile_signals(conn, character_id, window_start, window_end))
    # Battle-only score derived from existing solo_ratio + small-gang-loss ratio.
    out["battle_only_score"] = _battle_only_score(conn, character_id, out)
    return out


def _dormancy_signals(conn: pymysql.connections.Connection, character_id: int) -> dict:
    """Walk the union of (attacker, victim) killmail timestamps for the
    character and find the largest gap. If that gap exceeds the dormancy
    threshold, also locate the reactivation timestamp and the days-to-
    next-corp-change."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT t FROM (
              SELECT k.killed_at AS t
                FROM killmail_attackers ka
                JOIN killmails k ON k.killmail_id = ka.killmail_id
               WHERE ka.character_id = %s
              UNION
              SELECT killed_at AS t
                FROM killmails
               WHERE victim_character_id = %s
            ) u ORDER BY t
            """,
            (character_id, character_id),
        )
        rows = cur.fetchall()
    if len(rows) < 2:
        return {
            "dormancy_max_gap_days": None,
            "dormancy_reactivated_at": None,
            "dormancy_days_to_corp_change": None,
        }
    times = [r["t"] for r in rows]
    max_gap_days = 0
    reactivated_at: datetime | None = None
    for prev, cur_ts in zip(times, times[1:]):
        gap_days = (cur_ts - prev).total_seconds() / 86400.0
        if gap_days > max_gap_days:
            max_gap_days = gap_days
            reactivated_at = cur_ts
    out: dict = {
        "dormancy_max_gap_days": int(max_gap_days),
        "dormancy_reactivated_at": None,
        "dormancy_days_to_corp_change": None,
    }
    if max_gap_days >= DORMANCY_GAP_THRESHOLD_DAYS and reactivated_at is not None:
        out["dormancy_reactivated_at"] = reactivated_at
        # Find next corp join after reactivation. Spec §3.2: dormancy +
        # strategic timing (e.g. joining target alliance) is the hot signal.
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT MIN(start_date) AS nx
                  FROM character_corporation_history
                 WHERE character_id = %s AND is_deleted = 0
                   AND start_date > %s
                """,
                (character_id, reactivated_at),
            )
            row = cur.fetchone()
            if row and row["nx"] is not None:
                delta = row["nx"] - reactivated_at
                out["dormancy_days_to_corp_change"] = max(int(delta.total_seconds() / 86400), 0)
    return out


def _corp_tenure_signals(conn: pymysql.connections.Connection, character_id: int) -> dict:
    """Compute distinct corp tenure durations and report (min, stdev).
    Spec §3.1: corp-hopping cadence is most damning when each step is a
    similar duration — stdev catches that, min catches the suspicious
    short-stay alt corp pattern."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT start_date, COALESCE(end_date, NOW()) AS end_dt
              FROM character_corporation_history
             WHERE character_id = %s AND is_deleted = 0
             ORDER BY start_date
            """,
            (character_id,),
        )
        rows = cur.fetchall()
    if not rows:
        return {"corp_tenure_min_days": None, "corp_tenure_stdev_days": None}
    durations = [max(int(((r["end_dt"] - r["start_date"]).total_seconds()) / 86400), 0) for r in rows]
    out = {"corp_tenure_min_days": min(durations) if durations else None}
    if len(durations) >= 2:
        out["corp_tenure_stdev_days"] = round(statistics.pstdev(durations), 2)
    else:
        out["corp_tenure_stdev_days"] = None
    return out


def _loss_profile_signals(
    conn: pymysql.connections.Connection,
    character_id: int,
    window_start: date,
    window_end: date,
) -> dict:
    """Loss-side signals: small-gang count, solo count, pod count, ship
    count, pod_survival_rate, cheap_loss_rate. All restricted to the
    feature window."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT
              SUM(CASE WHEN attacker_count <= %s THEN 1 ELSE 0 END) AS small_gang_n,
              SUM(CASE WHEN attacker_count = 1 THEN 1 ELSE 0 END) AS solo_n,
              SUM(CASE WHEN victim_ship_group_id = %s THEN 1 ELSE 0 END) AS pod_n,
              SUM(CASE WHEN victim_ship_group_id IS NULL OR victim_ship_group_id <> %s THEN 1 ELSE 0 END) AS ship_n,
              SUM(CASE WHEN total_value < %s
                    AND (victim_ship_group_id IS NULL OR victim_ship_group_id <> %s)
                   THEN 1 ELSE 0 END) AS cheap_n,
              COUNT(*) AS total_n
              FROM killmails
             WHERE victim_character_id = %s
               AND killed_at BETWEEN %s AND %s
            """,
            (
                SMALL_GANG_ATTACKER_CAP,
                POD_GROUP_ID,
                POD_GROUP_ID,
                CHEAP_LOSS_ISK_THRESHOLD,
                POD_GROUP_ID,
                character_id,
                datetime.combine(window_start, datetime.min.time(), tzinfo=timezone.utc),
                datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc),
            ),
        )
        row = cur.fetchone() or {}

    small_gang = int(row.get("small_gang_n") or 0)
    solo = int(row.get("solo_n") or 0)
    pod = int(row.get("pod_n") or 0)
    ship = int(row.get("ship_n") or 0)
    cheap = int(row.get("cheap_n") or 0)

    pod_survival_rate: float | None
    if ship > 0:
        # Approximation: 1 - (pods / ship_losses). High = pilot escapes
        # without losing pod after most ship losses; low = consistently
        # caught and podded.
        pod_survival_rate = max(0.0, min(1.0, 1.0 - (pod / ship)))
    else:
        pod_survival_rate = None

    cheap_rate: float | None = (cheap / ship) if ship > 0 else None

    return {
        "small_gang_loss_count": small_gang,
        "solo_loss_count": solo,
        "pod_loss_count": pod,
        "ship_loss_count": ship,
        "pod_survival_rate": round(pod_survival_rate, 4) if pod_survival_rate is not None else None,
        "cheap_loss_rate": round(cheap_rate, 4) if cheap_rate is not None else None,
    }


def _battle_only_score(conn: pymysql.connections.Connection, character_id: int, signals: dict) -> float | None:
    """Higher = more abnormal. Composite of:
      - low solo_ratio (already in features_rolling)
      - low small-gang loss ratio
      - low cheap loss rate
    Pilot only ever shows up in big fights = score → 1. Real main with
    boring losses = score → 0."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT solo_ratio, killmails_attacker FROM ci_character_features_rolling "
            "WHERE character_id = %s ORDER BY window_end_date DESC LIMIT 1",
            (character_id,),
        )
        row = cur.fetchone()
    if row is None:
        return None
    solo_ratio = float(row.get("solo_ratio") or 0)
    attacker_n = int(row.get("killmails_attacker") or 0)
    if attacker_n < 10:
        return None  # not enough killmail footprint to score
    ship_loss = signals.get("ship_loss_count") or 0
    sg_loss = signals.get("small_gang_loss_count") or 0
    cheap = signals.get("cheap_loss_rate")
    sg_ratio = (sg_loss / ship_loss) if ship_loss > 0 else 0.0
    # Components: each is "abnormal-ness" in [0, 1].
    abnormal_solo = max(0.0, 1.0 - (solo_ratio / 0.10))  # solo_ratio >= 0.10 = normal
    abnormal_sg = max(0.0, 1.0 - (sg_ratio / 0.30))  # sg_ratio >= 0.30 of losses = normal
    abnormal_cheap = max(0.0, 1.0 - ((cheap or 0.0) / 0.20))  # cheap >= 20% = normal
    score = (abnormal_solo + abnormal_sg + abnormal_cheap) / 3.0
    return round(min(max(score, 0.0), 1.0), 4)


def _persist_bloc_agnostic(
    conn: pymysql.connections.Connection,
    character_id: int,
    window_end: date,
    window_days: int,
    s: dict,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE ci_character_features_rolling
               SET dormancy_max_gap_days = %s,
                   dormancy_reactivated_at = %s,
                   dormancy_days_to_corp_change = %s,
                   corp_tenure_min_days = %s,
                   corp_tenure_stdev_days = %s,
                   small_gang_loss_count = %s,
                   solo_loss_count = %s,
                   pod_loss_count = %s,
                   ship_loss_count = %s,
                   pod_survival_rate = %s,
                   cheap_loss_rate = %s,
                   battle_only_score = %s
             WHERE character_id = %s AND window_end_date = %s AND window_days = %s
            """,
            (
                s.get("dormancy_max_gap_days"),
                s.get("dormancy_reactivated_at"),
                s.get("dormancy_days_to_corp_change"),
                s.get("corp_tenure_min_days"),
                s.get("corp_tenure_stdev_days"),
                s.get("small_gang_loss_count") or 0,
                s.get("solo_loss_count") or 0,
                s.get("pod_loss_count") or 0,
                s.get("ship_loss_count") or 0,
                s.get("pod_survival_rate"),
                s.get("cheap_loss_rate"),
                s.get("battle_only_score"),
                character_id,
                window_end,
                window_days,
            ),
        )


# ----- bloc-relative per-character ---------------------------------------


def _compute_one_bloc_relative(
    conn: pymysql.connections.Connection,
    character_id: int,
    window_start: date,
    window_end: date,
    hostile_alliances: set[int],
) -> dict | None:
    out: dict = {}
    out.update(_asymmetric_signals(conn, character_id, window_start, window_end, hostile_alliances))
    out.update(_community_signals(conn, character_id, hostile_alliances))
    return out


def _asymmetric_signals(
    conn: pymysql.connections.Connection,
    character_id: int,
    window_start: date,
    window_end: date,
    hostile_alliances: set[int],
) -> dict:
    """Spec §1.2 directional handler/asset signal. For every hostile
    character B that appeared opposite A, count distinct (battle-day)
    co-occurrences. Top counterpart wins; we then compute B's own
    activity total to derive the inbound ratio.

    Battle-day proxy: distinct DATE(killed_at) values where (A as
    attacker AND B as victim) OR (A as victim AND B as attacker).
    Cheaper than full battle-theater join, captures the same intent."""
    if not hostile_alliances:
        return {
            "asymmetric_top_pair_character_id": None,
            "asymmetric_top_pair_outbound_pct": None,
            "asymmetric_top_pair_inbound_pct": None,
            "asymmetric_top_pair_battles": None,
        }
    window_start_dt = datetime.combine(window_start, datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)
    # Use a small placeholders list rather than a giant IN — for very
    # large hostile sets, fall back to a join on coalition_entity_labels.
    # Practical hostile_alliances size is < 5000 in current data; IN is fine.
    if len(hostile_alliances) > 4000:
        # Materialise a temp filter via the labels table for huge sets.
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

    # Distinct days A is opposed to each candidate B, restricted to the
    # window. UNION over (A-attacker, B-victim) and (A-victim, B-attacker).
    # Each leg already has the opponent's character_id + alliance_id, so
    # no extra join to killmail_attackers is needed.
    sql = (
        "SELECT opp_cid AS opp_id, COUNT(DISTINCT DATE(killed_at)) AS battle_days "
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
        "GROUP BY opp_cid "
        "HAVING battle_days >= 3 "
        "ORDER BY battle_days DESC LIMIT 1"
    )
    params = (
        character_id, window_start_dt, window_end_dt,
        character_id, window_start_dt, window_end_dt,
        character_id,
    ) + params_extra
    with conn.cursor() as cur:
        cur.execute(sql, params)
        top = cur.fetchone()
    if not top:
        return {
            "asymmetric_top_pair_character_id": None,
            "asymmetric_top_pair_outbound_pct": None,
            "asymmetric_top_pair_inbound_pct": None,
            "asymmetric_top_pair_battles": None,
        }
    opp_id = int(top["opp_id"])
    pair_battles = int(top["battle_days"])

    with conn.cursor() as cur:
        cur.execute(
            "SELECT COUNT(DISTINCT DATE(k.killed_at)) AS d FROM ( "
            "  SELECT killmail_id FROM killmail_attackers WHERE character_id=%s "
            "  UNION SELECT killmail_id FROM killmails WHERE victim_character_id=%s "
            ") mine JOIN killmails k ON k.killmail_id = mine.killmail_id "
            "WHERE k.killed_at BETWEEN %s AND %s",
            (character_id, character_id, window_start_dt, window_end_dt),
        )
        a_days = int((cur.fetchone() or {}).get("d") or 0)
        cur.execute(
            "SELECT COUNT(DISTINCT DATE(k.killed_at)) AS d FROM ( "
            "  SELECT killmail_id FROM killmail_attackers WHERE character_id=%s "
            "  UNION SELECT killmail_id FROM killmails WHERE victim_character_id=%s "
            ") mine JOIN killmails k ON k.killmail_id = mine.killmail_id "
            "WHERE k.killed_at BETWEEN %s AND %s",
            (opp_id, opp_id, window_start_dt, window_end_dt),
        )
        b_days = int((cur.fetchone() or {}).get("d") or 0)

    outbound_pct = (pair_battles / a_days) if a_days > 0 else None
    inbound_pct = (pair_battles / b_days) if b_days > 0 else None
    return {
        "asymmetric_top_pair_character_id": opp_id,
        "asymmetric_top_pair_outbound_pct": round(outbound_pct, 4) if outbound_pct is not None else None,
        "asymmetric_top_pair_inbound_pct": round(inbound_pct, 4) if inbound_pct is not None else None,
        "asymmetric_top_pair_battles": pair_battles,
    }


def _community_signals(
    conn: pymysql.connections.Connection,
    character_id: int,
    hostile_alliances: set[int],
) -> dict:
    """Spec §4.3: detected graph community vs declared affiliation. Use
    the existing co-flier set — every distinct character who appeared
    alongside this pilot on at least one killmail — and ask what fraction
    of those neighbours are in hostile-tagged alliances.

    Cheap proxy for full Louvain: doesn't run a community-detection pass,
    just reads CO_OCCURS_WITH-equivalent rows from killmail_attackers.
    Phase 2 can swap this for the Leiden ring populated by graph_features."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT ka2.character_id AS neighbor_id,
                   ka2.alliance_id AS neighbor_alliance
              FROM killmail_attackers ka
              JOIN killmail_attackers ka2 ON ka2.killmail_id = ka.killmail_id
             WHERE ka.character_id = %s
               AND ka2.character_id <> %s
             GROUP BY ka2.character_id, ka2.alliance_id
            """,
            (character_id, character_id),
        )
        rows = cur.fetchall()
    if not rows:
        return {"community_hostile_pct": None, "community_neighbor_count": 0}
    n = len(rows)
    hostile_n = sum(
        1 for r in rows
        if r.get("neighbor_alliance") and int(r["neighbor_alliance"]) in hostile_alliances
    )
    return {
        "community_hostile_pct": round(hostile_n / n, 4) if n else None,
        "community_neighbor_count": n,
    }


def _persist_bloc_relative(
    conn: pymysql.connections.Connection,
    character_id: int,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int,
    s: dict,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE ci_character_anomalies_rolling
               SET asymmetric_top_pair_character_id = %s,
                   asymmetric_top_pair_outbound_pct = %s,
                   asymmetric_top_pair_inbound_pct = %s,
                   asymmetric_top_pair_battles = %s,
                   community_hostile_pct = %s,
                   community_neighbor_count = %s
             WHERE character_id = %s
               AND viewer_bloc_id = %s
               AND window_end_date = %s
               AND window_days = %s
            """,
            (
                s.get("asymmetric_top_pair_character_id"),
                s.get("asymmetric_top_pair_outbound_pct"),
                s.get("asymmetric_top_pair_inbound_pct"),
                s.get("asymmetric_top_pair_battles"),
                s.get("community_hostile_pct"),
                s.get("community_neighbor_count"),
                character_id,
                viewer_bloc_id,
                window_end,
                window_days,
            ),
        )
