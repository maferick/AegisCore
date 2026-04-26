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

# Incident fusion. Signals on the same primary system within
# INCIDENT_GAP_SECONDS roll up into one incident.
INCIDENT_GAP_SECONDS = _env_int("PHASE4_INCIDENT_GAP_SEC", 15 * 60)


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
    dscan_snapshot_ids: set[str] = field(default_factory=set)
    dscan_total_ships: int = 0


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
    # one ordered scan. Plus dscan snapshot id from event row so the
    # cluster can carry has_dscan + total ship counts.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT e.id AS event_id, e.event_timestamp, e.actor_name,
                   e.parsed_json,
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

    # Pre-load dscan snapshot ship counts for any snapshot referenced
    # in the events. Cheap key→count map.
    with conn.cursor() as cur:
        cur.execute(
            "SELECT snapshot_id, COALESCE(ship_count, 0) AS ship_count "
            "FROM eve_log_dscan_snapshots WHERE fetch_status='success'"
        )
        dscan_ships: dict[str, int] = {str(r["snapshot_id"]): int(r["ship_count"]) for r in cur.fetchall()}
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
    # back into one event with sets of ids. Also extract dscan_id from
    # parsed_json once per event.
    events: dict[int, dict] = {}
    for r in rows:
        eid = int(r["event_id"])
        if eid not in events:
            events[eid] = {
                "event_timestamp": r["event_timestamp"],
                "actor_name": r["actor_name"],
                "systems": [],
                "characters": {},
                "dscan_id": None,
            }
            try:
                pj = json.loads(r.get("parsed_json") or "{}")
                if isinstance(pj, dict) and pj.get("dscan_id"):
                    events[eid]["dscan_id"] = str(pj["dscan_id"])
            except (TypeError, ValueError):
                pass
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
        if ev.get("dscan_id"):
            sid = ev["dscan_id"]
            if sid not in cur_cluster.dscan_snapshot_ids:
                cur_cluster.dscan_snapshot_ids.add(sid)
                cur_cluster.dscan_total_ships += dscan_ships.get(sid, 0)
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
        # dscan presence promotes one tier (system+chars+dscan = strong
        # candidate; dscan with many ships = escalation candidate).
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
        if c.dscan_snapshot_ids:
            promotion_ladder = ["noisy", "weak", "normal", "strong", "strategic"]
            cur_idx = promotion_ladder.index(quality)
            # Always promote one tier on dscan presence.
            cur_idx = min(cur_idx + 1, len(promotion_ladder) - 1)
            # Big dscan (≥50 ships) = at least 'strong'; ≥150 ships =
            # strategic.
            if c.dscan_total_ships >= 150:
                cur_idx = max(cur_idx, promotion_ladder.index("strategic"))
            elif c.dscan_total_ships >= 50:
                cur_idx = max(cur_idx, promotion_ladder.index("strong"))
            quality = promotion_ladder[cur_idx]

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
                   confidence, quality, has_dscan, dscan_total_ships,
                   dscan_snapshot_ids_json, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
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
                    has_dscan = VALUES(has_dscan),
                    dscan_total_ships = VALUES(dscan_total_ships),
                    dscan_snapshot_ids_json = VALUES(dscan_snapshot_ids_json),
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
                    1 if c.dscan_snapshot_ids else 0,
                    c.dscan_total_ships if c.dscan_snapshot_ids else None,
                    json.dumps(sorted(c.dscan_snapshot_ids)) if c.dscan_snapshot_ids else None,
                    json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.3A hostile clusters done", {"events": len(events), "clusters_written": written})
    return {"events": len(events), "clusters_written": written}


# =====================================================================
# §4.3B + §4.3C + §4.3E — operational incident fusion + battle linkage
# =====================================================================

@dataclass
class _IncidentAcc:
    primary_system_id: int | None = None
    primary_system_name: str | None = None
    primary_region_id: int | None = None
    start_at: datetime | None = None
    end_at: datetime | None = None
    signal_types: set[str] = field(default_factory=set)
    cluster_ids: list[int] = field(default_factory=list)
    timeline_event_ids: list[int] = field(default_factory=list)
    reporter_count: int = 0
    character_count: int = 0
    hostile_cluster_quality_max: str = "noisy"
    timeline_quality_max: str = "noisy"
    has_dscan: bool = False
    dscan_total_ships: int = 0


# Quality ordering for promotion comparisons.
_QUALITY_ORDER = ["noisy", "weak", "normal", "strong", "strategic"]


def _max_quality(a: str, b: str) -> str:
    return a if _QUALITY_ORDER.index(a) >= _QUALITY_ORDER.index(b) else b


