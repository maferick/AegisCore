"""Phase 4.9E.1 — quality guards.

Eight detectors that scan recent platform state and emit
system_quality_events when thresholds breach. Severity bands:

  info        notable but expected
  warning     needs attention this week
  elevated    needs attention this shift
  critical    needs attention now

All detectors are read-only with respect to source surfaces.
Each detector scopes its findings to a (window_start, window_end)
so the unique key (detector, viewer_bloc_id, window_start,
window_end) prevents duplicate events.
"""

from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase49e_quality_guards")


def run_quality_guards(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int | None = None,
) -> dict:
    """Run every detector against current platform state. Returns
    counts per detector.

    When viewer_bloc_id is None, the detectors that are bloc-scoped
    walk every bloc; cross-cutting detectors (parser_drift,
    unknown_event_spike) ignore bloc and use NULL."""
    log.info("phase4.9E quality guards starting", {"viewer_bloc_id": viewer_bloc_id})
    counts: dict[str, int] = {}

    blocs = _resolve_blocs(conn, viewer_bloc_id)

    for bloc_id in blocs:
        counts.setdefault("incident_explosion", 0)
        counts["incident_explosion"] += _detect_incident_explosion(conn, bloc_id)
        counts.setdefault("corridor_explosion", 0)
        counts["corridor_explosion"] += _detect_corridor_explosion(conn, bloc_id)
        counts.setdefault("doctrine_mismatch_explosion", 0)
        counts["doctrine_mismatch_explosion"] += _detect_doctrine_mismatch_explosion(conn, bloc_id)
        counts.setdefault("impossible_fleet_size", 0)
        counts["impossible_fleet_size"] += _detect_impossible_fleet_size(conn, bloc_id)
        counts.setdefault("duplicate_narrative_loop", 0)
        counts["duplicate_narrative_loop"] += _detect_duplicate_narrative_loop(conn, bloc_id)
        counts.setdefault("stale_compute_chain", 0)
        counts["stale_compute_chain"] += _detect_stale_compute_chain(conn, bloc_id)

    # Cross-cutting (no bloc scope).
    counts["parser_drift_split"] = _detect_parser_drift(conn)
    counts["unknown_event_spike"] = _detect_unknown_event_spike(conn)

    conn.commit()
    log.info("phase4.9E quality guards done", counts)
    return counts


def _resolve_blocs(conn, viewer_bloc_id):
    if viewer_bloc_id is not None:
        return [int(viewer_bloc_id)]
    with conn.cursor() as cur:
        cur.execute("SELECT id FROM coalition_blocs WHERE is_active=1")
        return [int(r["id"]) for r in cur.fetchall()]


# Detectors -----------------------------------------------------------

def _detect_incident_explosion(conn, bloc_id) -> int:
    """Trigger if 24h incident count > 4× rolling 7-day daily mean.

    Severity:
      warning   ≥3× mean
      elevated  ≥4×
      critical  ≥6×
    """
    end = datetime.now(timezone.utc)
    last_24h_start = end - timedelta(hours=24)
    week_start = end - timedelta(days=7)

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT
              SUM(start_at >= %s) AS recent_24h,
              COUNT(*) AS total_7d
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND start_at >= %s
            """,
            (last_24h_start, bloc_id, week_start),
        )
        row = cur.fetchone() or {}
    recent = int(row.get("recent_24h") or 0)
    total = int(row.get("total_7d") or 0)
    daily_mean = max(1.0, (total - recent) / 6.0)
    if recent < 3 * daily_mean or recent < 50:
        return 0
    ratio = recent / daily_mean
    severity = "critical" if ratio >= 6 else "elevated" if ratio >= 4 else "warning"
    return _persist_event(
        conn, bloc_id, "incident_explosion", severity,
        last_24h_start, end,
        f"Incident rate spike: {recent} in 24h vs {daily_mean:.1f}/d baseline",
        f"Last 24h saw {recent} incidents — {ratio:.1f}× the rolling 7-day daily mean.",
        recent, daily_mean,
        {"recent_24h": recent, "daily_mean_prior_6d": round(daily_mean, 2)},
    )


def _detect_corridor_explosion(conn, bloc_id) -> int:
    """Trigger when new corridors created in 7d > 5× the prior-7d count.

    Severity warning ≥5×, elevated ≥10×, critical ≥20×.
    """
    end = datetime.now(timezone.utc)
    cur_start = end - timedelta(days=7)
    prev_start = end - timedelta(days=14)
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT
              SUM(first_seen_at >= %s) AS cur_7d,
              SUM(first_seen_at >= %s AND first_seen_at < %s) AS prev_7d
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
            """,
            (cur_start, prev_start, cur_start, bloc_id),
        )
        row = cur.fetchone() or {}
    cur_n = int(row.get("cur_7d") or 0)
    prev_n = max(1, int(row.get("prev_7d") or 0))
    if cur_n < 50 or cur_n < 5 * prev_n:
        return 0
    ratio = cur_n / prev_n
    severity = "critical" if ratio >= 20 else "elevated" if ratio >= 10 else "warning"
    return _persist_event(
        conn, bloc_id, "corridor_explosion", severity,
        cur_start, end,
        f"Corridor explosion: {cur_n} new corridors in 7d vs {prev_n} prior-7d",
        f"New-corridor rate is {ratio:.1f}× the prior-7d baseline. Suggests bulk traffic ingest or a parser regression — verify before acting on corridor signals.",
        cur_n, prev_n,
        {"current_7d": cur_n, "prior_7d": prev_n},
    )


