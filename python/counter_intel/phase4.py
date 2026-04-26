"""Phase 4 — log-derived operational analytics.

Four passes, each idempotent and write-aside (UPSERT on the unique
key). Designed for incremental runs: pass `since_dt` and only that
window is rebuilt. Materialise aggressively — readers (dossier card,
CI dashboard) hit these tables, never raw eve_log_events.

Passes:
  run_timelines           §4.1 → operational_timeline_events
  run_fleet_participation §4.2 → fleet_presence_windows
  run_intel_reliability   §4.3 → intel_reliability_profiles
  run_session_correlation §4.4 → session_correlation_edges

Privacy:
  - never copies raw_line into the derived tables
  - evidence_json stores summarised counts + structured ids only
  - the dossier render layer is responsible for the no-raw-chat rule
    (ABAC-gated raw access is in the EveLogEventsAdmin Filament page)

All passes share the eve_log_events table; the controller already
parses the chat / combat / notify / intel rows during ingest.
"""

from __future__ import annotations

import json
import os
import re
import statistics
from collections import defaultdict
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4")


# ---- tuning constants (calibration spec revisits) ---------------------

def _env_int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None:
        return default
    try:
        return int(raw)
    except ValueError:
        return default


# Timeline clustering: events within ±N minutes + same listener +
# same source_listener collapse into one row.
TIMELINE_CLUSTER_MINUTES = _env_int("PHASE4_TIMELINE_CLUSTER_MINUTES", 5)

# Combat spike threshold. v1 used 10 events / window which over-fired
# every sustained engagement (each combat tick produces an event).
# Calibrated to 30 events / window — a real fight engagement still
# fires but per-tick chatter doesn't.
COMBAT_SPIKE_MIN_EVENTS = _env_int("PHASE4_COMBAT_SPIKE_MIN_EVENTS", 30)

# Self-destruct wave detection — minimum distinct (notify) self-
# destruct lines within the cluster window.
SELF_DESTRUCT_MIN_LINES = _env_int("PHASE4_SELF_DESTRUCT_MIN_LINES", 3)

# Self-destruct wave dedup — minimum gap between successive wave
# rows from the same listener+system. Stops a single fleet wipe from
# emitting 5+ adjacent rows.
SELF_DESTRUCT_MIN_GAP_MINUTES = _env_int("PHASE4_SELF_DESTRUCT_MIN_GAP_MINUTES", 30)

# Combat spike — minimum distinct line fingerprints within the
# cluster window. Suppresses overheating-tick repeats. Calibrated
# 8 → 4 in 4.4 — gamelog ticks share most of the message text, so
# 8 was too tight; 4 still excludes single-target overheating spam.
COMBAT_SPIKE_MIN_DISTINCT = _env_int("PHASE4_COMBAT_SPIKE_MIN_DISTINCT", 4)

# Disengagement — combat-rate derivative threshold. Fires when the
# events-per-minute rate drops by at least this fraction over a
# 10-minute lookahead vs the prior 5-minute window.
DISENGAGEMENT_DROP_FRACTION = float(os.environ.get("PHASE4_DISENGAGEMENT_DROP_FRACTION", "0.7"))

# Fleet presence: gap > N minutes between speaker's messages closes
# the window.
FLEET_SESSION_GAP_MINUTES = _env_int("PHASE4_FLEET_SESSION_GAP_MINUTES", 30)

# Intel confirmation window — a hostile killmail occurring within N
# minutes of an intel report counts as confirmation.
INTEL_CONFIRM_WINDOW_MINUTES = _env_int("PHASE4_INTEL_CONFIRM_WINDOW_MINUTES", 15)

# Session correlation: shared session if both characters had any
# log activity within an N-minute bucket.
SESSION_BUCKET_MINUTES = _env_int("PHASE4_SESSION_BUCKET_MINUTES", 5)

# Minimum reports for a meaningful intel reliability score.
MIN_INTEL_REPORTS_MEDIUM_CONFIDENCE = 10
MIN_INTEL_REPORTS_HIGH_CONFIDENCE = 30

# Minimum samples for a session correlation edge.
MIN_SESSION_OVERLAP_BUCKETS_MEDIUM = 6
MIN_SESSION_OVERLAP_BUCKETS_HIGH = 20

# Self-destruct: only count actual EVE-emitted notify patterns. The
# lazy substring "self-destruct" was matching MOTD chatter, fleet
# announcements, and quoted text. The real notify shape is one of:
#   "Your ship's self-destruct sequence has been initiated"
#   "Self-destruct sequence aborted"
#   "Capsule ... self-destruct"
# All on the (notify) gamelog flavor with a fixed phrasing.
_SELF_DESTRUCT_PATTERNS = [
    re.compile(r"self[- ]destruct sequence (has been )?initiated", re.I),
    re.compile(r"self[- ]destruct sequence (has been )?(activated|started)", re.I),
    re.compile(r"self[- ]destruct sequence (has been )?aborted", re.I),
    re.compile(r"capsule .* self[- ]destruct", re.I),
    re.compile(r"^you (have )?initiat(ed|e) self[- ]destruct", re.I),
]


# =====================================================================
# §4.1 — operational timeline events
# =====================================================================