def run_incidents(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
) -> dict:
    """Fuse hostile clusters + timeline events on the same primary
    system within INCIDENT_GAP_SECONDS into a single incident row.

    Also links each incident to a battle_theaters row when the system
    + time window overlaps a known theater (Phase 4.3C)."""
    log.info("phase4.3B incidents starting", {"viewer_bloc_id": viewer_bloc_id, "since": since_dt.isoformat()})

    # Pull hostile clusters in window.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, primary_system_id, primary_system_name, primary_region_id,
                   start_at, end_at, reporter_count, report_count,
                   confidence, quality, involved_character_ids_json,
                   has_dscan, dscan_total_ships
              FROM operational_hostile_clusters
             WHERE viewer_bloc_id = %s
               AND start_at >= %s
             ORDER BY start_at
            """,
            (viewer_bloc_id, since_dt),
        )
        hostile_rows = list(cur.fetchall())

    # Pull timeline events in window with system_id where present.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, timeline_type, event_timestamp, event_window_start,
                   event_window_end, source_listener, solar_system_name,
                   solar_system_id, region_id, confidence, quality
              FROM operational_timeline_events
             WHERE viewer_bloc_id = %s
               AND event_timestamp >= %s
               AND timeline_type != 'hostile_report'
             ORDER BY event_timestamp
            """,
            (viewer_bloc_id, since_dt),
        )
        timeline_rows = list(cur.fetchall())

    # Merge into one chronological event stream of (timestamp,
    # primary_system_id, source_kind, payload). Hostile clusters use
    # start_at / primary_system_id directly. Timeline events without a
    # solar_system_id won't fuse cleanly — track them under
    # source_listener as their pseudo-system. Fleet form-ups are the
    # main case here; gamelog crash_symptom etc. similarly.
    chronological: list[tuple[datetime, int | None, str, dict]] = []
    for h in hostile_rows:
        chronological.append((h["start_at"], int(h["primary_system_id"]) if h["primary_system_id"] else None,
                              "hostile_cluster", h))
    for t in timeline_rows:
        sid = int(t["solar_system_id"]) if t["solar_system_id"] else None
        chronological.append((t["event_timestamp"], sid, "timeline", t))
    chronological.sort(key=lambda x: x[0])

    # Group: per primary_system, an active incident extends while gap
    # < INCIDENT_GAP_SECONDS. Timeline events without system fall into
    # a per-listener pseudo-bucket (None bucket).
    active: dict[int | None, _IncidentAcc] = {}
    closed: list[_IncidentAcc] = []
    for ts, sid, kind, payload in chronological:
        cur_inc = active.get(sid)
        if cur_inc is None or (ts - cur_inc.end_at).total_seconds() > INCIDENT_GAP_SECONDS:
            if cur_inc is not None:
                closed.append(cur_inc)
            cur_inc = _IncidentAcc(
                primary_system_id=sid,
                start_at=ts, end_at=ts,
            )
            active[sid] = cur_inc
        cur_inc.end_at = ts
        if kind == "hostile_cluster":
            cur_inc.signal_types.add("hostile_cluster")
            cur_inc.cluster_ids.append(int(payload["id"]))
            cur_inc.reporter_count = max(cur_inc.reporter_count, int(payload["reporter_count"]))
            try:
                names = json.loads(payload.get("involved_character_ids_json") or "[]")
                cur_inc.character_count = max(cur_inc.character_count, len(names))
            except (TypeError, ValueError):
                pass
            cur_inc.hostile_cluster_quality_max = _max_quality(
                cur_inc.hostile_cluster_quality_max, str(payload["quality"])
            )
            if int(payload.get("has_dscan") or 0) == 1:
                cur_inc.has_dscan = True
            ships = payload.get("dscan_total_ships")
            if ships is not None:
                cur_inc.dscan_total_ships = max(cur_inc.dscan_total_ships, int(ships))
            if cur_inc.primary_system_name is None:
                cur_inc.primary_system_name = payload["primary_system_name"]
                cur_inc.primary_region_id = (
                    int(payload["primary_region_id"]) if payload["primary_region_id"] else None
                )
        else:  # timeline
            cur_inc.signal_types.add(str(payload["timeline_type"]))
            cur_inc.timeline_event_ids.append(int(payload["id"]))
            cur_inc.timeline_quality_max = _max_quality(
                cur_inc.timeline_quality_max, str(payload["quality"])
            )
            if cur_inc.primary_system_name is None and payload["solar_system_name"]:
                cur_inc.primary_system_name = payload["solar_system_name"]
                cur_inc.primary_region_id = (
                    int(payload["region_id"]) if payload["region_id"] else None
                )
    closed.extend(active.values())

    # Persist + link to battles in one pass.
    written = 0
    linked = 0
    for inc in closed:
        if not inc.signal_types:
            continue
        incident_type = _classify_incident_type(inc.signal_types)
        severity = _classify_severity(inc)
        confidence = _classify_confidence(inc)
        battle_id, theater_id = _link_battle(conn, inc) if inc.primary_system_id else (None, None)
        if battle_id is not None:
            linked += 1
        evidence = {
            "signal_types": sorted(inc.signal_types),
            "hostile_cluster_ids": inc.cluster_ids,
            "timeline_event_ids": inc.timeline_event_ids,
            "max_hostile_cluster_quality": inc.hostile_cluster_quality_max,
            "max_timeline_quality": inc.timeline_quality_max,
        }
        summary = _build_summary(inc, incident_type, severity)
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO operational_incidents
                  (viewer_bloc_id, incident_type, start_at, end_at,
                   primary_system_id, primary_system_name, primary_region_id,
                   battle_id, theater_id, severity, has_dscan, dscan_total_ships,
                   confidence, participant_estimate, signal_types_json,
                   hostile_cluster_ids_json, timeline_event_ids_json,
                   timeline_summary, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    end_at = VALUES(end_at),
                    incident_type = VALUES(incident_type),
                    primary_system_name = VALUES(primary_system_name),
                    primary_region_id = VALUES(primary_region_id),
                    battle_id = VALUES(battle_id),
                    theater_id = VALUES(theater_id),
                    severity = VALUES(severity),
                    has_dscan = VALUES(has_dscan),
                    dscan_total_ships = VALUES(dscan_total_ships),
                    confidence = VALUES(confidence),
                    participant_estimate = VALUES(participant_estimate),
                    signal_types_json = VALUES(signal_types_json),
                    hostile_cluster_ids_json = VALUES(hostile_cluster_ids_json),
                    timeline_event_ids_json = VALUES(timeline_event_ids_json),
                    timeline_summary = VALUES(timeline_summary),
                    evidence_json = VALUES(evidence_json),
                    updated_at = NOW()
                """,
                (
                    viewer_bloc_id, incident_type, inc.start_at, inc.end_at,
                    inc.primary_system_id, inc.primary_system_name, inc.primary_region_id,
                    battle_id, theater_id, severity,
                    1 if inc.has_dscan else 0,
                    inc.dscan_total_ships if inc.has_dscan else None,
                    confidence,
                    inc.character_count or None,
                    json.dumps(sorted(inc.signal_types)),
                    json.dumps(inc.cluster_ids),
                    json.dumps(inc.timeline_event_ids),
                    summary,
                    json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.3B incidents done", {"incidents_written": written, "battle_linked": linked})
    return {"incidents_written": written, "battle_linked": linked}


def _classify_incident_type(signal_types: set[str]) -> str:
    """Map signal-type set → incident_type enum."""
    s = signal_types
    if "fleet_formup" in s and ("hostile_cluster" in s or "combat_spike" in s or "escalation" in s):
        return "fleet_op"
    if "escalation" in s:
        return "engagement"
    if "hostile_cluster" in s and ("combat_spike" in s or "disengagement" in s):
        return "engagement"
    if "hostile_cluster" in s:
        return "hostile_contact"
    if "combat_spike" in s:
        return "combat"
    if "disengagement" in s:
        return "disengagement"
    if "fleet_formup" in s:
        return "fleet_op"
    if s == {"crash_symptom"} or s == {"unknown"}:
        return "telemetry_gap"
    return "mixed"


def _classify_severity(inc: _IncidentAcc) -> str:
    """Phase 4.3E severity tier. Higher tier wins.

    Phase 4.4 dscan integration: a cluster with strong cluster
    quality alone bumps to 'strategic' (was: required 3+ signals).
    A dscan with ≥150 ships is an escalation candidate even with a
    single cluster signal."""
    s = inc.signal_types
    has_hostile = "hostile_cluster" in s
    has_combat = "combat_spike" in s or "escalation" in s
    has_disengage = "disengagement" in s
    n_signals = len(s)

    if inc.reporter_count >= 10 and inc.character_count >= 10:
        return "coalition_level"
    if has_hostile and has_combat and has_disengage:
        return "escalation"
    if inc.has_dscan and inc.dscan_total_ships >= 150 and has_hostile:
        return "escalation"
    if inc.hostile_cluster_quality_max == "strategic":
        return "strategic"
    if n_signals >= 3 and inc.hostile_cluster_quality_max in ("strong", "strategic"):
        return "strategic"
    if inc.hostile_cluster_quality_max == "strong":
        return "tactical"
    if n_signals >= 2:
        return "tactical"
    return "noise"


def _classify_confidence(inc: _IncidentAcc) -> str:
    if inc.reporter_count >= 5 or len(inc.cluster_ids) >= 3:
        return "high"
    if inc.reporter_count >= 3 or len(inc.cluster_ids) >= 2:
        return "medium"
    if inc.reporter_count >= 2 or len(inc.signal_types) >= 2:
        return "low"
    return "insufficient"


def _build_summary(inc: _IncidentAcc, incident_type: str, severity: str) -> str:
    sys_part = inc.primary_system_name or "unknown system"
    duration = max(1, int((inc.end_at - inc.start_at).total_seconds()) // 60)
    parts = sorted(inc.signal_types)
    return f"{incident_type.replace('_', ' ')} in {sys_part}, {duration}m, signals: {', '.join(parts)}, severity={severity}, reporters={inc.reporter_count}, named={inc.character_count}"[:500]


def run_system_activity(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
) -> dict:
    """Phase 4.3D — daily per-system rollup. Combines hostile clusters,
    timeline events, and incidents into one row per (system, day).

    Reliability-weighted reports: each hostile_report contribution is
    weighted by the reporter's intel_reliability_profiles.reliability_score
    (default 0.25 when missing). Strong-reliability reporters dominate
    the heatmap, low-reliability noise gets damped.
    """
    log.info("phase4.3D system activity starting", {"viewer_bloc_id": viewer_bloc_id, "since": since_dt.isoformat()})

    # Load reliability scores in one shot.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT character_name, MAX(reliability_score) AS score
              FROM intel_reliability_profiles
             WHERE viewer_bloc_id = %s
             GROUP BY character_name
            """,
            (viewer_bloc_id,),
        )
        rel_score = {r["character_name"]: float(r["score"] or 0.25) for r in cur.fetchall()}

    # Per-day per-system buckets.
    per_day: dict[tuple[int, str], dict] = defaultdict(lambda: {
        "name": None, "region_id": None,
        "hostile_report": 0, "hostile_cluster": 0,
        "escalation": 0, "combat_spike": 0,
        "fleet_formup": 0, "disengagement": 0,
        "self_destruct_wave": 0,
        "incident_count": 0, "incident_max_sev": None,
        "reporters": set(),
        "weighted": 0.0,
    })

    # Hostile clusters → counts + reporter set + weighted reports.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT primary_system_id, primary_system_name, primary_region_id,
                   DATE(start_at) AS d, reporter_count, report_count,
                   evidence_json
              FROM operational_hostile_clusters
             WHERE viewer_bloc_id = %s AND start_at >= %s
            """,
            (viewer_bloc_id, since_dt),
        )
        for r in cur.fetchall():
            sid = r["primary_system_id"]
            if sid is None:
                continue
            sid = int(sid)
            day = r["d"]
            key = (sid, day.isoformat())
            b = per_day[key]
            b["name"] = r["primary_system_name"]
            b["region_id"] = int(r["primary_region_id"]) if r["primary_region_id"] else None
            b["hostile_cluster"] += 1
            b["hostile_report"] += int(r["report_count"])
            # Weighted reports: assume average 0.25 reliability for
            # cluster (per-event weighting needs join to reporters,
            # heavy; this approximation is fine for the heatmap).
            b["weighted"] += float(r["report_count"]) * 0.25

    # Reliability-weighted reports — re-pass on raw intel events to
    # accumulate per-reporter scores. Bound to systems we already
    # have buckets for.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT e.actor_name, sys.resolved_entity_id AS sys_id,
                   DATE(e.event_timestamp) AS d
              FROM eve_log_events e
              JOIN eve_log_entity_resolutions sys
                ON sys.eve_log_event_id = e.id
               AND sys.resolved_entity_type = 'system'
             WHERE e.event_type = 'intel_report'
               AND e.event_timestamp >= %s
               AND e.actor_name IS NOT NULL
            """,
            (since_dt,),
        )
        for r in cur.fetchall():
            sid = int(r["sys_id"])
            day = r["d"].isoformat()
            key = (sid, day)
            b = per_day.get(key)
            if b is None:
                continue
            b["reporters"].add(r["actor_name"])
            # Override the cluster-derived weight with per-reporter
            # reliability. We add (score - 0.25 default) to avoid
            # double-counting; net effect is each reporter contributes
            # their actual reliability.
            b["weighted"] += rel_score.get(r["actor_name"], 0.25) - 0.25

    # Timeline events → per-type counts. Skip hostile_report (counted
    # via clusters above).
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT solar_system_id, region_id, DATE(event_timestamp) AS d,
                   timeline_type
              FROM operational_timeline_events
             WHERE viewer_bloc_id = %s
               AND event_timestamp >= %s
               AND solar_system_id IS NOT NULL
               AND timeline_type != 'hostile_report'
            """,
            (viewer_bloc_id, since_dt),
        )
        for r in cur.fetchall():
            sid = int(r["solar_system_id"])
            day = r["d"].isoformat()
            key = (sid, day)
            b = per_day[key]
            if b["region_id"] is None and r["region_id"] is not None:
                b["region_id"] = int(r["region_id"])
            tt = str(r["timeline_type"])
            if tt in b:
                b[tt] += 1

    # Incidents → max severity + count.
    severity_order = ["noise", "tactical", "strategic", "escalation", "coalition_level"]
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT primary_system_id, DATE(start_at) AS d, severity
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND start_at >= %s
               AND primary_system_id IS NOT NULL
            """,
            (viewer_bloc_id, since_dt),
        )
        for r in cur.fetchall():
            sid = int(r["primary_system_id"])
            day = r["d"].isoformat()
            key = (sid, day)
            b = per_day[key]
            b["incident_count"] += 1
            sev = str(r["severity"])
            cur_max = b["incident_max_sev"]
            if cur_max is None or severity_order.index(sev) > severity_order.index(cur_max):
                b["incident_max_sev"] = sev

    # Persist.
    written = 0
    for (sid, day_iso), b in per_day.items():
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO system_operational_activity
                  (viewer_bloc_id, solar_system_id, solar_system_name, region_id,
                   activity_date,
                   hostile_report_count, hostile_cluster_count, escalation_count,
                   combat_spike_count, fleet_formup_count, disengagement_count,
                   self_destruct_wave_count, incident_count, incident_max_severity,
                   distinct_reporters, reliability_weighted_reports)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    solar_system_name = VALUES(solar_system_name),
                    region_id = VALUES(region_id),
                    hostile_report_count = VALUES(hostile_report_count),
                    hostile_cluster_count = VALUES(hostile_cluster_count),
                    escalation_count = VALUES(escalation_count),
                    combat_spike_count = VALUES(combat_spike_count),
                    fleet_formup_count = VALUES(fleet_formup_count),
                    disengagement_count = VALUES(disengagement_count),
                    self_destruct_wave_count = VALUES(self_destruct_wave_count),
                    incident_count = VALUES(incident_count),
                    incident_max_severity = VALUES(incident_max_severity),
                    distinct_reporters = VALUES(distinct_reporters),
                    reliability_weighted_reports = VALUES(reliability_weighted_reports),
                    computed_at = NOW()
                """,
                (
                    viewer_bloc_id, sid, b["name"], b["region_id"],
                    day_iso,
                    b["hostile_report"], b["hostile_cluster"], b["escalation"],
                    b["combat_spike"], b["fleet_formup"], b["disengagement"],
                    b["self_destruct_wave"], b["incident_count"], b["incident_max_sev"],
                    len(b["reporters"]), round(max(0.0, b["weighted"]), 3),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.3D system activity done", {"rows_written": written})
    return {"rows_written": written}


def run_corridors(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
) -> dict:
    """Phase 4.4C — infer hostile travel lanes from cluster sequences.

    For each (system_a, system_b) pair where a hostile cluster in
    system_a is followed by a cluster in system_b within
    CORRIDOR_TRANSITION_GAP_SECONDS AND the two clusters share at
    least one named character, increment the corridor's
    transition_count + record the average transit time.

    No EVE topology join — corridors are inferred from observed
    traffic, not declared adjacency. This catches Ansiblex / wormhole
    traffic the topology graph wouldn't show."""
    transition_gap = _env_int("PHASE4_CORRIDOR_TRANSITION_GAP_SEC", 30 * 60)
    log.info("phase4.4C corridors starting", {"viewer_bloc_id": viewer_bloc_id, "since": since_dt.isoformat()})

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, primary_system_id, primary_system_name, primary_region_id,
                   start_at, end_at, involved_character_ids_json
              FROM operational_hostile_clusters
             WHERE viewer_bloc_id = %s
               AND start_at >= %s
             ORDER BY start_at
            """,
            (viewer_bloc_id, since_dt),
        )
        clusters = list(cur.fetchall())
    if not clusters:
        return {"clusters": 0, "corridors_written": 0}

    # Decode character sets per cluster.
    for c in clusters:
        try:
            c["chars"] = set(int(x) for x in (json.loads(c.get("involved_character_ids_json") or "[]") or []))
        except (TypeError, ValueError):
            c["chars"] = set()

    # Pairwise sweep: for each cluster, look ahead for clusters in
    # different systems whose start is within transition_gap seconds.
    corridor_acc: dict[tuple[int, int], dict] = defaultdict(lambda: {
        "count": 0,
        "characters": set(),
        "transit_seconds": [],
        "from_name": None, "from_region": None,
        "to_name": None, "to_region": None,
        "first": None, "last": None,
    })

    n = len(clusters)
    for i, c_from in enumerate(clusters):
        if c_from["primary_system_id"] is None:
            continue
        from_sid = int(c_from["primary_system_id"])
        from_chars = c_from["chars"]
        if not from_chars:
            continue
        # Look ahead until we exceed the gap.
        end_ts = c_from["end_at"]
        for j in range(i + 1, n):
            c_to = clusters[j]
            if c_to["primary_system_id"] is None:
                continue
            to_sid = int(c_to["primary_system_id"])
            if to_sid == from_sid:
                continue
            gap = (c_to["start_at"] - end_ts).total_seconds()
            if gap < 0:
                continue
            if gap > transition_gap:
                break
            shared = from_chars & c_to["chars"]
            if not shared:
                continue
            key = (from_sid, to_sid)
            acc = corridor_acc[key]
            acc["count"] += 1
            acc["characters"].update(shared)
            acc["transit_seconds"].append(int(gap))
            acc["from_name"] = c_from["primary_system_name"]
            acc["from_region"] = int(c_from["primary_region_id"]) if c_from["primary_region_id"] else None
            acc["to_name"] = c_to["primary_system_name"]
            acc["to_region"] = int(c_to["primary_region_id"]) if c_to["primary_region_id"] else None
            if acc["first"] is None or c_from["start_at"] < acc["first"]:
                acc["first"] = c_from["start_at"]
            if acc["last"] is None or c_to["start_at"] > acc["last"]:
                acc["last"] = c_to["start_at"]

    written = 0
    for (from_sid, to_sid), acc in corridor_acc.items():
        n_chars = len(acc["characters"])
        avg_transit = (
            int(sum(acc["transit_seconds"]) / len(acc["transit_seconds"]))
            if acc["transit_seconds"] else None
        )
        confidence = (
            "high" if acc["count"] >= 10 and n_chars >= 5 else
            "medium" if acc["count"] >= 4 and n_chars >= 3 else
            "low" if acc["count"] >= 2 else
            "insufficient"
        )
        evidence = {
            "transition_count": acc["count"],
            "distinct_characters": n_chars,
            "transit_seconds_samples": acc["transit_seconds"][:10],
        }
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO operational_corridors
                  (viewer_bloc_id, from_system_id, to_system_id,
                   from_system_name, to_system_name,
                   from_region_id, to_region_id,
                   transition_count, distinct_characters, avg_transition_seconds,
                   first_seen_at, last_seen_at, confidence, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    from_system_name = VALUES(from_system_name),
                    to_system_name = VALUES(to_system_name),
                    from_region_id = VALUES(from_region_id),
                    to_region_id = VALUES(to_region_id),
                    transition_count = VALUES(transition_count),
                    distinct_characters = VALUES(distinct_characters),
                    avg_transition_seconds = VALUES(avg_transition_seconds),
                    first_seen_at = VALUES(first_seen_at),
                    last_seen_at = VALUES(last_seen_at),
                    confidence = VALUES(confidence),
                    evidence_json = VALUES(evidence_json),
                    computed_at = NOW()
                """,
                (
                    viewer_bloc_id, from_sid, to_sid,
                    acc["from_name"], acc["to_name"],
                    acc["from_region"], acc["to_region"],
                    acc["count"], n_chars, avg_transit,
                    acc["first"], acc["last"], confidence,
                    json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.4C corridors done", {"clusters": len(clusters), "corridors_written": written})
    return {"clusters": len(clusters), "corridors_written": written}


def run_response_times(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    """Phase 4.4E — operational tempo per system per window.

    intel_to_combat: time from first hostile_report (intel) to next
        combat_spike OR escalation in same primary_system within 30min.
    formup_to_engage: time from fleet_formup to next combat_spike or
        escalation in same primary_system within 30min.
    engage_to_disengage: time from combat_spike or escalation to
        disengagement in same primary_system within 30min.

    Median rather than mean (resilient to single outlier responses).
    """
    import statistics as _stats
    window_start = datetime.combine(window_end - timedelta(days=window_days - 1), datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)
    log.info("phase4.4E response-times starting", {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    # Pull events with system_id (skip rows where system unknown).
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT timeline_type, event_timestamp, solar_system_id, region_id, solar_system_name
              FROM operational_timeline_events
             WHERE viewer_bloc_id = %s
               AND event_timestamp BETWEEN %s AND %s
               AND solar_system_id IS NOT NULL
            """,
            (viewer_bloc_id, window_start, window_end_dt),
        )
        timeline_rows = list(cur.fetchall())

    # Hostile clusters as the "intel" anchor (more accurate than raw
    # hostile_report which fires once per intel event).
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT primary_system_id, primary_system_name, primary_region_id, start_at
              FROM operational_hostile_clusters
             WHERE viewer_bloc_id = %s
               AND start_at BETWEEN %s AND %s
               AND primary_system_id IS NOT NULL
            """,
            (viewer_bloc_id, window_start, window_end_dt),
        )
        cluster_rows = list(cur.fetchall())

    by_system: dict[int, dict] = defaultdict(lambda: {
        "intel": [], "formup": [], "engage": [], "disengage": [],
        "name": None, "region": None,
    })
    for c in cluster_rows:
        sid = int(c["primary_system_id"])
        b = by_system[sid]
        b["intel"].append(c["start_at"])
        b["name"] = c["primary_system_name"]
        if c["primary_region_id"] is not None:
            b["region"] = int(c["primary_region_id"])
    for t in timeline_rows:
        sid = int(t["solar_system_id"])
        b = by_system[sid]
        if b["name"] is None and t["solar_system_name"]:
            b["name"] = t["solar_system_name"]
        if b["region"] is None and t["region_id"] is not None:
            b["region"] = int(t["region_id"])
        tt = t["timeline_type"]
        ts = t["event_timestamp"]
        if tt == "fleet_formup":
            b["formup"].append(ts)
        elif tt in ("combat_spike", "escalation"):
            b["engage"].append(ts)
        elif tt == "disengagement":
            b["disengage"].append(ts)

    written = 0
    for sid, b in by_system.items():
        i2c = _pair_medians(b["intel"], b["engage"])
        f2e = _pair_medians(b["formup"], b["engage"])
        e2d = _pair_medians(b["engage"], b["disengage"])
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO system_response_times
                  (viewer_bloc_id, solar_system_id, solar_system_name, region_id,
                   window_end_date, window_days,
                   intel_to_combat_count, intel_to_combat_median_seconds,
                   formup_to_engage_count, formup_to_engage_median_seconds,
                   engage_to_disengage_count, engage_to_disengage_median_seconds)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    solar_system_name = VALUES(solar_system_name),
                    region_id = VALUES(region_id),
                    intel_to_combat_count = VALUES(intel_to_combat_count),
                    intel_to_combat_median_seconds = VALUES(intel_to_combat_median_seconds),
                    formup_to_engage_count = VALUES(formup_to_engage_count),
                    formup_to_engage_median_seconds = VALUES(formup_to_engage_median_seconds),
                    engage_to_disengage_count = VALUES(engage_to_disengage_count),
                    engage_to_disengage_median_seconds = VALUES(engage_to_disengage_median_seconds),
                    computed_at = NOW()
                """,
                (
                    viewer_bloc_id, sid, b["name"], b["region"],
                    window_end, window_days,
                    i2c[0], i2c[1], f2e[0], f2e[1], e2d[0], e2d[1],
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.4E response-times done", {"systems": written})
    return {"systems": written}


def _pair_medians(starts: list[datetime], ends: list[datetime]) -> tuple[int, int | None]:
    """For each start, find the next end within 30min; collect the
    deltas in seconds; return (count, median or None)."""
    import statistics as _stats
    if not starts or not ends:
        return (0, None)
    ends_sorted = sorted(ends)
    deltas: list[int] = []
    for s in starts:
        for e in ends_sorted:
            d = (e - s).total_seconds()
            if d < 0:
                continue
            if d > 30 * 60:
                break
            deltas.append(int(d))
            break
    if not deltas:
        return (0, None)
    return (len(deltas), int(_stats.median(deltas)))


def run_threat_surface(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    """Phase 4.4F — composite per-system threat score.

    Inputs (all rolled across `window_days` ending at `window_end`):
      hostile_cluster_score   = sum of cluster quality weights
                                (noisy=0.25, weak=0.5, normal=1,
                                strong=2, strategic=4)
      escalation_score        = count of escalation timelines × 2
      battle_linkage_score    = count of incidents linked to a battle
      density_score           = total operational signal count
      reliability_score       = sum of reliability_weighted_reports
                                from system_operational_activity
      corridor_centrality_score = transit_count of corridors that
                                  start OR end at this system

    Composite threat_score = weighted sum, normalised so the top
    system in the window scores ~10. Tier breakdown:
      strategic   threat_score >= 7
      hot         threat_score >= 4
      contested   threat_score >= 2
      watch       threat_score >= 0.5
      safe        below
    """
    window_start = datetime.combine(window_end - timedelta(days=window_days - 1), datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)
    log.info("phase4.4F threat-surface starting", {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    quality_weights = {"noisy": 0.25, "weak": 0.5, "normal": 1.0, "strong": 2.0, "strategic": 4.0}

    by_system: dict[int, dict] = defaultdict(lambda: {
        "name": None, "region": None,
        "cluster_score": 0.0, "escalation_score": 0.0,
        "battle_linkage_score": 0.0, "density_score": 0.0,
        "reliability_score": 0.0, "corridor_score": 0.0,
        "dscan_score": 0.0,
        "capital_score": 0.0, "logistics_score": 0.0,
        "doctrine_threat_score": 0.0, "escalation_propensity_score": 0.0,
        "mobility_votes": defaultdict(int),
    })

    # Hostile cluster contributions.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT primary_system_id, primary_system_name, primary_region_id,
                   quality, COUNT(*) AS n,
                   SUM(has_dscan) AS dscan_clusters,
                   SUM(COALESCE(dscan_total_ships, 0)) AS dscan_ships
              FROM operational_hostile_clusters
             WHERE viewer_bloc_id = %s
               AND start_at BETWEEN %s AND %s
               AND primary_system_id IS NOT NULL
             GROUP BY primary_system_id, primary_system_name, primary_region_id, quality
            """,
            (viewer_bloc_id, window_start, window_end_dt),
        )
        for r in cur.fetchall():
            sid = int(r["primary_system_id"])
            b = by_system[sid]
            b["name"] = r["primary_system_name"]
            b["region"] = int(r["primary_region_id"]) if r["primary_region_id"] else None
            w = quality_weights.get(str(r["quality"]), 1.0)
            b["cluster_score"] += w * int(r["n"])
            b["density_score"] += int(r["n"])
            # dscan score: log-ish ramp on total ships seen so a 200-
            # ship dscan dominates over fifty 5-ship snapshots.
            ds_ships = int(r.get("dscan_ships") or 0)
            ds_clusters = int(r.get("dscan_clusters") or 0)
            if ds_ships > 0:
                b["dscan_score"] += min(ds_ships / 25.0, 50.0) + ds_clusters * 0.5

    # Escalation + density timeline contributions.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT solar_system_id, region_id, solar_system_name, timeline_type, COUNT(*) AS n
              FROM operational_timeline_events
             WHERE viewer_bloc_id = %s
               AND event_timestamp BETWEEN %s AND %s
               AND solar_system_id IS NOT NULL
             GROUP BY solar_system_id, region_id, solar_system_name, timeline_type
            """,
            (viewer_bloc_id, window_start, window_end_dt),
        )
        for r in cur.fetchall():
            sid = int(r["solar_system_id"])
            b = by_system[sid]
            if b["name"] is None and r["solar_system_name"]:
                b["name"] = r["solar_system_name"]
            if b["region"] is None and r["region_id"] is not None:
                b["region"] = int(r["region_id"])
            n = int(r["n"])
            b["density_score"] += n
            if r["timeline_type"] == "escalation":
                b["escalation_score"] += n * 2.0

    # Battle linkage from operational_incidents.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT primary_system_id, COUNT(*) AS n
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND start_at BETWEEN %s AND %s
               AND primary_system_id IS NOT NULL
               AND battle_id IS NOT NULL
             GROUP BY primary_system_id
            """,
            (viewer_bloc_id, window_start, window_end_dt),
        )
        for r in cur.fetchall():
            sid = int(r["primary_system_id"])
            by_system[sid]["battle_linkage_score"] += float(r["n"])

    # Reliability-weighted reports from system_operational_activity.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT solar_system_id, SUM(reliability_weighted_reports) AS rwr
              FROM system_operational_activity
             WHERE viewer_bloc_id = %s
               AND activity_date BETWEEN %s AND %s
             GROUP BY solar_system_id
            """,
            (viewer_bloc_id, window_start.date(), window_end),
        )
        for r in cur.fetchall():
            sid = int(r["solar_system_id"])
            by_system[sid]["reliability_score"] += float(r["rwr"] or 0)

    # Force-composition contributions: capital + super, logistics
    # density, doctrine recognition, mobility profile votes.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT c.primary_system_id AS sid,
                   SUM(f.estimated_capital_count) AS caps,
                   SUM(f.estimated_super_count) AS supers,
                   SUM(f.estimated_logistics_count) AS logi,
                   SUM(f.estimated_tackle_count) AS tackle,
                   SUM(f.ship_total) AS ships,
                   SUM(CASE WHEN f.primary_doctrine_id IS NOT NULL THEN 1 ELSE 0 END) AS doctrine_hits,
                   SUM(CASE WHEN f.estimated_capital_count > 0 OR f.estimated_super_count > 0 THEN 1 ELSE 0 END) AS escalation_hits
              FROM operational_force_compositions f
              JOIN operational_hostile_clusters c ON c.id = f.cluster_id
             WHERE f.viewer_bloc_id = %s
               AND f.snapshot_at BETWEEN %s AND %s
               AND c.primary_system_id IS NOT NULL
             GROUP BY c.primary_system_id
            """,
            (viewer_bloc_id, window_start, window_end_dt),
        )
        for r in cur.fetchall():
            sid = int(r["sid"])
            b = by_system[sid]
            b["capital_score"] += float(r.get("caps") or 0) * 1.0 + float(r.get("supers") or 0) * 4.0
            b["logistics_score"] += float(r.get("logi") or 0) * 0.5
            b["doctrine_threat_score"] += float(r.get("doctrine_hits") or 0) * 1.0
            b["escalation_propensity_score"] += float(r.get("escalation_hits") or 0) * 2.0
    # Mobility votes — pull per-composition mobility values to roll up.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT c.primary_system_id AS sid, f.mobility, COUNT(*) AS n
              FROM operational_force_compositions f
              JOIN operational_hostile_clusters c ON c.id = f.cluster_id
             WHERE f.viewer_bloc_id = %s
               AND f.snapshot_at BETWEEN %s AND %s
               AND c.primary_system_id IS NOT NULL
             GROUP BY c.primary_system_id, f.mobility
            """,
            (viewer_bloc_id, window_start, window_end_dt),
        )
        for r in cur.fetchall():
            sid = int(r["sid"])
            by_system[sid]["mobility_votes"][str(r["mobility"])] += int(r["n"])

    # Corridor centrality.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT from_system_id AS sid, SUM(transition_count) AS n FROM operational_corridors
             WHERE viewer_bloc_id = %s GROUP BY from_system_id
            UNION ALL
            SELECT to_system_id AS sid, SUM(transition_count) AS n FROM operational_corridors
             WHERE viewer_bloc_id = %s GROUP BY to_system_id
            """,
            (viewer_bloc_id, viewer_bloc_id),
        )
        for r in cur.fetchall():
            sid = int(r["sid"])
            by_system[sid]["corridor_score"] += float(r["n"] or 0)

    if not by_system:
        log.info("phase4.4F threat-surface no data", {})
        return {"systems": 0}

    # Composite weighted score. Normalise on the top observed value
    # so the highest system lands near ~10 — this gives the tier
    # thresholds operational meaning across blocs of different size.
    weights = {
        "cluster_score": 1.0,
        "escalation_score": 1.5,
        "battle_linkage_score": 1.0,
        "density_score": 0.05,
        "reliability_score": 0.05,
        "corridor_score": 0.05,
        "dscan_score": 0.4,
        "capital_score": 1.5,
        "logistics_score": 0.3,
        "doctrine_threat_score": 0.5,
        "escalation_propensity_score": 1.0,
    }
    raw_scores = []
    for sid, b in by_system.items():
        s = sum(b[k] * w for k, w in weights.items())
        b["raw"] = s
        raw_scores.append(s)
    max_raw = max(raw_scores) if raw_scores else 1.0
    scale = 10.0 / max(max_raw, 1.0)

    written = 0
    for sid, b in by_system.items():
        threat = round(b["raw"] * scale, 4)
        tier = (
            "strategic" if threat >= 7 else
            "hot" if threat >= 4 else
            "contested" if threat >= 2 else
            "watch" if threat >= 0.5 else
            "safe"
        )
        # Mobility profile: highest-vote bucket, or NULL when nothing.
        mob_votes = b["mobility_votes"]
        mobility_profile = (
            max(mob_votes.items(), key=lambda x: x[1])[0]
            if mob_votes else None
        )
        evidence = {
            "raw": round(b["raw"], 3),
            "scale": round(scale, 4),
            "components": {k: round(b[k], 3) for k in weights.keys()},
            "mobility_votes": dict(mob_votes),
        }
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO system_threat_surface
                  (viewer_bloc_id, solar_system_id, solar_system_name, region_id,
                   window_end_date, window_days, threat_score,
                   hostile_cluster_score, escalation_score, battle_linkage_score,
                   density_score, reliability_score, corridor_centrality_score,
                   dscan_score, capital_score, logistics_score,
                   doctrine_threat_score, escalation_propensity_score,
                   mobility_profile, tier, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    solar_system_name = VALUES(solar_system_name),
                    region_id = VALUES(region_id),
                    threat_score = VALUES(threat_score),
                    hostile_cluster_score = VALUES(hostile_cluster_score),
                    escalation_score = VALUES(escalation_score),
                    battle_linkage_score = VALUES(battle_linkage_score),
                    density_score = VALUES(density_score),
                    reliability_score = VALUES(reliability_score),
                    corridor_centrality_score = VALUES(corridor_centrality_score),
                    dscan_score = VALUES(dscan_score),
                    capital_score = VALUES(capital_score),
                    logistics_score = VALUES(logistics_score),
                    doctrine_threat_score = VALUES(doctrine_threat_score),
                    escalation_propensity_score = VALUES(escalation_propensity_score),
                    mobility_profile = VALUES(mobility_profile),
                    tier = VALUES(tier),
                    evidence_json = VALUES(evidence_json),
                    computed_at = NOW()
                """,
                (
                    viewer_bloc_id, sid, b["name"], b["region"],
                    window_end, window_days, threat,
                    round(b["cluster_score"], 4),
                    round(b["escalation_score"], 4),
                    round(b["battle_linkage_score"], 4),
                    round(b["density_score"], 4),
                    round(b["reliability_score"], 4),
                    round(b["corridor_score"], 4),
                    round(b["dscan_score"], 4),
                    round(b["capital_score"], 4),
                    round(b["logistics_score"], 4),
                    round(b["doctrine_threat_score"], 4),
                    round(b["escalation_propensity_score"], 4),
                    mobility_profile,
                    tier,
                    json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.4F threat-surface done", {"systems": written})
    return {"systems": written}


def _link_battle(
    conn: pymysql.connections.Connection,
    inc: _IncidentAcc,
) -> tuple[int | None, int | None]:
    """Find a battle_theater that overlaps this incident's primary
    system + time window. Returns (battle_id, theater_id) — for now
    they're the same value since battle_theaters.id IS the battle id
    in this codebase. Confidence-weighted: only link when the theater
    actually overlaps in time (theater.end_time within ± 15min of
    incident window)."""
    if inc.primary_system_id is None:
        return (None, None)
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT bt.id
              FROM battle_theaters bt
              JOIN battle_theater_systems bts ON bts.theater_id = bt.id
             WHERE bts.solar_system_id = %s
               AND bt.end_time >= %s
               AND bt.start_time <= %s
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, bt.start_time, %s)) ASC
             LIMIT 1
            """,
            (
                inc.primary_system_id,
                inc.start_at - timedelta(minutes=15),
                inc.end_at + timedelta(minutes=15),
                inc.start_at,
            ),
        )
        row = cur.fetchone()
    if row is None:
        return (None, None)
    bid = int(row["id"])
    return (bid, bid)
