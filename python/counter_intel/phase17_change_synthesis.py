"""Phase 17.1 — operational "what changed?" synthesis.

First safe-AI surface per ADR 0012 / 0013. Deterministic SQL
delta gatherer, deterministic templated synthesizer (LLM rewrite
optional and not in this first ship). Every row written satisfies
the ADR 0013 binding UI/UX rule:

    confidence band, evidence list, source references, caveats,
    freshness state, why-strengthened.

Window pairs:
    1h:  current = [now-1h, now);       comparison = [now-2h, now-1h)
    6h:  current = [now-6h, now);       comparison = [now-12h, now-6h)
    24h: current = [now-24h, now);      comparison = [now-48h, now-24h)
    7d:  current = [now-7d, now);       comparison = [now-14d, now-7d)
"""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from typing import Any

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase17_change_synthesis")


# Window definitions in seconds.
_WINDOWS: dict[str, int] = {
    "1h": 3600,
    "6h": 6 * 3600,
    "24h": 24 * 3600,
    "7d": 7 * 24 * 3600,
}


def _window_pair(window_type: str, now: datetime) -> tuple[datetime, datetime, datetime, datetime]:
    secs = _WINDOWS[window_type]
    cur_end = now
    cur_start = cur_end - timedelta(seconds=secs)
    cmp_end = cur_start
    cmp_start = cmp_end - timedelta(seconds=secs)
    return cur_start, cur_end, cmp_start, cmp_end


def _ratio(cur: int, prev: int) -> float | None:
    if prev == 0:
        return None
    return round(cur / prev, 3)


def _severity_from_delta(cur: int, prev: int, abs_floor: int = 5) -> str:
    """Map a count delta to severity. Conservative — first ship.

    Floor protects against rage-band on tiny absolute counts.
    """
    if cur < abs_floor and prev < abs_floor:
        return "info"
    r = _ratio(cur, prev)
    if r is None:
        # New activity from a zero baseline — elevated only when the
        # absolute count crosses the floor, otherwise informational.
        return "elevated" if cur >= abs_floor * 2 else "info"
    if r >= 4.0:
        return "critical"
    if r >= 2.5:
        return "elevated"
    if r >= 1.5:
        return "warning"
    return "info"


def _confidence_from_corroboration(cross_surface_hits: int) -> str:
    """Confidence band per ADR 0013 ladder.

    Promotion to high requires 2+ corroborating signals; promotion
    to confirmed always requires a human and never happens here.
    """
    if cross_surface_hits >= 2:
        return "high"
    if cross_surface_hits == 1:
        return "medium"
    return "low"


def _hash_refs(refs: list[dict[str, Any]]) -> str:
    """Stable digest of source refs for idempotency key."""
    canonical = json.dumps(refs, sort_keys=True, default=str)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


# ---------------------------------------------------------------
# Surface delta gatherers — pure SQL, deterministic, no LLM.
# ---------------------------------------------------------------

def _delta_incidents(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    cur_start: datetime,
    cur_end: datetime,
    cmp_start: datetime,
    cmp_end: datetime,
) -> dict[str, Any] | None:
    """Operational incidents delta — count + top systems."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n,
                   SUM(severity IN ('escalation','coalition_level')) AS critical_n,
                   SUM(severity = 'strategic') AS elevated_n
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND end_at >= %s AND end_at < %s
            """,
            (bloc_id, cur_start, cur_end),
        )
        cur_row = cur.fetchone() or {}
        cur.execute(
            """
            SELECT COUNT(*) AS n
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND end_at >= %s AND end_at < %s
            """,
            (bloc_id, cmp_start, cmp_end),
        )
        cmp_row = cur.fetchone() or {}
        cur.execute(
            """
            SELECT primary_system_name AS solar_system_name, COUNT(*) AS n
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND end_at >= %s AND end_at < %s
               AND primary_system_name IS NOT NULL
             GROUP BY primary_system_name
             ORDER BY n DESC
             LIMIT 5
            """,
            (bloc_id, cur_start, cur_end),
        )
        top_systems = list(cur.fetchall() or [])

    cur_n = int(cur_row.get("n") or 0)
    cmp_n = int(cmp_row.get("n") or 0)
    if cur_n == 0 and cmp_n == 0:
        return None
    return {
        "surface": "operational_incidents",
        "current_count": cur_n,
        "comparison_count": cmp_n,
        "ratio": _ratio(cur_n, cmp_n),
        "critical_count": int(cur_row.get("critical_n") or 0),
        "elevated_count": int(cur_row.get("elevated_n") or 0),
        "top_systems": top_systems,
    }