def _detect_doctrine_mismatch_explosion(conn, bloc_id) -> int:
    """Trigger when ≥30% of recent force compositions report
    doctrine_match_pct < 0.30 — suggests doctrine library drift."""
    end = datetime.now(timezone.utc)
    start = end - timedelta(days=14)
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT
              COUNT(*) AS total,
              SUM(doctrine_match_pct < 0.30 OR primary_doctrine_id IS NULL) AS missed
              FROM operational_force_compositions
             WHERE viewer_bloc_id = %s
               AND snapshot_at >= %s
            """,
            (bloc_id, start),
        )
        row = cur.fetchone() or {}
    total = int(row.get("total") or 0)
    missed = int(row.get("missed") or 0)
    if total < 10:
        return 0
    rate = missed / total
    if rate < 0.30:
        return 0
    severity = "critical" if rate >= 0.60 else "elevated" if rate >= 0.45 else "warning"
    return _persist_event(
        conn, bloc_id, "doctrine_mismatch_explosion", severity,
        start, end,
        f"Doctrine mismatch rate {rate:.0%} over last 14d ({missed}/{total})",
        "Force compositions are not finding doctrines they recognise. Check auto_doctrines coverage or recent meta shift.",
        missed, total,
        {"missed": missed, "total": total, "rate": round(rate, 4)},
    )


def _detect_impossible_fleet_size(conn, bloc_id) -> int:
    """Trigger on any composition reporting >2500 ships (ESI cap is
    ~2000/system, dscan rarely > 2000). Treat as parser regression
    or duplicated rows."""
    end = datetime.now(timezone.utc)
    start = end - timedelta(days=2)
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n, MAX(ship_total) AS max_ships
              FROM operational_force_compositions
             WHERE viewer_bloc_id = %s
               AND snapshot_at >= %s
               AND ship_total > 2500
            """,
            (bloc_id, start),
        )
        row = cur.fetchone() or {}
    n = int(row.get("n") or 0)
    max_ships = int(row.get("max_ships") or 0)
    if n == 0:
        return 0
    severity = "critical" if max_ships >= 5000 else "elevated"
    return _persist_event(
        conn, bloc_id, "impossible_fleet_size", severity,
        start, end,
        f"Impossible fleet size detected: max {max_ships} ships",
        f"{n} composition(s) report >2500 ships in last 48h. Likely dscan parser regression or duplicate row insert.",
        max_ships, 2500,
        {"count": n, "max_ships": max_ships},
    )


def _detect_duplicate_narrative_loop(conn, bloc_id) -> int:
    """Trigger when ≥10 narratives share identical body in 24h."""
    end = datetime.now(timezone.utc)
    start = end - timedelta(hours=24)
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT MD5(narrative_md) AS h, COUNT(*) AS n
              FROM incident_narratives
             WHERE viewer_bloc_id = %s
               AND computed_at >= %s
             GROUP BY MD5(narrative_md)
            HAVING n >= 10
             ORDER BY n DESC
             LIMIT 1
            """,
            (bloc_id, start),
        )
        row = cur.fetchone()
    if row is None:
        return 0
    n = int(row["n"])
    severity = "critical" if n >= 50 else "elevated" if n >= 25 else "warning"
    return _persist_event(
        conn, bloc_id, "duplicate_narrative_loop", severity,
        start, end,
        f"Duplicate narrative loop: same body produced {n} times",
        "The narrative generator is producing identical output. Likely template fall-through; check renderer.",
        n, 10,
        {"hash_count": n},
    )


def _detect_stale_compute_chain(conn, bloc_id) -> int:
    """Trigger when the most recent compute_run_log row for any
    flagship pipeline is >36h old (TTL chosen to outlive a missed
    daily run but flag a true gap)."""
    threshold = datetime.now(timezone.utc) - timedelta(hours=36)
    flagship = [
        "phase4-threat-surface",
        "phase47-daily-digest",
        "phase47-strategic-alerts",
        "phase47-incident-narratives",
        "phase49-freshness",
    ]
    written = 0
    for pipeline in flagship:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT MAX(compute_started_at) AS latest
                  FROM compute_run_log
                 WHERE pipeline = %s
                   AND (viewer_bloc_id = %s OR viewer_bloc_id IS NULL)
                """,
                (pipeline, bloc_id),
            )
            row = cur.fetchone() or {}
        latest = row.get("latest")
        if latest is None:
            # never run → not stale, just unseen. Skip.
            continue
        if latest.replace(tzinfo=timezone.utc) >= threshold:
            continue
        age_h = int((datetime.now(timezone.utc) - latest.replace(tzinfo=timezone.utc)).total_seconds() / 3600)
        severity = "critical" if age_h >= 96 else "elevated" if age_h >= 60 else "warning"
        written += _persist_event(
            conn, bloc_id, "stale_compute_chain", severity,
            latest.replace(tzinfo=timezone.utc), datetime.now(timezone.utc),
            f"Stale compute: {pipeline} hasn't run in {age_h}h",
            f"Last run of {pipeline} was {age_h}h ago. Downstream surfaces will age out without re-runs.",
            age_h, 36,
            {"pipeline": pipeline, "age_hours": age_h, "latest_run": str(latest)},
        )
    return written


