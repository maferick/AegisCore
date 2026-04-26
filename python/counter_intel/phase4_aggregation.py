"""Phase 4.3 — operational aggregation.

Consumes raw eve_log_events + entity_resolutions and produces
operational artefacts that describe "what happened operationally"
rather than "what individual lines existed":

  run_hostile_clusters     §4.3A → operational_hostile_clusters
  run_incidents            §4.3B → operational_incidents
  run_battle_linkage       §4.3C → updates incidents.battle_id
  run_system_activity      §4.3D → system_operational_activity

Each pass is idempotent (UPSERT on a stable key). Compute order
matters for §4.3B (clusters before incidents) and §4.3C (incidents
before linkage).
"""

from __future__ import annotations

import json
import os
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4_aggregation")


def _env_int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None:
        return default
    try:
        return int(raw)
    except ValueError:
        return default


# Cluster tunables. 5-min rolling window; merge events with the same
# primary_system_id whose timestamps are within CLUSTER_GAP_SECONDS.
CLUSTER_GAP_SECONDS = _env_int("PHASE4_HOSTILE_CLUSTER_GAP_SEC", 5 * 60)


# =====================================================================
# §4.3A — hostile-report clustering
# =====================================================================

@dataclass
class _ClusterAcc:
    primary_system_id: int | None = None
    primary_system_name: str | None = None
    primary_region_id: int | None = None
    start_at: datetime | None = None
    end_at: datetime | None = None
    adjacent_systems: set[int] = field(default_factory=set)
    characters: dict[int, str] = field(default_factory=dict)
    reporters: set[str] = field(default_factory=set)
    report_count: int = 0
    sample_event_ids: list[int] = field(default_factory=list)