def _delta_alerts(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    cur_start: datetime,
    cur_end: datetime,
    cmp_start: datetime,
    cmp_end: datetime,
) -> dict[str, Any] | None:
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n,
                   SUM(severity = 'urgent') AS critical_n,
                   SUM(severity = 'elevated') AS elevated_n
              FROM strategic_alerts
             WHERE viewer_bloc_id = %s
               AND detected_at >= %s AND detected_at < %s
               AND dismissed_at IS NULL
            """,
            (bloc_id, cur_start, cur_end),
        )
        cur_row = cur.fetchone() or {}
        cur.execute(
            """
            SELECT COUNT(*) AS n
              FROM strategic_alerts
             WHERE viewer_bloc_id = %s
               AND detected_at >= %s AND detected_at < %s
               AND dismissed_at IS NULL
            """,
            (bloc_id, cmp_start, cmp_end),
        )
        cmp_row = cur.fetchone() or {}
        cur.execute(
            """
            SELECT alert_kind AS alert_type, COUNT(*) AS n
              FROM strategic_alerts
             WHERE viewer_bloc_id = %s
               AND detected_at >= %s AND detected_at < %s
               AND dismissed_at IS NULL
             GROUP BY alert_kind
             ORDER BY n DESC
             LIMIT 5
            """,
            (bloc_id, cur_start, cur_end),
        )
        top_types = list(cur.fetchall() or [])

    cur_n = int(cur_row.get("n") or 0)
    cmp_n = int(cmp_row.get("n") or 0)
    if cur_n == 0 and cmp_n == 0:
        return None
    return {
        "surface": "strategic_alerts",
        "current_count": cur_n,
        "comparison_count": cmp_n,
        "ratio": _ratio(cur_n, cmp_n),
        "critical_count": int(cur_row.get("critical_n") or 0),
        "elevated_count": int(cur_row.get("elevated_n") or 0),
        "top_types": top_types,
    }


def _delta_corridors(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    cur_start: datetime,
    cur_end: datetime,
    cmp_start: datetime,
    cmp_end: datetime,
) -> dict[str, Any] | None:
    """New corridors seen this window vs prior."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
               AND last_seen_at >= %s AND last_seen_at < %s
            """,
            (bloc_id, cur_start, cur_end),
        )
        cur_n = int((cur.fetchone() or {}).get("n") or 0)
        cur.execute(
            """
            SELECT COUNT(*) AS n
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
               AND last_seen_at >= %s AND last_seen_at < %s
            """,
            (bloc_id, cmp_start, cmp_end),
        )
        cmp_n = int((cur.fetchone() or {}).get("n") or 0)
        cur.execute(
            """
            SELECT CONCAT(from_system_name, ' → ', to_system_name) AS corridor_label,
                   route_classification, COUNT(*) AS n
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
               AND last_seen_at >= %s AND last_seen_at < %s
               AND from_system_name IS NOT NULL
               AND to_system_name IS NOT NULL
             GROUP BY from_system_name, to_system_name, route_classification
             ORDER BY n DESC
             LIMIT 5
            """,
            (bloc_id, cur_start, cur_end),
        )
        top_corridors = list(cur.fetchall() or [])

    if cur_n == 0 and cmp_n == 0:
        return None
    return {
        "surface": "operational_corridors",
        "current_count": cur_n,
        "comparison_count": cmp_n,
        "ratio": _ratio(cur_n, cmp_n),
        "top_corridors": top_corridors,
    }


def _delta_threat_surface(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    cur_end: datetime,
    cmp_end: datetime,
) -> dict[str, Any] | None:
    """Top systems by threat score delta (current snapshot vs prior)."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT solar_system_name, threat_score, computed_at
              FROM system_threat_surface
             WHERE viewer_bloc_id = %s
               AND computed_at <= %s
             ORDER BY threat_score DESC
             LIMIT 25
            """,
            (bloc_id, cur_end),
        )
        cur_rows = {r["solar_system_name"]: float(r["threat_score"] or 0) for r in (cur.fetchall() or [])}
        cur.execute(
            """
            SELECT solar_system_name, threat_score
              FROM system_threat_surface
             WHERE viewer_bloc_id = %s
               AND computed_at <= %s
             ORDER BY threat_score DESC
             LIMIT 25
            """,
            (bloc_id, cmp_end),
        )
        cmp_rows = {r["solar_system_name"]: float(r["threat_score"] or 0) for r in (cur.fetchall() or [])}

    if not cur_rows:
        return None
    deltas: list[dict[str, Any]] = []
    for sys_name, cur_score in cur_rows.items():
        prev_score = cmp_rows.get(sys_name, 0.0)
        delta = round(cur_score - prev_score, 3)
        if abs(delta) >= 0.05:
            deltas.append({
                "system": sys_name,
                "current_score": cur_score,
                "previous_score": prev_score,
                "delta": delta,
            })
    deltas.sort(key=lambda d: abs(d["delta"]), reverse=True)
    return {
        "surface": "system_threat_surface",
        "top_movers": deltas[:5],
    }


def _delta_doctrine_evolution(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    cur_start: datetime,
    cur_end: datetime,
) -> dict[str, Any] | None:
    """New doctrine_evolution_events landed in window."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n
              FROM doctrine_evolution_events
             WHERE viewer_bloc_id = %s
               AND computed_at >= %s AND computed_at < %s
            """,
            (bloc_id, cur_start, cur_end),
        )
        n = int((cur.fetchone() or {}).get("n") or 0)
        cur.execute(
            """
            SELECT alliance_name, event_type AS evolution_kind, COUNT(*) AS n
              FROM doctrine_evolution_events
             WHERE viewer_bloc_id = %s
               AND computed_at >= %s AND computed_at < %s
             GROUP BY alliance_name, event_type
             ORDER BY n DESC
             LIMIT 5
            """,
            (bloc_id, cur_start, cur_end),
        )
        top = list(cur.fetchall() or [])

    if n == 0:
        return None
    return {
        "surface": "doctrine_evolution_events",
        "current_count": n,
        "top_alliance_kinds": top,
    }


# ---------------------------------------------------------------
# Templated synthesizer — deterministic. LLM rewrite is a v2 polish.
# ---------------------------------------------------------------

def _synth_incidents(d: dict[str, Any], window_type: str) -> dict[str, Any] | None:
    cur_n = d["current_count"]
    cmp_n = d["comparison_count"]
    if cur_n == 0:
        return None
    severity = _severity_from_delta(cur_n, cmp_n)
    ratio = d["ratio"]
    ratio_str = f"{ratio}×" if ratio is not None else "vs zero baseline"
    top_str = ", ".join(f"{r['solar_system_name']} ({r['n']})" for r in d["top_systems"][:3]) or "no system concentration"
    title = f"Operational incidents in {window_type}: {cur_n} closed (was {cmp_n}, {ratio_str})"
    summary = (
        f"{cur_n} operational incidents closed in the {window_type} window vs {cmp_n} in "
        f"the comparison window. Top systems: {top_str}. "
        f"Critical: {d['critical_count']}, elevated: {d['elevated_count']}."
    )
    return {
        "summary_type": "incident_volume",
        "severity": severity,
        "title": title,
        "summary": summary,
        "evidence": d,
        "source_refs": [
            {"table": "operational_incidents", "field": "end_at",
             "where": f"viewer_bloc_id={{bloc}} AND end_at IN [{{cur_start}},{{cur_end}})",
             "url": "/portal/intelligence/incidents"},
        ],
        "caveats": _caveats_incidents(d),
    }


def _caveats_incidents(d: dict[str, Any]) -> list[str]:
    out = []
    if d["current_count"] < 5:
        out.append("low absolute volume — single events drive the band")
    if d["comparison_count"] == 0:
        out.append("no comparison baseline (zero prior incidents) — ratio undefined")
    return out


def _synth_alerts(d: dict[str, Any], window_type: str) -> dict[str, Any] | None:
    cur_n = d["current_count"]
    if cur_n == 0:
        return None
    severity = _severity_from_delta(cur_n, d["comparison_count"], abs_floor=3)
    ratio = d["ratio"]
    ratio_str = f"{ratio}×" if ratio is not None else "vs zero baseline"
    top_str = ", ".join(f"{r['alert_type']} ({r['n']})" for r in d["top_types"][:3]) or "mixed types"
    title = f"Strategic alerts in {window_type}: {cur_n} new (was {d['comparison_count']}, {ratio_str})"
    summary = (
        f"{cur_n} strategic alerts opened in the {window_type} window vs {d['comparison_count']} prior. "
        f"Top types: {top_str}. Critical: {d['critical_count']}, elevated: {d['elevated_count']}."
    )
    caveats = []
    if cur_n < 3:
        caveats.append("low absolute count — single alert can drive the band")
    return {
        "summary_type": "alert_volume",
        "severity": severity,
        "title": title,
        "summary": summary,
        "evidence": d,
        "source_refs": [
            {"table": "strategic_alerts", "field": "detected_at",
             "where": f"viewer_bloc_id={{bloc}} AND detected_at IN [{{cur_start}},{{cur_end}}) AND dismissed_at IS NULL",
             "url": "/portal/intelligence/alerts"},
        ],
        "caveats": caveats,
    }


def _synth_corridors(d: dict[str, Any], window_type: str) -> dict[str, Any] | None:
    cur_n = d["current_count"]
    cmp_n = d["comparison_count"]
    if cur_n == 0 and cmp_n == 0:
        return None
    if cur_n == 0:
        # Activity dropped to zero — operationally interesting.
        return {
            "summary_type": "corridor_silence",
            "severity": "warning" if cmp_n >= 5 else "info",
            "title": f"Corridor activity dropped to zero in {window_type} (was {cmp_n})",
            "summary": (
                f"No corridors were active in the {window_type} window. "
                f"The prior window saw {cmp_n} active corridors."
            ),
            "evidence": d,
            "source_refs": [
                {"table": "operational_corridors", "field": "last_seen_at",
                 "where": f"viewer_bloc_id={{bloc}}",
                 "url": "/portal/intelligence/operations-corridors"},
            ],
            "caveats": ["interpret with care — could indicate uploader gap rather than real silence"],
        }
    severity = _severity_from_delta(cur_n, cmp_n)
    ratio = d["ratio"]
    ratio_str = f"{ratio}×" if ratio is not None else "vs zero baseline"
    top_str = ", ".join(
        f"{r['corridor_label']} ({r['route_classification']}, {r['n']})"
        for r in d["top_corridors"][:3]
    ) or "no concentration"
    title = f"Corridors active in {window_type}: {cur_n} (was {cmp_n}, {ratio_str})"
    summary = (
        f"{cur_n} corridors registered activity in the {window_type} window vs {cmp_n} prior. "
        f"Top: {top_str}."
    )
    return {
        "summary_type": "corridor_activity",
        "severity": severity,
        "title": title,
        "summary": summary,
        "evidence": d,
        "source_refs": [
            {"table": "operational_corridors", "field": "last_seen_at",
             "where": f"viewer_bloc_id={{bloc}}",
             "url": "/portal/intelligence/operations-corridors"},
        ],
        "caveats": ["corridor counts include both new and recurring; check route_classification for novelty"],
    }


def _synth_threat_surface(d: dict[str, Any], window_type: str) -> dict[str, Any] | None:
    movers = d.get("top_movers") or []
    if not movers:
        return None
    rising = [m for m in movers if m["delta"] > 0]
    falling = [m for m in movers if m["delta"] < 0]
    if not rising and not falling:
        return None
    severity = "warning" if (rising and rising[0]["delta"] >= 0.20) else "info"
    rising_str = ", ".join(f"{m['system']} (+{m['delta']:.2f})" for m in rising[:3]) or "none"
    falling_str = ", ".join(f"{m['system']} ({m['delta']:.2f})" for m in falling[:3]) or "none"
    title = f"Threat-surface movement over {window_type}: top mover {movers[0]['system']} ({movers[0]['delta']:+.2f})"
    summary = (
        f"Threat scores shifted across the top systems. Rising: {rising_str}. Falling: {falling_str}."
    )
    return {
        "summary_type": "threat_surface_movement",
        "severity": severity,
        "title": title,
        "summary": summary,
        "evidence": d,
        "source_refs": [
            {"table": "system_threat_surface", "field": "computed_at",
             "where": f"viewer_bloc_id={{bloc}}",
             "url": "/portal/intelligence/threat-surface"},
        ],
        "caveats": [
            "threat-surface compares snapshots, not deltas in raw activity — score weights apply",
            "small absolute deltas (<0.05) suppressed",
        ],
    }


def _synth_doctrine_evolution(d: dict[str, Any], window_type: str) -> dict[str, Any] | None:
    n = d["current_count"]
    if n == 0:
        return None
    severity = "info" if n < 5 else "warning"
    top_str = ", ".join(
        f"{r['alliance_name']}/{r['evolution_kind']} ({r['n']})"
        for r in d["top_alliance_kinds"][:3]
    ) or "no concentration"
    return {
        "summary_type": "doctrine_evolution",
        "severity": severity,
        "title": f"Doctrine-evolution events in {window_type}: {n}",
        "summary": f"{n} doctrine-evolution events landed in the {window_type} window. Top: {top_str}.",
        "evidence": d,
        "source_refs": [
            {"table": "doctrine_evolution_events", "field": "computed_at",
             "where": f"viewer_bloc_id={{bloc}}",
             "url": "/portal/intelligence/doctrine-evolution"},
        ],
        "caveats": ["doctrine-evolution surfaces alliance composition shifts; needs operator validation"],
    }


# ---------------------------------------------------------------
# Cross-surface confidence — corroboration count.
# ---------------------------------------------------------------

def _count_corroboration(deltas: dict[str, dict[str, Any]]) -> int:
    """How many surfaces show non-info movement at the same time?"""
    hits = 0
    for d in deltas.values():
        if d.get("current_count", 0) and d.get("ratio") is not None and d["ratio"] >= 1.5:
            hits += 1
        if d.get("top_movers"):
            hits += 1
    return hits


# ---------------------------------------------------------------
# Why-strengthened diff against prior generation (ADR 0013 field 6).
# ---------------------------------------------------------------

def _why_strengthened(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    window_type: str,
    summary_type: str,
    cur_evidence: dict[str, Any],
) -> dict[str, Any] | None:
    """Compare to the most recent prior summary of the same kind."""
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT evidence_json, confidence
              FROM operational_change_summaries
             WHERE viewer_bloc_id = %s
               AND window_type = %s
               AND summary_type = %s
             ORDER BY generated_at DESC
             LIMIT 1
            """,
            (bloc_id, window_type, summary_type),
        )
        row = cur.fetchone()
    if not row:
        return {"first_observation": True}
    try:
        prior = json.loads(row["evidence_json"])
    except Exception:
        return {"first_observation": True}
    out: dict[str, Any] = {"prior_confidence": row["confidence"]}
    cur_n = cur_evidence.get("current_count")
    prev_n = prior.get("current_count")
    if cur_n is not None and prev_n is not None and prev_n != cur_n:
        out["count_delta_vs_prior_render"] = cur_n - prev_n
    return out