def _detect_parser_drift(conn) -> int:
    """Cross-cutting: split into current_parser_drift (open errors)
    vs historical_parser_backlog (already retried/dismissed).

    Only `current_parser_drift` is allowed to escalate beyond
    `info` — historical backlog is informational because operators
    have already triaged it.
    """
    end = datetime.now(timezone.utc)
    start = end - timedelta(hours=24)

    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) AS n FROM eve_log_events WHERE event_timestamp >= %s",
                (start,),
            )
            total = int((cur.fetchone() or {}).get("n") or 0)
            cur.execute(
                "SELECT COUNT(*) AS n FROM eve_log_parse_errors "
                "WHERE created_at >= %s AND status = 'open'",
                (start,),
            )
            open_errors = int((cur.fetchone() or {}).get("n") or 0)
            cur.execute(
                "SELECT COUNT(*) AS n FROM eve_log_parse_errors "
                "WHERE created_at >= %s AND status IN ('retried','dismissed','reparsed_ok')",
                (start,),
            )
            historical = int((cur.fetchone() or {}).get("n") or 0)
    except pymysql.err.ProgrammingError:
        return 0

    written = 0

    # Current drift — only `open` errors. Floor 100 events.
    if total >= 100:
        rate = open_errors / total if total else 0.0
        if rate >= 0.05:
            severity = "critical" if rate >= 0.20 else "elevated" if rate >= 0.10 else "warning"
            written += _persist_event(
                conn, None, "current_parser_drift", severity,
                start, end,
                f"Current parser drift: {rate:.1%} open-error rate ({open_errors}/{total} in 24h)",
                "Above 5% open-error rate means an upstream EVE chat/log change broke a regex. Status='open' parse errors haven't been retried — investigate before more accumulate.",
                open_errors, total,
                {"open_errors": open_errors, "total_events": total, "rate": round(rate, 4)},
            )

    # Historical backlog — informational. Triggers when retried/
    # dismissed/reparsed_ok rows in 24h exceed 10× successful events
    # (signals a recent bulk replay rather than ongoing drift).
    if total >= 100 and historical >= 10 * total:
        written += _persist_event(
            conn, None, "historical_parser_backlog", "info",
            start, end,
            f"Historical parser backlog visible: {historical} retried/dismissed errors in 24h",
            f"{historical} previously-failed parse rows now in retried/dismissed/reparsed_ok status. Backlog already triaged — informational only.",
            historical, total,
            {"historical": historical, "total_events": total},
        )
    return written


def _detect_unknown_event_spike(conn) -> int:
    """Cross-cutting: trigger when the share of event_type='unknown'
    rows in the last 24h exceeds 8%."""
    end = datetime.now(timezone.utc)
    start = end - timedelta(hours=24)
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  SUM(event_type = 'unknown') AS unk,
                  COUNT(*) AS total
                  FROM eve_log_events
                 WHERE event_timestamp >= %s
                """,
                (start,),
            )
            row = cur.fetchone() or {}
    except pymysql.err.ProgrammingError:
        return 0
    unk = int(row.get("unk") or 0)
    total = int(row.get("total") or 0)
    if total < 200:
        return 0
    rate = unk / total
    if rate < 0.08:
        return 0
    severity = "critical" if rate >= 0.25 else "elevated" if rate >= 0.15 else "warning"
    return _persist_event(
        conn, None, "unknown_event_spike", severity,
        start, end,
        f"Unknown-event spike: {rate:.1%} of last-24h events untagged",
        "An above-baseline share of parsed events ended up event_type=unknown. Likely a new EVE log line variant the classifier doesn't recognise.",
        unk, total,
        {"unknown": unk, "total": total, "rate": round(rate, 4)},
    )


# Persist helper -------------------------------------------------------

def _persist_event(
    conn, bloc_id, detector, severity,
    window_start, window_end,
    title, summary,
    metric_value, threshold_value,
    evidence,
) -> int:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO system_quality_events
              (viewer_bloc_id, detector, severity, detected_at,
               window_start, window_end, title, summary,
               metric_value, threshold_value, evidence_json)
            VALUES (%s, %s, %s, NOW(), %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                title = VALUES(title),
                summary = VALUES(summary),
                metric_value = VALUES(metric_value),
                threshold_value = VALUES(threshold_value),
                evidence_json = VALUES(evidence_json),
                updated_at = NOW()
            """,
            (
                bloc_id, detector, severity,
                window_start, window_end,
                title[:220], summary[:600] if summary else None,
                metric_value if metric_value is not None else None,
                threshold_value if threshold_value is not None else None,
                json.dumps(evidence, default=str),
            ),
        )
    return 1