def run_hostile_clusters(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
) -> dict:
    """Group intel_report events into operational clusters keyed on
    primary_system + 5-min proximity."""
    log.info("phase4.3A hostile clusters starting", {"viewer_bloc_id": viewer_bloc_id, "since": since_dt.isoformat()})

    # Pull every intel_report + its system + character resolutions in
    # one ordered scan. We aggregate in Python because the windowing
    # is per-(primary_system, time_proximity), not pure SQL-friendly.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT e.id AS event_id, e.event_timestamp, e.actor_name,
                   sys.resolved_entity_id AS sys_id,
                   sys.resolved_entity_name AS sys_name,
                   chr.resolved_entity_id AS chr_id,
                   chr.resolved_entity_name AS chr_name
              FROM eve_log_events e
              LEFT JOIN eve_log_entity_resolutions sys
                ON sys.eve_log_event_id = e.id
               AND sys.resolved_entity_type = 'system'
              LEFT JOIN eve_log_entity_resolutions chr
                ON chr.eve_log_event_id = e.id
               AND chr.resolved_entity_type = 'character'
               AND chr.resolution_confidence IN ('medium','high')
             WHERE e.event_type = 'intel_report'
               AND e.event_timestamp >= %s
               AND e.actor_name IS NOT NULL
             ORDER BY e.event_timestamp
            """,
            (since_dt,),
        )
        rows = list(cur.fetchall())
    if not rows:
        log.info("phase4.3A no rows", {})
        return {"events": 0, "clusters_written": 0}

    # Resolve region_id for every system referenced — single batch lookup.
    sys_ids = sorted({int(r["sys_id"]) for r in rows if r["sys_id"]})
    sys_meta: dict[int, tuple[str, int | None]] = {}
    if sys_ids:
        chunk = sys_ids
        with conn.cursor() as cur:
            placeholders = ",".join(["%s"] * len(chunk))
            cur.execute(
                f"SELECT id, name, region_id FROM ref_solar_systems WHERE id IN ({placeholders})",
                tuple(chunk),
            )
            for r in cur.fetchall():
                sys_meta[int(r["id"])] = (
                    str(r["name"]),
                    int(r["region_id"]) if r["region_id"] is not None else None,
                )

    # Bucket events by event_id so multi-system / multi-char rows fold
    # back into one event with sets of ids.
    events: dict[int, dict] = {}
    for r in rows:
        eid = int(r["event_id"])
        if eid not in events:
            events[eid] = {
                "event_timestamp": r["event_timestamp"],
                "actor_name": r["actor_name"],
                "systems": [],
                "characters": {},
            }
        if r["sys_id"]:
            sid = int(r["sys_id"])
            sname = str(r["sys_name"])
            if (sid, sname) not in events[eid]["systems"]:
                events[eid]["systems"].append((sid, sname))
        if r["chr_id"] is not None:
            events[eid]["characters"][int(r["chr_id"])] = str(r["chr_name"] or "")

    # Cluster: for each event with a primary system, append to the
    # active cluster on that system if the gap < threshold; otherwise
    # close the previous and start a fresh one.
    active: dict[int, _ClusterAcc] = {}
    closed: list[_ClusterAcc] = []
    for eid in sorted(events.keys(), key=lambda x: events[x]["event_timestamp"]):
        ev = events[eid]
        if not ev["systems"]:
            continue  # intel report with no resolved system — skip cluster path
        primary_sid, primary_sname = ev["systems"][0]
        ts = ev["event_timestamp"]
        adjacents = [sid for sid, _ in ev["systems"][1:]]
        cur_cluster = active.get(primary_sid)
        if cur_cluster is None or (ts - cur_cluster.end_at).total_seconds() > CLUSTER_GAP_SECONDS:
            if cur_cluster is not None:
                closed.append(cur_cluster)
            sname, rid = sys_meta.get(primary_sid, (primary_sname, None))
            cur_cluster = _ClusterAcc(
                primary_system_id=primary_sid,
                primary_system_name=sname,
                primary_region_id=rid,
                start_at=ts, end_at=ts,
            )
            active[primary_sid] = cur_cluster
        cur_cluster.end_at = ts
        cur_cluster.adjacent_systems.update(adjacents)
        cur_cluster.characters.update(ev["characters"])
        cur_cluster.reporters.add(ev["actor_name"])
        cur_cluster.report_count += 1
        if len(cur_cluster.sample_event_ids) < 10:
            cur_cluster.sample_event_ids.append(eid)
    closed.extend(active.values())

    # Persist.
    written = 0
    for c in closed:
        if c.primary_system_id is None:
            continue
        rep_n = len(c.reporters)
        confidence = (
            "high" if rep_n >= 5 else
            "medium" if rep_n >= 3 else
            "low" if rep_n >= 2 else
            "insufficient"
        )
        # Quality scaling — single reporter / 1 report = noisy, mass
        # cluster with multi-reporter + many characters = strategic.
        if rep_n <= 1 and c.report_count <= 2:
            quality = "noisy"
        elif rep_n <= 2 and c.report_count <= 5:
            quality = "weak"
        elif rep_n >= 5 and len(c.characters) >= 5:
            quality = "strategic"
        elif rep_n >= 3 or len(c.characters) >= 3:
            quality = "strong"
        else:
            quality = "normal"

        evidence = {
            "sample_event_ids": c.sample_event_ids,
            "duration_seconds": int((c.end_at - c.start_at).total_seconds()),
        }
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO operational_hostile_clusters
                  (viewer_bloc_id, start_at, end_at,
                   primary_system_id, primary_system_name, primary_region_id,
                   adjacent_system_ids_json, involved_character_ids_json,
                   involved_character_names_json, reporter_count, report_count,
                   confidence, quality, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    end_at = VALUES(end_at),
                    primary_system_name = VALUES(primary_system_name),
                    primary_region_id = VALUES(primary_region_id),
                    adjacent_system_ids_json = VALUES(adjacent_system_ids_json),
                    involved_character_ids_json = VALUES(involved_character_ids_json),
                    involved_character_names_json = VALUES(involved_character_names_json),
                    reporter_count = VALUES(reporter_count),
                    report_count = VALUES(report_count),
                    confidence = VALUES(confidence),
                    quality = VALUES(quality),
                    evidence_json = VALUES(evidence_json)
                """,
                (
                    viewer_bloc_id, c.start_at, c.end_at,
                    c.primary_system_id, c.primary_system_name, c.primary_region_id,
                    json.dumps(sorted(c.adjacent_systems)),
                    json.dumps(sorted(c.characters.keys())),
                    json.dumps([c.characters[k] for k in sorted(c.characters.keys())]),
                    rep_n, c.report_count,
                    confidence, quality,
                    json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.3A hostile clusters done", {"events": len(events), "clusters_written": written})
    return {"events": len(events), "clusters_written": written}