# ---------------------------------------------------------------
# Persist a finding.
# ---------------------------------------------------------------

def _persist_finding(
    conn: pymysql.connections.Connection,
    *,
    bloc_id: int,
    window_type: str,
    cur_start: datetime,
    cur_end: datetime,
    cmp_start: datetime,
    cmp_end: datetime,
    finding: dict[str, Any],
    confidence: str,
    why_strengthened: dict[str, Any] | None,
    ai_model: str | None,
    ai_prompt_hash: str | None,
) -> int:
    """Returns the operational_change_summaries.id for the row."""
    refs = finding.get("source_refs") or []
    refs_hash = _hash_refs(refs)
    now = datetime.now(timezone.utc)

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO operational_change_summaries
              (viewer_bloc_id, window_type,
               current_window_start, current_window_end,
               comparison_window_start, comparison_window_end,
               summary_type, severity, confidence, title, summary,
               evidence_json, source_refs_json, source_refs_hash,
               caveats_json, why_strengthened_json,
               freshness_state, source_window_start, source_window_end,
               ai_model, ai_prompt_hash, generated_at, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                    %s, %s, %s, %s, %s, 'fresh', %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
              current_window_start = VALUES(current_window_start),
              current_window_end = VALUES(current_window_end),
              comparison_window_start = VALUES(comparison_window_start),
              comparison_window_end = VALUES(comparison_window_end),
              severity = VALUES(severity),
              confidence = VALUES(confidence),
              title = VALUES(title),
              summary = VALUES(summary),
              evidence_json = VALUES(evidence_json),
              source_refs_json = VALUES(source_refs_json),
              source_refs_hash = VALUES(source_refs_hash),
              caveats_json = VALUES(caveats_json),
              why_strengthened_json = VALUES(why_strengthened_json),
              freshness_state = 'fresh',
              source_window_start = VALUES(source_window_start),
              source_window_end = VALUES(source_window_end),
              ai_model = VALUES(ai_model),
              ai_prompt_hash = VALUES(ai_prompt_hash),
              generated_at = VALUES(generated_at)
            """,
            (
                bloc_id, window_type, cur_start, cur_end,
                cmp_start, cmp_end,
                finding["summary_type"], finding["severity"], confidence,
                finding["title"][:240], finding["summary"],
                json.dumps(finding["evidence"], default=str),
                json.dumps(refs, default=str),
                refs_hash,
                json.dumps(finding.get("caveats") or [], default=str),
                json.dumps(why_strengthened or {}, default=str),
                cur_start, cur_end,
                ai_model, ai_prompt_hash,
                now, now,
            ),
        )
        # Resolve the row id (works for both INSERT + UPDATE branches).
        cur.execute(
            """
            SELECT id FROM operational_change_summaries
             WHERE viewer_bloc_id = %s AND window_type = %s AND summary_type = %s
            """,
            (bloc_id, window_type, finding["summary_type"]),
        )
        row = cur.fetchone()
    conn.commit()
    if not row:
        return 0
    # pymysql is configured with DictCursor by default in this project,
    # but tolerate tuple-cursor too for safety.
    return int(row.get("id") if isinstance(row, dict) else row[0])


def _audit_ai_finding(
    conn: pymysql.connections.Connection,
    *,
    bloc_id: int,
    summary_id: int,
    summary_type: str,
    window_type: str,
    confidence: str,
    severity: str,
    ai_model: str | None,
    ai_prompt_hash: str | None,
) -> None:
    """Write an intel_audit_log row with actor_kind='ai' so the
    audit trail records what the AI said vs what the operator
    eventually decided on top of it. Per ADR 0013."""
    if summary_id <= 0:
        return
    metadata = {
        "summary_type": summary_type,
        "window_type": window_type,
        "confidence": confidence,
        "severity": severity,
        "ai_model": ai_model,
        "ai_prompt_hash": ai_prompt_hash,
        "pipeline": "phase17-what-changed",
    }
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO intel_audit_log
              (actor_user_id, actor_alliance_id, actor_bloc_id, actor_kind,
               surface, surface_ref_id, action,
               prior_state_json, new_state_json, metadata_json,
               ip_address, user_agent, created_at)
            VALUES (NULL, NULL, %s, 'ai',
                    'ai_change_summary', %s, 'generate',
                    NULL, NULL, %s,
                    NULL, NULL, %s)
            """,
            (
                bloc_id, summary_id,
                json.dumps(metadata, default=str),
                datetime.now(timezone.utc),
            ),
        )
    conn.commit()