@dataclass
class TimelineEvent:
    timeline_type: str
    event_timestamp: datetime
    source_listener: str | None
    solar_system_name: str | None
    confidence: str
    event_summary: str
    evidence: dict
    window_start: datetime | None = None
    window_end: datetime | None = None
    quality: str = "normal"
    solar_system_id: int | None = None
    region_id: int | None = None


# Phase 4.2C — quality is separate from confidence.
#   confidence = how much we trust the row exists at all
#   quality    = how operationally meaningful the row is
# Example: a high-confidence MOTD line is low quality (noise).
TIMELINE_QUALITY_BY_TYPE: dict[str, str] = {
    "fleet_formup": "strong",
    "hostile_report": "strong",
    "escalation": "strategic",
    "combat_spike": "normal",
    "self_destruct_wave": "strong",
    "extraction": "strong",
    "disengagement": "normal",
    "crash_symptom": "weak",
    "intel_gap": "normal",
    "unknown": "noisy",
}


def run_timelines(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
    dry_run: bool = False,
) -> dict:
    """Pattern-match log events into timeline_type rows.

    Detection rules implemented in v1 (each documented inline):
      - fleet_formup: cluster of fleet_message events ≥ N speakers
      - hostile_report: intel_report event
      - escalation: dense combat_event window after a hostile_report
      - combat_spike: cluster of combat events
      - self_destruct_wave: ≥3 pod losses (heuristic)
      - extraction: fleet_message + travel notify cluster, no combat
      - disengagement: combat_spike followed by silence > 5min
      - crash_symptom: long event gap from a previously-active listener
      - intel_gap: hostile killmail with no preceding intel_report
    """
    log.info("phase4 timelines starting", {"viewer_bloc_id": viewer_bloc_id, "since": since_dt.isoformat()})

    events = _load_events_window(conn, since_dt)
    log.info("events loaded", {"n": len(events)})
    if not events:
        return {"events_loaded": 0, "timelines_written": 0}

    by_listener_system: dict[tuple[str, str | None], list[dict]] = defaultdict(list)
    for e in events:
        key = (str(e.get("source_listener") or ""), e.get("solar_system_name"))
        by_listener_system[key].append(e)

    out: list[TimelineEvent] = []

    for (listener, system), bucket in by_listener_system.items():
        bucket.sort(key=lambda r: r["event_timestamp"])
        out.extend(_detect_fleet_formups(bucket, listener, system))
        out.extend(_detect_hostile_reports(bucket, listener, system))
        out.extend(_detect_combat_spikes_and_escalation(bucket, listener, system))
        out.extend(_detect_self_destruct_waves(bucket, listener, system))
        out.extend(_detect_disengagement_and_crash(bucket, listener, system))

    # Quality + system context attach.
    system_id_cache: dict[str, tuple[int | None, int | None]] = {}
    for ev in out:
        ev.quality = TIMELINE_QUALITY_BY_TYPE.get(ev.timeline_type, "normal")
        if ev.solar_system_name:
            sid_rid = system_id_cache.get(ev.solar_system_name)
            if sid_rid is None:
                with conn.cursor() as cur:
                    cur.execute(
                        "SELECT id, region_id FROM ref_solar_systems WHERE name = %s LIMIT 1",
                        (ev.solar_system_name,),
                    )
                    row = cur.fetchone()
                sid_rid = (
                    int(row["id"]) if row else None,
                    int(row["region_id"]) if row and row.get("region_id") is not None else None,
                )
                system_id_cache[ev.solar_system_name] = sid_rid
            ev.solar_system_id, ev.region_id = sid_rid

    if dry_run:
        for ev in out[:50]:
            log.info("[dry-run] timeline", {
                "type": ev.timeline_type, "ts": str(ev.event_timestamp),
                "listener": ev.source_listener, "system": ev.solar_system_name,
                "confidence": ev.confidence, "quality": ev.quality, "summary": ev.event_summary,
            })
        log.info("phase4 timelines dry-run done", {"events_loaded": len(events), "would_write": len(out)})
        return {"events_loaded": len(events), "would_write": len(out), "dry_run": True}

    written = 0
    for ev in out:
        _persist_timeline(conn, viewer_bloc_id, ev)
        written += 1
    conn.commit()
    log.info("phase4 timelines done", {"events_loaded": len(events), "timelines_written": written})
    return {"events_loaded": len(events), "timelines_written": written}


def _load_events_window(conn, since_dt: datetime) -> list[dict]:
    """Pull eve_log_events + file metadata in the active window.
    solar_system_name comes from:
      1. eve_log_events.system_name (parser sets this for EVE System
         lines)
      2. fallback: first system entity_resolution attached to the
         event (Phase 4.4 enrichment — picks up systems mentioned in
         intel chatter so the timeline clusters can group by
         operational system instead of falling into the no-system
         bucket)
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT e.id, e.event_timestamp, e.event_type, e.actor_name,
                   COALESCE(e.system_name, sys.resolved_entity_name) AS solar_system_name,
                   sys.resolved_entity_id AS resolved_system_id,
                   e.channel_name,
                   e.parsed_json, e.line_offset,
                   f.id AS file_id, f.listener AS source_listener,
                   f.log_type, f.user_id
              FROM eve_log_events e
              JOIN eve_log_files f ON f.id = e.eve_log_file_id
              LEFT JOIN (
                SELECT eve_log_event_id, MIN(id) AS keep_id
                  FROM eve_log_entity_resolutions
                 WHERE resolved_entity_type = 'system'
                 GROUP BY eve_log_event_id
              ) syskeep ON syskeep.eve_log_event_id = e.id
              LEFT JOIN eve_log_entity_resolutions sys
                ON sys.id = syskeep.keep_id
             WHERE e.event_timestamp IS NOT NULL
               AND e.event_timestamp >= %s
             ORDER BY e.event_timestamp
            """,
            (since_dt,),
        )
        return list(cur.fetchall())


