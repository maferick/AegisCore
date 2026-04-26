"""Phase 4.9 — intelligence freshness compute.

Per-surface TTL ladder classifies each row as
fresh / aging / stale / expired.

Idempotent. Reads the row's authoritative timestamp (varies by
surface), compares to current UTC, derives the state, and updates
the freshness_state column. Also fills source_window_start /
source_window_end where the surface has them implicitly so the UI
can render a uniform "valid from / valid to" line.

Re-running keeps the table accurate. Pair with read-time helper
on the PHP side so freshness is recomputed live for hot rows.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4_freshness")


# Per-surface TTL ladder (hours). State is determined by comparing
# (now - authoritative_timestamp) against these thresholds.
SURFACE_TTL: dict[str, dict] = {
    "daily_operational_digest": {
        "ts_col": "generated_at",
        "fresh": 6, "aging": 24, "stale": 72,
        "window_start_col": None, "window_end_col": "generated_at",
        "where": "1=1",
    },
    "strategic_alerts": {
        "ts_col": "detected_at",
        "fresh": 1, "aging": 6, "stale": 24,
        "window_start_col": "window_start", "window_end_col": "window_end",
        "where": "dismissed_at IS NULL",
    },
    "operational_incidents": {
        "ts_col": "end_at",
        "fresh": 0.5, "aging": 6, "stale": 48,
        "window_start_col": "start_at", "window_end_col": "end_at",
        "where": "1=1",
    },
    "operational_hostile_clusters": {
        "ts_col": "end_at",
        "fresh": 0.5, "aging": 6, "stale": 48,
        "window_start_col": "start_at", "window_end_col": "end_at",
        "where": "1=1",
    },
    "operational_corridors": {
        "ts_col": "last_seen_at",
        "fresh": 24, "aging": 24 * 7, "stale": 24 * 30,
        "window_start_col": "first_seen_at", "window_end_col": "last_seen_at",
        "where": "1=1",
    },
    "operational_force_compositions": {
        "ts_col": "snapshot_at",
        "fresh": 24, "aging": 24 * 7, "stale": 24 * 30,
        "window_start_col": "snapshot_at", "window_end_col": "snapshot_at",
        "where": "snapshot_at IS NOT NULL",
    },
    "system_threat_surface": {
        "ts_col": "computed_at",
        "fresh": 24, "aging": 24 * 7, "stale": 24 * 14,
        "window_start_col": None, "window_end_col": "computed_at",
        "where": "1=1",
    },
    "alliance_operational_profiles": {
        "ts_col": "computed_at",
        "fresh": 24, "aging": 24 * 7, "stale": 24 * 30,
        "window_start_col": "window_start", "window_end_col": "window_end",
        "where": "1=1",
    },
    "coalition_behavior_comparisons": {
        "ts_col": "computed_at",
        "fresh": 24, "aging": 24 * 7, "stale": 24 * 30,
        "window_start_col": "window_start", "window_end_col": "window_end",
        "where": "1=1",
    },
    "incident_narratives": {
        "ts_col": "computed_at",
        "fresh": 6, "aging": 24, "stale": 24 * 7,
        "window_start_col": None, "window_end_col": "computed_at",
        "where": "1=1",
    },
    "doctrine_evolution_events": {
        "ts_col": "computed_at",
        "fresh": 24 * 7, "aging": 24 * 30, "stale": 24 * 90,
        "window_start_col": None, "window_end_col": "window_end",
        "where": "1=1",
    },
    "verified_intelligence_items": {
        "ts_col": "verified_at",
        "fresh": 24 * 7, "aging": 24 * 30, "stale": 24 * 90,
        "window_start_col": "verified_at", "window_end_col": "expires_at",
        "where": "verified_at IS NOT NULL",
    },
}


def run_freshness(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int | None = None,
) -> dict:
    """Recompute freshness_state across every intel surface.

    When viewer_bloc_id is None, walk every bloc's rows. Otherwise
    scope by viewer_bloc_id.
    """
    log.info("phase4.9 freshness starting", {"viewer_bloc_id": viewer_bloc_id})
    now = datetime.now(timezone.utc)
    totals: dict[str, dict[str, int]] = {}

    for table, cfg_t in SURFACE_TTL.items():
        ts_col = cfg_t["ts_col"]
        fresh_h = cfg_t["fresh"]
        aging_h = cfg_t["aging"]
        stale_h = cfg_t["stale"]

        # CASE expression mirrors the read-time PHP helper. Bands are
        # cumulative — first match wins.
        case_sql = f"""
            CASE
              WHEN {ts_col} IS NULL THEN 'expired'
              WHEN TIMESTAMPDIFF(MINUTE, {ts_col}, %s) <= {int(fresh_h * 60)} THEN 'fresh'
              WHEN TIMESTAMPDIFF(MINUTE, {ts_col}, %s) <= {int(aging_h * 60)} THEN 'aging'
              WHEN TIMESTAMPDIFF(MINUTE, {ts_col}, %s) <= {int(stale_h * 60)} THEN 'stale'
              ELSE 'expired'
            END
        """

        # Persist the explicit source_window_* if the table provides
        # candidate columns; otherwise leave NULL.
        ws = cfg_t["window_start_col"]
        we = cfg_t["window_end_col"]
        sets = [f"freshness_state = ({case_sql})"]
        if ws:
            sets.append(f"source_window_start = {ws}")
        if we:
            sets.append(f"source_window_end = {we}")

        where = cfg_t["where"]
        params: list = [now, now, now]

        # Bloc scoping. operational_force_compositions, system_threat_surface,
        # daily_operational_digest, etc. all carry viewer_bloc_id.
        if viewer_bloc_id is not None and _has_viewer_bloc(table):
            where = f"({where}) AND viewer_bloc_id = %s"
            params.append(viewer_bloc_id)

        sql = f"UPDATE {table} SET {', '.join(sets)} WHERE {where}"
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params))
            updated = cur.rowcount

        # Tally per-state distribution for telemetry.
        with conn.cursor() as cur:
            scope = ""
            scope_params: list = []
            if viewer_bloc_id is not None and _has_viewer_bloc(table):
                scope = " WHERE viewer_bloc_id = %s"
                scope_params.append(viewer_bloc_id)
            cur.execute(
                f"SELECT freshness_state, COUNT(*) AS n FROM {table}{scope} GROUP BY freshness_state",
                tuple(scope_params),
            )
            tally = {r["freshness_state"]: int(r["n"]) for r in cur.fetchall()}
        totals[table] = tally
        log.info("phase4.9 freshness table done",
                 {"table": table, "updated": updated, "tally": tally})

    conn.commit()
    log.info("phase4.9 freshness complete", {"surfaces": len(totals)})
    return {"surfaces": len(totals), "by_table": totals}


_BLOC_SCOPED = {
    "daily_operational_digest", "strategic_alerts",
    "operational_incidents", "operational_hostile_clusters",
    "operational_corridors", "operational_force_compositions",
    "system_threat_surface", "alliance_operational_profiles",
    "coalition_behavior_comparisons", "incident_narratives",
    "doctrine_evolution_events", "verified_intelligence_items",
}


def _has_viewer_bloc(table: str) -> bool:
    return table in _BLOC_SCOPED
