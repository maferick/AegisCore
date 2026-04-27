"""Phase 4.9 — intelligence freshness compute.

Per-surface TTL ladder classifies each row as
fresh / aging / stale / expired.

The TTL ladder itself comes from `intel_ttl.json` (single source
of truth, mirrored to app/config/intel_ttl.json for PHP). Static
table-specific bindings (timestamp column, predicate, etc.) stay
hard-coded here because they're SQL-shape concerns rather than
ladder values.

Idempotent. Reads the row's authoritative timestamp (varies by
surface), compares to current UTC, derives the state, and updates
the freshness_state column. Also fills source_window_start /
source_window_end where the surface has them implicitly so the UI
can render a uniform "valid from / valid to" line.

Re-running keeps the table accurate. Pair with read-time helper
on the PHP side so freshness is recomputed live for hot rows.
"""

from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4_freshness")


def _load_ttl_config() -> dict:
    p = Path(__file__).parent / "intel_ttl.json"
    with p.open() as f:
        return json.load(f)


_TTL = _load_ttl_config()
_FRESHNESS_HOURS = _TTL["freshness_ttl_hours"]


# Per-surface TTL ladder (hours). State is determined by comparing
# (now - authoritative_timestamp) against these thresholds.
# Bindings — table + timestamp column + predicate. TTL hours come
# from intel_ttl.json (canonical) so PHP + Python share values.
def _surface_entry(surface_key: str, ts_col: str, ws: str | None, we: str | None, where: str) -> dict:
    fresh, aging, stale = _FRESHNESS_HOURS[surface_key]
    return {
        "ts_col": ts_col, "fresh": fresh, "aging": aging, "stale": stale,
        "window_start_col": ws, "window_end_col": we, "where": where,
    }


SURFACE_TTL: dict[str, dict] = {
    "daily_operational_digest": _surface_entry(
        "digest", "generated_at", None, "generated_at", "1=1"),
    "strategic_alerts": _surface_entry(
        "alert", "detected_at", "window_start", "window_end", "dismissed_at IS NULL"),
    "operational_incidents": _surface_entry(
        "incident", "end_at", "start_at", "end_at", "1=1"),
    "operational_hostile_clusters": _surface_entry(
        "cluster", "end_at", "start_at", "end_at", "1=1"),
    "operational_corridors": _surface_entry(
        "corridor", "last_seen_at", "first_seen_at", "last_seen_at", "1=1"),
    "operational_force_compositions": _surface_entry(
        "force_composition", "snapshot_at", "snapshot_at", "snapshot_at",
        "snapshot_at IS NOT NULL"),
    "system_threat_surface": _surface_entry(
        "threat_surface", "computed_at", None, "computed_at", "1=1"),
    "alliance_operational_profiles": _surface_entry(
        "alliance_profile", "computed_at", "window_start", "window_end", "1=1"),
    "coalition_behavior_comparisons": _surface_entry(
        "coalition", "computed_at", "window_start", "window_end", "1=1"),
    "incident_narratives": _surface_entry(
        "narrative", "computed_at", None, "computed_at", "1=1"),
    "doctrine_evolution_events": _surface_entry(
        "doctrine_evolution", "computed_at", None, "window_end", "1=1"),
    "verified_intelligence_items": _surface_entry(
        "verified", "verified_at", "verified_at", "expires_at",
        "verified_at IS NOT NULL"),
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