def _detect_fleet_formups(
    bucket: list[dict], listener: str, system: str | None,
) -> list[TimelineEvent]:
    """Cluster of >= 6 fleet_message events from >= 3 distinct
    speakers within a 5-minute window, AND preceded by ≥ 5 minutes
    of silence on the same fleet channel.

    Phase 4.2B: pre-cluster-silence gate added so sustained fleet
    chatter doesn't fire form-up rows every 5 minutes."""
    fleet_msgs = [e for e in bucket if e["event_type"] == "fleet_message"]
    if len(fleet_msgs) < 6:
        return []
    out: list[TimelineEvent] = []
    window: list[dict] = []
    last_fired_at: datetime | None = None
    silence_required = TIMELINE_CLUSTER_MINUTES * 60  # seconds of pre-cluster silence
    for idx, m in enumerate(fleet_msgs):
        ts = m["event_timestamp"]
        while window and (ts - window[0]["event_timestamp"]).total_seconds() > TIMELINE_CLUSTER_MINUTES * 60:
            window.pop(0)
        window.append(m)
        if len(window) < 6:
            continue
        speakers = {e["actor_name"] for e in window if e.get("actor_name")}
        if len(speakers) < 3:
            continue
        ws = window[0]["event_timestamp"]
        # Pre-cluster silence: previous fleet_message before window
        # start must be > silence_required seconds earlier (or there's
        # no previous message at all).
        prev = None
        for k in range(idx - len(window), -1, -1):
            if k < 0 or k >= len(fleet_msgs): continue
            prev = fleet_msgs[k]
            break
        if prev is not None and (ws - prev["event_timestamp"]).total_seconds() < silence_required:
            continue
        # Cooldown: don't double-fire on the same listener/system if
        # we already fired in the last 30min.
        if last_fired_at is not None and (ts - last_fired_at).total_seconds() < 30 * 60:
            window = []
            continue
        we = window[-1]["event_timestamp"]
        out.append(TimelineEvent(
            timeline_type="fleet_formup",
            event_timestamp=ws,
            window_start=ws, window_end=we,
            source_listener=listener or None,
            solar_system_name=system,
            confidence="medium",
            event_summary=f"Fleet form-up: {len(window)} messages from {len(speakers)} speakers in {(we - ws).seconds // 60 + 1}m (after silence).",
            evidence={"speakers": sorted(speakers), "messages": len(window)},
        ))
        last_fired_at = we
        window = []
    return out


def _detect_hostile_reports(
    bucket: list[dict], listener: str, system: str | None,
) -> list[TimelineEvent]:
    """Phase 4.4 calibration: hostile_report timeline emission is
    DISABLED. Per-event rows generated 40K timeline rows that
    overwhelmed the dossier surface; the same information is
    represented operationally by operational_hostile_clusters
    (Phase 4.3A) which the incident-fusion layer already reads
    directly.

    Kept as a function (returning empty) so the calling code path
    stays untouched and the calibration is reversible via env var
    if needed later."""
    if os.environ.get("PHASE4_EMIT_HOSTILE_REPORTS") == "1":
        out: list[TimelineEvent] = []
        for e in bucket:
            if e["event_type"] != "intel_report":
                continue
            actor = e.get("actor_name") or "unknown"
            out.append(TimelineEvent(
                timeline_type="hostile_report",
                event_timestamp=e["event_timestamp"],
                source_listener=listener or None,
                solar_system_name=system,
                confidence="high",
                event_summary=f"Intel report from {actor} on channel {e.get('channel_name') or '—'}.",
                evidence={"actor": actor, "channel": e.get("channel_name")},
            ))
        return out
    return []