# ---------------------------------------------------------------
# Public entry point.
# ---------------------------------------------------------------

def run_change_synthesis(
    conn: pymysql.connections.Connection,
    cfg: Config,
    *,
    viewer_bloc_id: int,
    window_type: str,
) -> dict[str, Any]:
    """Compute deltas + persist findings for one (bloc, window).

    Idempotent — INSERT ... ON DUPLICATE KEY UPDATE on the
    (bloc, window, current_window_end, summary_type, source_refs_hash)
    key. Re-runs refresh prose without fanning out duplicates.
    """
    if window_type not in _WINDOWS:
        raise ValueError(f"unknown window_type: {window_type}")
    now = datetime.now(timezone.utc)
    cur_start, cur_end, cmp_start, cmp_end = _window_pair(window_type, now)

    log.info(
        "phase17 change synthesis starting",
        {"viewer_bloc_id": viewer_bloc_id, "window_type": window_type,
         "current_window": [cur_start.isoformat(), cur_end.isoformat()]},
    )

    deltas: dict[str, dict[str, Any]] = {}
    for name, fn in [
        ("incidents", lambda: _delta_incidents(conn, viewer_bloc_id, cur_start, cur_end, cmp_start, cmp_end)),
        ("alerts", lambda: _delta_alerts(conn, viewer_bloc_id, cur_start, cur_end, cmp_start, cmp_end)),
        ("corridors", lambda: _delta_corridors(conn, viewer_bloc_id, cur_start, cur_end, cmp_start, cmp_end)),
        ("threat_surface", lambda: _delta_threat_surface(conn, viewer_bloc_id, cur_end, cmp_end)),
        ("doctrine_evolution", lambda: _delta_doctrine_evolution(conn, viewer_bloc_id, cur_start, cur_end)),
    ]:
        try:
            d = fn()
            if d:
                deltas[name] = d
        except Exception as exc:
            log.warning("phase17 surface delta failed", {"surface": name, "error": str(exc)})

    corroboration = _count_corroboration(deltas)
    confidence = _confidence_from_corroboration(corroboration)

    findings: list[dict[str, Any]] = []
    if "incidents" in deltas:
        f = _synth_incidents(deltas["incidents"], window_type)
        if f:
            findings.append(f)
    if "alerts" in deltas:
        f = _synth_alerts(deltas["alerts"], window_type)
        if f:
            findings.append(f)
    if "corridors" in deltas:
        f = _synth_corridors(deltas["corridors"], window_type)
        if f:
            findings.append(f)
    if "threat_surface" in deltas:
        f = _synth_threat_surface(deltas["threat_surface"], window_type)
        if f:
            findings.append(f)
    if "doctrine_evolution" in deltas:
        f = _synth_doctrine_evolution(deltas["doctrine_evolution"], window_type)
        if f:
            findings.append(f)

    written = 0
    ai_model = "rule_based_v1"  # LLM rewrite is a follow-up; first ship is deterministic
    for finding in findings:
        why = _why_strengthened(
            conn, viewer_bloc_id, window_type, finding["summary_type"], finding["evidence"],
        )
        summary_id = _persist_finding(
            conn,
            bloc_id=viewer_bloc_id,
            window_type=window_type,
            cur_start=cur_start, cur_end=cur_end,
            cmp_start=cmp_start, cmp_end=cmp_end,
            finding=finding,
            confidence=confidence,
            why_strengthened=why,
            ai_model=ai_model,
            ai_prompt_hash=None,
        )
        _audit_ai_finding(
            conn,
            bloc_id=viewer_bloc_id,
            summary_id=summary_id,
            summary_type=finding["summary_type"],
            window_type=window_type,
            confidence=confidence,
            severity=finding["severity"],
            ai_model=ai_model,
            ai_prompt_hash=None,
        )
        written += 1

    log.info(
        "phase17 change synthesis complete",
        {"viewer_bloc_id": viewer_bloc_id, "window_type": window_type,
         "deltas_observed": list(deltas.keys()),
         "findings_written": written, "confidence": confidence},
    )
    return {
        "deltas": list(deltas.keys()),
        "findings_written": written,
        "confidence": confidence,
        "corroboration": corroboration,
    }