def _detect_combat_spikes_and_escalation(
    bucket: list[dict], listener: str, system: str | None,
) -> list[TimelineEvent]:
    """combat_spike: ≥ COMBAT_SPIKE_MIN_EVENTS combat_event lines in
    a 5-min window AND ≥ COMBAT_SPIKE_MIN_DISTINCT distinct
    fingerprints (suppresses single-target overheating tick spam) AND
    a 30-min cooldown between fires.

    escalation: same trigger plus a hostile_report in the previous 15
    minutes."""
    combat_msgs = [e for e in bucket if e["event_type"] == "combat_event"]
    if len(combat_msgs) < COMBAT_SPIKE_MIN_EVENTS:
        return []
    intel_msgs = [e for e in bucket if e["event_type"] == "intel_report"]
    out: list[TimelineEvent] = []
    window: list[dict] = []
    last_fired_at: datetime | None = None
    cooldown = 30 * 60
    for m in combat_msgs:
        ts = m["event_timestamp"]
        while window and (ts - window[0]["event_timestamp"]).total_seconds() > TIMELINE_CLUSTER_MINUTES * 60:
            window.pop(0)
        window.append(m)
        if len(window) < COMBAT_SPIKE_MIN_EVENTS:
            continue
        # Distinct fingerprints — derive a coarse hash of the message
        # text so identical-tick repeats don't all count.
        prints = set()
        for w in window:
            try:
                px = json.loads(w.get("parsed_json") or "{}")
            except (TypeError, ValueError):
                px = {}
            msg = (px.get("message") or "")
            # Strip HTML-ish markup + numbers — leaves the source/target
            # tokens intact, dedupes "12 from Bad Guy" + "8 from Bad Guy".
            fp = re.sub(r"<[^>]+>", " ", msg)
            fp = re.sub(r"\d+", "N", fp).strip()
            prints.add(fp[:80])
        if len(prints) < COMBAT_SPIKE_MIN_DISTINCT:
            continue
        if last_fired_at is not None and (ts - last_fired_at).total_seconds() < cooldown:
            window = []
            continue
        ws = window[0]["event_timestamp"]
        we = window[-1]["event_timestamp"]
        preceding_intel = [
            ir for ir in intel_msgs
            if (ws - ir["event_timestamp"]).total_seconds() <= 15 * 60
            and ir["event_timestamp"] <= ws
        ]
        if preceding_intel:
            out.append(TimelineEvent(
                timeline_type="escalation",
                event_timestamp=ws,
                window_start=ws, window_end=we,
                source_listener=listener or None,
                solar_system_name=system,
                confidence="medium",
                event_summary=f"Escalation: {len(window)} combat events / {len(prints)} distinct lines in {(we - ws).seconds // 60 + 1}m, preceded by {len(preceding_intel)} intel report(s).",
                evidence={"combat_n": len(window), "distinct": len(prints), "intel_n": len(preceding_intel)},
            ))
        else:
            out.append(TimelineEvent(
                timeline_type="combat_spike",
                event_timestamp=ws,
                window_start=ws, window_end=we,
                source_listener=listener or None,
                solar_system_name=system,
                confidence="medium",
                event_summary=f"Combat spike: {len(window)} combat events / {len(prints)} distinct lines in {(we - ws).seconds // 60 + 1}m.",
                evidence={"combat_n": len(window), "distinct": len(prints)},
            ))
        last_fired_at = we
        window = []
    return out


def _detect_self_destruct_waves(
    bucket: list[dict], listener: str, system: str | None,
) -> list[TimelineEvent]:
    """≥SELF_DESTRUCT_MIN_LINES notify_event lines that match an
    actual EVE self-destruct notify pattern within the cluster window.

    v1 used a lazy 'self-destruct' substring match which fired on
    MOTD chatter, fleet ping copy-paste, and quoted strategy text.
    Now requires:
      - event_type == 'notify_event'
      - parsed_json.gamelog_kind == 'notify' (the actual game UI
        notify channel — excludes (info)/(hint)/(warning))
      - message matches one of _SELF_DESTRUCT_PATTERNS
    """
    out: list[TimelineEvent] = []
    sd_window: list[dict] = []
    last_fired_at: datetime | None = None
    min_gap = SELF_DESTRUCT_MIN_GAP_MINUTES * 60
    for m in bucket:
        if m["event_type"] != "notify_event":
            continue
        parsed = m.get("parsed_json")
        try:
            d = json.loads(parsed) if parsed else None
        except (TypeError, ValueError):
            d = None
        if not isinstance(d, dict):
            continue
        if (d.get("gamelog_kind") or "").lower() != "notify":
            continue
        msg = (d.get("message") or "")
        if not any(p.search(msg) for p in _SELF_DESTRUCT_PATTERNS):
            continue
        ts = m["event_timestamp"]
        while sd_window and (ts - sd_window[0]["event_timestamp"]).total_seconds() > TIMELINE_CLUSTER_MINUTES * 60:
            sd_window.pop(0)
        sd_window.append(m)
        if last_fired_at is not None and (ts - last_fired_at).total_seconds() < min_gap:
            # In cooldown after a previous wave on this listener/system.
            continue
        if len(sd_window) >= SELF_DESTRUCT_MIN_LINES:
            ws = sd_window[0]["event_timestamp"]
            we = sd_window[-1]["event_timestamp"]
            samples: list[str] = []
            for x in sd_window[:3]:
                try:
                    px = json.loads(x.get("parsed_json") or "{}")
                except (TypeError, ValueError):
                    px = {}
                samples.append(str(px.get("message") or "")[:120])
            out.append(TimelineEvent(
                timeline_type="self_destruct_wave",
                event_timestamp=ws,
                window_start=ws, window_end=we,
                source_listener=listener or None,
                solar_system_name=system,
                confidence="medium",
                event_summary=f"Self-destruct wave: {len(sd_window)} (notify) self-destruct lines in {(we - ws).seconds // 60 + 1}m.",
                evidence={"count": len(sd_window), "samples": samples},
            ))
            last_fired_at = we
            sd_window = []
    return out


def _detect_disengagement_and_crash(
    bucket: list[dict], listener: str, system: str | None,
) -> list[TimelineEvent]:
    """disengagement: combat-rate derivative drops sharply.
    Compute combat_events/min over a sliding 5-min window; fire when
    a 5-min window's rate drops by >= DISENGAGEMENT_DROP_FRACTION
    relative to the prior window's rate.

    crash_symptom: any single gap > 30min in an otherwise-active
    listener with > 100 events in the last hour."""
    if len(bucket) < 30:
        return []
    out: list[TimelineEvent] = []
    combat_msgs = [e for e in bucket if e["event_type"] == "combat_event"]

    # Disengagement via rate derivative. Bucket combat events into
    # 1-minute slots, then walk a 5-min window vs the previous 5-min
    # window. Fire when rate drops sharply AND prior window had real
    # density (> 5 events / minute).
    if len(combat_msgs) >= 30:
        bucket_by_minute: dict[int, int] = defaultdict(int)
        for m in combat_msgs:
            slot = int(m["event_timestamp"].timestamp() // 60)
            bucket_by_minute[slot] += 1
        if bucket_by_minute:
            min_slot = min(bucket_by_minute)
            max_slot = max(bucket_by_minute)
            last_fire_slot = -10**9
            for s in range(min_slot + 5, max_slot - 5):
                prev_rate = sum(bucket_by_minute.get(s - i, 0) for i in range(1, 6)) / 5.0
                next_rate = sum(bucket_by_minute.get(s + i, 0) for i in range(0, 5)) / 5.0
                if prev_rate < 5.0:
                    continue  # baseline too low, not a real engagement
                drop = (prev_rate - next_rate) / prev_rate if prev_rate > 0 else 0.0
                if drop < DISENGAGEMENT_DROP_FRACTION:
                    continue
                if s - last_fire_slot < 30:  # 30-minute cooldown
                    continue
                last_fire_slot = s
                ts = datetime.fromtimestamp(s * 60, tz=timezone.utc)
                out.append(TimelineEvent(
                    timeline_type="disengagement",
                    event_timestamp=ts,
                    source_listener=listener or None,
                    solar_system_name=system,
                    confidence="medium",
                    event_summary=f"Disengagement: combat rate dropped {int(drop*100)}% (prior 5m {prev_rate:.1f}/min → next 5m {next_rate:.1f}/min).",
                    evidence={"prev_rate": round(prev_rate, 2), "next_rate": round(next_rate, 2), "drop_pct": round(drop * 100, 1)},
                ))

    # Crash symptom: sustained activity then long gap.
    timestamps = [e["event_timestamp"] for e in bucket]
    for i in range(1, len(timestamps)):
        gap = (timestamps[i] - timestamps[i - 1]).total_seconds()
        if gap < 30 * 60:
            continue
        recent = [e for e in bucket[: i] if e["event_timestamp"] >= timestamps[i - 1] - timedelta(hours=1)]
        if len(recent) >= 100:
            out.append(TimelineEvent(
                timeline_type="crash_symptom",
                event_timestamp=timestamps[i - 1],
                source_listener=listener or None,
                solar_system_name=system,
                confidence="low",
                event_summary=f"Possible crash: {len(recent)} events in last hour, then {int(gap // 60)}m silence.",
                evidence={"recent_events": len(recent), "gap_seconds": int(gap)},
            ))
    return out


def _persist_timeline(
    conn: pymysql.connections.Connection,
    viewer_bloc_id: int,
    ev: TimelineEvent,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO operational_timeline_events
                (viewer_bloc_id, timeline_type, event_timestamp, event_window_start,
                 event_window_end, source_listener, solar_system_name,
                 solar_system_id, region_id,
                 confidence, quality, event_summary, evidence_json)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                event_window_start = VALUES(event_window_start),
                event_window_end = VALUES(event_window_end),
                solar_system_name = VALUES(solar_system_name),
                solar_system_id = VALUES(solar_system_id),
                region_id = VALUES(region_id),
                confidence = VALUES(confidence),
                quality = VALUES(quality),
                event_summary = VALUES(event_summary),
                evidence_json = VALUES(evidence_json)
            """,
            (
                viewer_bloc_id, ev.timeline_type, ev.event_timestamp,
                ev.window_start, ev.window_end, ev.source_listener,
                ev.solar_system_name, ev.solar_system_id, ev.region_id,
                ev.confidence, ev.quality, ev.event_summary[:500],
                json.dumps(ev.evidence, default=str),
            ),
        )


# =====================================================================
# §4.2 — fleet presence windows
# =====================================================================

def run_fleet_participation(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
) -> dict:
    """Build per-character fleet presence windows from fleet-channel
    chat activity, then correlate with killmail attendance to derive
    a participation_score + role classification."""
    log.info("phase4 fleet participation starting",
             {"viewer_bloc_id": viewer_bloc_id, "since": since_dt.isoformat()})

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT e.event_timestamp, e.actor_name, e.channel_name,
                   f.listener AS listener_name
              FROM eve_log_events e
              JOIN eve_log_files f ON f.id = e.eve_log_file_id
             WHERE e.event_type = 'fleet_message'
               AND e.actor_name IS NOT NULL
               AND e.event_timestamp >= %s
             ORDER BY e.actor_name, e.channel_name, e.event_timestamp
            """,
            (since_dt,),
        )
        msgs = list(cur.fetchall())
    if not msgs:
        return {"messages": 0, "windows_written": 0}

    # Group by (actor_name, channel_name) and split into sessions on
    # gaps > FLEET_SESSION_GAP_MINUTES.
    grouped: dict[tuple[str, str | None, str | None], list[dict]] = defaultdict(list)
    for m in msgs:
        grouped[(m["actor_name"], m["channel_name"], m["listener_name"])].append(m)

    written = 0
    for (actor, channel, listener), seq in grouped.items():
        sessions = _split_into_sessions(seq, FLEET_SESSION_GAP_MINUTES)
        for sess in sessions:
            if not sess:
                continue
            start = sess[0]["event_timestamp"]
            end = sess[-1]["event_timestamp"]
            duration_min = max(1, int((end - start).total_seconds() // 60))
            spoken = len(sess)

            # Killmail correlation. For now: count killmails in the
            # window where the actor appears as attacker. We resolve
            # actor → character_id via esi_entity_names if present.
            killmail_count, character_id = _killmail_count_in_window(conn, actor, start, end)
            combat_events = killmail_count  # proxy for v1

            participation_score = (combat_events / max(1, duration_min // 5))
            participation_score = min(participation_score, 1.0)

            derived_role, confidence = _classify_fleet_role(
                spoken=spoken,
                duration_min=duration_min,
                killmail_count=killmail_count,
                channel=channel or "",
            )

            evidence = {
                "spoken_messages": spoken,
                "duration_minutes": duration_min,
                "killmails": killmail_count,
                "character_id": character_id,
            }

            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO fleet_presence_windows
                        (viewer_bloc_id, character_name, listener_name, fleet_channel,
                         start_at, end_at, duration_minutes, participation_score,
                         combat_events, killmail_count, spoke_in_fleet, spoken_messages,
                         derived_role, confidence, evidence_json)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        end_at = VALUES(end_at),
                        duration_minutes = VALUES(duration_minutes),
                        participation_score = VALUES(participation_score),
                        combat_events = VALUES(combat_events),
                        killmail_count = VALUES(killmail_count),
                        spoke_in_fleet = VALUES(spoke_in_fleet),
                        spoken_messages = VALUES(spoken_messages),
                        derived_role = VALUES(derived_role),
                        confidence = VALUES(confidence),
                        evidence_json = VALUES(evidence_json)
                    """,
                    (
                        viewer_bloc_id, actor, listener, channel,
                        start, end, duration_min,
                        round(participation_score, 4),
                        combat_events, killmail_count,
                        1 if spoken > 0 else 0, spoken,
                        derived_role, confidence,
                        json.dumps(evidence, default=str),
                    ),
                )
            written += 1
    conn.commit()
    log.info("phase4 fleet participation done", {"messages": len(msgs), "windows_written": written})
    return {"messages": len(msgs), "windows_written": written}


def _split_into_sessions(
    msgs: list[dict], gap_minutes: int,
) -> list[list[dict]]:
    sessions: list[list[dict]] = []
    current: list[dict] = []
    for m in msgs:
        if current and (m["event_timestamp"] - current[-1]["event_timestamp"]).total_seconds() > gap_minutes * 60:
            sessions.append(current)
            current = [m]
        else:
            current.append(m)
    if current:
        sessions.append(current)
    return sessions


def _killmail_count_in_window(
    conn: pymysql.connections.Connection,
    actor_name: str,
    start: datetime,
    end: datetime,
) -> tuple[int, int | None]:
    """Resolve actor name → character_id via esi_entity_names, then
    count killmails in the window. Returns (count, character_id)."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT entity_id FROM esi_entity_names "
            "WHERE category='character' AND name=%s LIMIT 1",
            (actor_name,),
        )
        row = cur.fetchone()
        if row is None:
            return (0, None)
        cid = int(row["entity_id"])
        cur.execute(
            """
            SELECT COUNT(*) AS n FROM (
              SELECT killmail_id FROM killmail_attackers
               WHERE character_id = %s
              UNION
              SELECT killmail_id FROM killmails WHERE victim_character_id = %s
            ) mine JOIN killmails k ON k.killmail_id = mine.killmail_id
            WHERE k.killed_at BETWEEN %s AND %s
            """,
            (cid, cid, start, end),
        )
        n = int((cur.fetchone() or {}).get("n") or 0)
        return (n, cid)


def _classify_fleet_role(
    spoken: int, duration_min: int, killmail_count: int, channel: str,
) -> tuple[str, str]:
    """Conservative classifier. Calibration spec retunes."""
    chl = (channel or "").lower()
    if "logi" in chl:
        return ("logistics_presence", "low")
    if "scout" in chl or "intel" in chl:
        return ("scout_presence", "low")
    if killmail_count == 0 and spoken >= 5:
        return ("fleet_lurker", "low")
    if killmail_count == 0 and spoken < 5:
        return ("passive_observer", "low")
    if killmail_count >= 3:
        return ("active_combatant", "medium")
    return ("unknown", "low")


# =====================================================================
# §4.3 — intel reliability
# =====================================================================

def run_intel_reliability(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    """Per-reporter reliability score. Uses ALL intel_report events
    in the window, correlates with killmails to count confirmations
    (hostile named appears in killmails within window) and silence-
    before-hostiles (hostile killmail in actor's "active" window with
    no preceding intel_report)."""
    window_start = datetime.combine(window_end - timedelta(days=window_days - 1),
                                    datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)

    log.info("phase4 intel reliability starting",
             {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    # Pull intel reports + their resolved character entities in one
    # join. Heuristic name extraction (v1) is replaced by Phase 4.2A
    # eve_log_entity_resolutions which uses canonical
    # esi_entity_names lookups with confidence scoring.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT e.id, e.event_timestamp, e.actor_name,
                   r.resolved_entity_id AS hostile_cid,
                   r.resolved_entity_name AS hostile_name,
                   r.resolution_confidence AS hostile_conf
              FROM eve_log_events e
              LEFT JOIN eve_log_entity_resolutions r
                ON r.eve_log_event_id = e.id
               AND r.resolved_entity_type = 'character'
               AND r.resolution_confidence IN ('medium','high')
              WHERE e.event_type = 'intel_report'
                AND e.event_timestamp BETWEEN %s AND %s
                AND e.actor_name IS NOT NULL
              ORDER BY e.actor_name, e.event_timestamp
            """,
            (window_start, window_end_dt),
        )
        rows = list(cur.fetchall())
    if not rows:
        return {"reports": 0, "profiles_written": 0}

    # Group by (reporter, event_id) → set of hostile cids.
    reporter_events: dict[str, dict[int, dict]] = defaultdict(dict)
    for r in rows:
        actor = r["actor_name"]
        eid = int(r["id"])
        if eid not in reporter_events[actor]:
            reporter_events[actor][eid] = {
                "event_timestamp": r["event_timestamp"],
                "hostiles": set(),
            }
        if r["hostile_cid"] is not None:
            reporter_events[actor][eid]["hostiles"].add(int(r["hostile_cid"]))

    written = 0
    for reporter, by_event in reporter_events.items():
        confirmations = 0
        contradictions = 0
        latencies: list[int] = []
        for eid, ev in by_event.items():
            hostiles = ev["hostiles"]
            if not hostiles:
                # No resolved hostile name in this report — skip (was
                # over-counted as contradiction in v1).
                continue
            window_close = ev["event_timestamp"] + timedelta(minutes=INTEL_CONFIRM_WINDOW_MINUTES)
            confirmed_any = False
            for cid in hostiles:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        SELECT MIN(k.killed_at) AS first_seen FROM (
                          SELECT killmail_id FROM killmail_attackers WHERE character_id=%s
                          UNION
                          SELECT killmail_id FROM killmails WHERE victim_character_id=%s
                        ) m JOIN killmails k ON k.killmail_id = m.killmail_id
                        WHERE k.killed_at BETWEEN %s AND %s
                        """,
                        (cid, cid, ev["event_timestamp"], window_close),
                    )
                    row = cur.fetchone() or {}
                    first_seen = row.get("first_seen")
                if first_seen is not None:
                    confirmed_any = True
                    latencies.append(int((first_seen - ev["event_timestamp"]).total_seconds()))
            if confirmed_any:
                confirmations += 1
            else:
                contradictions += 1
        # Total reports = events with at least one resolved hostile.
        reps = [(eid, ev) for eid, ev in by_event.items() if ev["hostiles"]]

        reports_n = len(reps)
        false_alarm = round(contradictions / reports_n, 4) if reports_n > 0 else None
        avg_latency = int(statistics.fmean(latencies)) if latencies else None
        reliability = round(confirmations / reports_n, 4) if reports_n > 0 else None
        confidence = (
            "high" if reports_n >= MIN_INTEL_REPORTS_HIGH_CONFIDENCE
            else "medium" if reports_n >= MIN_INTEL_REPORTS_MEDIUM_CONFIDENCE
            else "low" if reports_n >= 3
            else "insufficient"
        )
        if reports_n == 0:
            # Reporter sent intel events but none resolved to a real
            # character — skip writing a profile (avoids zero rows).
            continue

        evidence = {
            "confirmations": confirmations,
            "contradictions": contradictions,
            "avg_latency_seconds": avg_latency,
            "reports": reports_n,
        }

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO intel_reliability_profiles
                    (viewer_bloc_id, character_name, window_end_date, window_days,
                     reports_submitted, confirmations, contradictions, false_alarm_rate,
                     avg_report_latency_seconds, silence_before_hostiles, repeated_hostile_overlap,
                     reliability_score, confidence, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    reports_submitted = VALUES(reports_submitted),
                    confirmations = VALUES(confirmations),
                    contradictions = VALUES(contradictions),
                    false_alarm_rate = VALUES(false_alarm_rate),
                    avg_report_latency_seconds = VALUES(avg_report_latency_seconds),
                    silence_before_hostiles = VALUES(silence_before_hostiles),
                    repeated_hostile_overlap = VALUES(repeated_hostile_overlap),
                    reliability_score = VALUES(reliability_score),
                    confidence = VALUES(confidence),
                    evidence_json = VALUES(evidence_json),
                    computed_at = NOW()
                """,
                (
                    viewer_bloc_id, reporter, window_end, window_days,
                    reports_n, confirmations, contradictions, false_alarm,
                    avg_latency, 0, 0,  # silence_before / repeated_overlap — v2
                    reliability, confidence,
                    json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    total_reports = sum(len([1 for ev in by_event.values() if ev["hostiles"]])
                         for by_event in reporter_events.values())
    log.info("phase4 intel reliability done", {"reports": total_reports, "profiles_written": written})
    return {"reports": total_reports, "profiles_written": written}


def _extract_hostile_names_from_intel(parsed_json: str | None) -> list[str]:
    """Heuristic name extraction. Real impl needs an EVE name table
    lookup against esi_entity_names; v1 just splits the message text
    on common separators and strips obvious non-name tokens."""
    if not parsed_json:
        return []
    try:
        d = json.loads(parsed_json)
    except (TypeError, ValueError):
        return []
    msg = (d or {}).get("message") or ""
    # Tokens that look like character names — words capitalised + space.
    # We don't try to be clever here. v2 will read against
    # esi_entity_names.name to confirm.
    out = []
    parts = msg.replace("{", " ").replace("}", " ").split()
    for p in parts:
        p = p.strip(",.;:")
        if not p:
            continue
        if not p[0].isupper():
            continue
        if p.lower() in {"in", "system", "warp", "gate", "station", "structure", "fleet"}:
            continue
        out.append(p)
    return out[:10]


def _resolve_character_id(
    conn: pymysql.connections.Connection, name: str,
) -> int | None:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT entity_id FROM esi_entity_names "
            "WHERE category='character' AND name=%s LIMIT 1",
            (name,),
        )
        row = cur.fetchone()
        return int(row["entity_id"]) if row else None


# =====================================================================
# §4.4 — session correlation
# =====================================================================

def run_session_correlation(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    """Pairwise temporal overlap. For each character active in the
    window, bucket their event timestamps into 5-minute slots; for
    each character pair, count buckets where both were active.
    High overlap + repeated patterns → correlation_score."""
    window_start = datetime.combine(window_end - timedelta(days=window_days - 1),
                                    datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)

    log.info("phase4 session correlation starting",
             {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT actor_name, event_timestamp
              FROM eve_log_events
             WHERE event_timestamp BETWEEN %s AND %s
               AND actor_name IS NOT NULL
            """,
            (window_start, window_end_dt),
        )
        rows = list(cur.fetchall())
    if not rows:
        return {"events": 0, "edges_written": 0}

    # actor → set of bucket ids
    buckets_by_actor: dict[str, set[int]] = defaultdict(set)
    bucket_seconds = SESSION_BUCKET_MINUTES * 60
    for r in rows:
        ts = r["event_timestamp"]
        bid = int(ts.timestamp() // bucket_seconds)
        buckets_by_actor[r["actor_name"]].add(bid)

    actors = list(buckets_by_actor.keys())
    written = 0
    # Pairwise — quadratic but bounded by number of distinct actors;
    # for v1 we cap at top-N most-active actors so the cost stays
    # bounded even on large windows.
    top_actors = sorted(actors, key=lambda a: -len(buckets_by_actor[a]))[:300]
    for i, a in enumerate(top_actors):
        a_buckets = buckets_by_actor[a]
        if len(a_buckets) < 3:
            continue
        for b in top_actors[i + 1:]:
            b_buckets = buckets_by_actor[b]
            shared = len(a_buckets & b_buckets)
            if shared < MIN_SESSION_OVERLAP_BUCKETS_MEDIUM:
                continue
            shared_minutes = shared * SESSION_BUCKET_MINUTES
            score = round(shared / max(min(len(a_buckets), len(b_buckets)), 1), 4)
            confidence = (
                "high" if shared >= MIN_SESSION_OVERLAP_BUCKETS_HIGH
                else "medium"
            )
            evidence = {
                "shared_buckets": shared,
                "a_buckets": len(a_buckets),
                "b_buckets": len(b_buckets),
                "bucket_minutes": SESSION_BUCKET_MINUTES,
            }
            char_a, char_b = sorted([a, b])
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO session_correlation_edges
                        (viewer_bloc_id, character_a, character_b, window_end_date, window_days,
                         shared_overlap_minutes, repeated_overlap_count, avg_offset_seconds,
                         correlation_score, sample_size_a, sample_size_b, confidence, evidence_json)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        shared_overlap_minutes = VALUES(shared_overlap_minutes),
                        repeated_overlap_count = VALUES(repeated_overlap_count),
                        avg_offset_seconds = VALUES(avg_offset_seconds),
                        correlation_score = VALUES(correlation_score),
                        sample_size_a = VALUES(sample_size_a),
                        sample_size_b = VALUES(sample_size_b),
                        confidence = VALUES(confidence),
                        evidence_json = VALUES(evidence_json),
                        computed_at = NOW()
                    """,
                    (
                        viewer_bloc_id, char_a, char_b, window_end, window_days,
                        shared_minutes, shared, None,
                        score, len(a_buckets), len(b_buckets),
                        confidence, json.dumps(evidence, default=str),
                    ),
                )
            written += 1
    conn.commit()
    log.info("phase4 session correlation done", {"events": len(rows), "edges_written": written})
    return {"events": len(rows), "edges_written": written}
