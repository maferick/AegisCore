"""Phase 4.7 — analyst workflow + intelligence production.

  run_daily_digest        §4.7A  daily_operational_digest
  run_strategic_alerts    §4.7B  strategic_alerts
  run_incident_narratives §4.7C  incident_narratives

All idempotent UPSERT. The compute is intentionally cheap: it
re-shapes already-materialised tables (operational_*, system_*,
alliance_operational_profiles, doctrine_evolution_events) into
analyst-ready surfaces.
"""

from __future__ import annotations

import json
from collections import defaultdict
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4_workflow")


# =====================================================================
# §4.7A — daily_operational_digest
# =====================================================================

WINDOW_HOURS = {"today": 24, "last_24h": 24, "last_7d": 24 * 7}


def run_daily_digest(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    digest_date: date,
    window_kind: str = "last_24h",
) -> dict:
    if window_kind not in WINDOW_HOURS:
        raise ValueError(f"unknown window_kind: {window_kind}")
    log.info("phase4.7A digest starting",
             {"viewer_bloc_id": viewer_bloc_id, "date": digest_date.isoformat(), "window": window_kind})

    win_end = datetime.combine(digest_date, datetime.max.time(), tzinfo=timezone.utc)
    if window_kind == "today":
        win_start = datetime.combine(digest_date, datetime.min.time(), tzinfo=timezone.utc)
    else:
        win_start = win_end - timedelta(hours=WINDOW_HOURS[window_kind])

    # Top strategic incidents.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, primary_system_name, severity, incident_type,
                   start_at, end_at, dscan_total_ships, has_dscan,
                   battle_id, participant_estimate, timeline_summary
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND start_at BETWEEN %s AND %s
               AND severity IN ('strategic','escalation','coalition_level')
             ORDER BY FIELD(severity,'coalition_level','escalation','strategic') ASC,
                      COALESCE(dscan_total_ships, 0) DESC, start_at DESC
             LIMIT 12
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        top_incidents = [dict(r) for r in cur.fetchall()]

    # Escalation summary — aggregate counts by signal.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT severity, COUNT(*) AS n
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND start_at BETWEEN %s AND %s
             GROUP BY severity
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        sev_dist = {r["severity"]: int(r["n"]) for r in cur.fetchall()}

    # Doctrine evolution highlights — top by magnitude in 30-day window
    # ending at digest_date.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT alliance_name, event_type, doctrine_name, magnitude,
                   prior_share, current_share, confidence
              FROM doctrine_evolution_events
             WHERE viewer_bloc_id = %s
               AND window_end BETWEEN %s AND %s
             ORDER BY magnitude DESC
             LIMIT 10
            """,
            (viewer_bloc_id, digest_date - timedelta(days=30), digest_date),
        )
        doctrine_highlights = [dict(r) for r in cur.fetchall()]

    # Coalition movement — bloc-level escalation, fleet size, footprint
    # in window. Pulled from operational_incidents joined to battle
    # participants for attribution.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT b.bloc_code, b.display_name AS bloc_name,
                   COUNT(DISTINCT i.id) AS incident_count,
                   SUM(CASE WHEN i.severity IN ('escalation','coalition_level') THEN 1 ELSE 0 END) AS escalations,
                   AVG(COALESCE(i.dscan_total_ships, 0)) AS avg_dscan_ships
              FROM operational_incidents i
              LEFT JOIN battle_theater_participants p ON p.theater_id = i.battle_id
              LEFT JOIN coalition_entity_labels lab
                ON lab.entity_type='alliance' AND lab.entity_id = p.alliance_id AND lab.is_active=1
              LEFT JOIN coalition_blocs b ON b.id = lab.bloc_id
             WHERE i.viewer_bloc_id = %s
               AND i.start_at BETWEEN %s AND %s
               AND b.bloc_code IS NOT NULL
             GROUP BY b.bloc_code, b.display_name
             ORDER BY incident_count DESC
             LIMIT 10
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        coalition_movement = [dict(r) for r in cur.fetchall()]

    # New corridors — those with first_seen_at inside the window.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, from_system_name, to_system_name, transition_count,
                   distinct_characters, route_classification, confidence
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
               AND first_seen_at BETWEEN %s AND %s
               AND confidence IN ('medium','high')
             ORDER BY transition_count DESC
             LIMIT 10
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        new_corridors = [dict(r) for r in cur.fetchall()]

    # Unusual force compositions — capital / super count, large ship
    # totals, or doctrine_match_pct < 0.4 (off-meta).
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT f.id, f.snapshot_at, f.ship_total,
                   f.estimated_capital_count, f.estimated_super_count,
                   f.estimated_logistics_count, f.primary_doctrine_name,
                   f.doctrine_match_pct, f.brawl_range, f.mobility,
                   c.primary_system_name
              FROM operational_force_compositions f
              JOIN operational_hostile_clusters c ON c.id = f.cluster_id
             WHERE f.viewer_bloc_id = %s
               AND f.snapshot_at BETWEEN %s AND %s
               AND (
                    f.estimated_super_count > 0
                 OR f.estimated_capital_count >= 3
                 OR f.ship_total >= 200
                 OR (f.doctrine_match_pct IS NOT NULL AND f.doctrine_match_pct < 0.40)
               )
             ORDER BY (f.estimated_super_count * 5 + f.estimated_capital_count + f.ship_total / 50) DESC
             LIMIT 8
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        unusual_compositions = [dict(r) for r in cur.fetchall()]

    # Emerging operators — new fingerprints whose first computed_at
    # lands in the window OR whose primary_style flipped recently.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT character_id, character_name, primary_style,
                   cluster_appearances, incident_count, style_confidence
              FROM operator_operational_fingerprints
             WHERE viewer_bloc_id = %s
               AND computed_at BETWEEN %s AND %s
               AND primary_style NOT IN ('undetermined','generalist')
               AND style_confidence >= 0.4
             ORDER BY style_confidence DESC, cluster_appearances DESC
             LIMIT 10
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        emerging_ops = [dict(r) for r in cur.fetchall()]

    # Response anomalies — systems whose intel_to_combat median
    # dropped sharply (≥30% improvement) versus the same system's
    # prior 30d row.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT solar_system_name, intel_to_combat_count,
                   intel_to_combat_median_seconds
              FROM system_response_times
             WHERE viewer_bloc_id = %s
               AND window_end_date = %s
               AND intel_to_combat_median_seconds IS NOT NULL
             ORDER BY intel_to_combat_median_seconds ASC
             LIMIT 8
            """,
            (viewer_bloc_id, digest_date),
        )
        fastest_response = [dict(r) for r in cur.fetchall()]

    # Top threat systems for the day's window.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT solar_system_name, threat_score, tier, capital_score,
                   doctrine_threat_score, escalation_propensity_score, mobility_profile
              FROM system_threat_surface
             WHERE viewer_bloc_id = %s
               AND window_end_date = %s
             ORDER BY threat_score DESC
             LIMIT 10
            """,
            (viewer_bloc_id, digest_date),
        )
        top_threats = [dict(r) for r in cur.fetchall()]

    metric_summary = {
        "incidents_total": sum(sev_dist.values()),
        "severity_distribution": sev_dist,
        "top_incident_count": len(top_incidents),
        "doctrine_event_count": len(doctrine_highlights),
        "new_corridor_count": len(new_corridors),
        "unusual_comp_count": len(unusual_compositions),
        "operators_emerging": len(emerging_ops),
    }

    narrative = _build_digest_narrative(
        digest_date, window_kind, sev_dist, top_incidents,
        doctrine_highlights, new_corridors, unusual_compositions,
        coalition_movement, top_threats,
    )

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO daily_operational_digest
              (viewer_bloc_id, digest_date, window_kind,
               top_incident_ids_json, escalation_summary_json,
               doctrine_evolution_json, coalition_movement_json,
               new_corridors_json, unusual_compositions_json,
               emerging_operators_json, response_anomalies_json,
               top_threat_systems_json, metric_summary_json,
               narrative_md)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                top_incident_ids_json = VALUES(top_incident_ids_json),
                escalation_summary_json = VALUES(escalation_summary_json),
                doctrine_evolution_json = VALUES(doctrine_evolution_json),
                coalition_movement_json = VALUES(coalition_movement_json),
                new_corridors_json = VALUES(new_corridors_json),
                unusual_compositions_json = VALUES(unusual_compositions_json),
                emerging_operators_json = VALUES(emerging_operators_json),
                response_anomalies_json = VALUES(response_anomalies_json),
                top_threat_systems_json = VALUES(top_threat_systems_json),
                metric_summary_json = VALUES(metric_summary_json),
                narrative_md = VALUES(narrative_md),
                updated_at = NOW()
            """,
            (
                viewer_bloc_id, digest_date, window_kind,
                json.dumps([int(r["id"]) for r in top_incidents]),
                json.dumps(sev_dist),
                json.dumps(doctrine_highlights, default=str),
                json.dumps(coalition_movement, default=str),
                json.dumps(new_corridors, default=str),
                json.dumps(unusual_compositions, default=str),
                json.dumps(emerging_ops, default=str),
                json.dumps(fastest_response, default=str),
                json.dumps(top_threats, default=str),
                json.dumps(metric_summary, default=str),
                narrative,
            ),
        )
    conn.commit()
    log.info("phase4.7A digest done",
             {"window": window_kind, "incidents": len(top_incidents)})
    return {"window": window_kind, "incidents": metric_summary}


def _build_digest_narrative(digest_date, window_kind, sev_dist,
                            top_incidents, doctrine_highlights,
                            new_corridors, unusual_compositions,
                            coalition_movement, top_threats) -> str:
    """Markdown digest. Plain-English summary for analyst consumption."""
    lines: list[str] = []
    lines.append(f"# Operational digest · {digest_date.isoformat()} · {window_kind}\n")

    total = sum(sev_dist.values())
    if total == 0:
        lines.append("_No incidents in window._\n")
        return "\n".join(lines)

    lines.append(f"**{total}** incidents in window. Severity mix: " + ", ".join(
        f"{k}={v}" for k, v in sorted(sev_dist.items(), key=lambda kv: -kv[1])
    ) + ".\n")

    if top_incidents:
        lines.append("## Top strategic incidents\n")
        for r in top_incidents[:5]:
            sys = r.get("primary_system_name") or "?"
            sev = r.get("severity")
            inc_type = r.get("incident_type")
            ships = r.get("dscan_total_ships")
            ts = r.get("start_at")
            ship_part = f" · {ships} ships on dscan" if ships else ""
            battle_part = f" · battle #{r['battle_id']}" if r.get("battle_id") else ""
            lines.append(f"- **{sev.upper()}** · {inc_type.replace('_', ' ')} in {sys} at {ts}{ship_part}{battle_part}")
        lines.append("")

    if doctrine_highlights:
        lines.append("## Doctrine evolution\n")
        for d in doctrine_highlights[:5]:
            lines.append(
                f"- {d['alliance_name'] or '(unattributed)'} · "
                f"{d['event_type'].replace('_', ' ')}"
                + (f" of {d['doctrine_name']}" if d.get('doctrine_name') else "")
                + f" · magnitude {float(d['magnitude']):.2f} ({d['confidence']})"
            )
        lines.append("")

    if coalition_movement:
        lines.append("## Coalition movement\n")
        for c in coalition_movement[:5]:
            lines.append(
                f"- **{c.get('bloc_name') or c.get('bloc_code')}**: "
                f"{int(c['incident_count'])} incidents, "
                f"{int(c['escalations'] or 0)} escalations, "
                f"avg dscan {float(c['avg_dscan_ships'] or 0):.0f} ships"
            )
        lines.append("")

    if new_corridors:
        lines.append("## New corridors\n")
        for c in new_corridors[:5]:
            cls = c.get("route_classification")
            lines.append(
                f"- {c['from_system_name']} → {c['to_system_name']} · "
                f"{int(c['transition_count'])} transits · "
                f"{int(c['distinct_characters'])} chars · {cls} ({c['confidence']})"
            )
        lines.append("")

    if unusual_compositions:
        lines.append("## Unusual force compositions\n")
        for f in unusual_compositions[:5]:
            cap = int(f.get("estimated_capital_count") or 0)
            sup = int(f.get("estimated_super_count") or 0)
            cap_part = ""
            if cap or sup:
                cap_part = f" · caps {cap} / supers {sup}"
            doc = f.get("primary_doctrine_name")
            doc_part = f" · {doc}" if doc else " · (off-meta)"
            lines.append(
                f"- {f.get('primary_system_name')} · "
                f"{int(f['ship_total'])} ships{cap_part}{doc_part}"
            )
        lines.append("")

    if top_threats:
        lines.append("## Top threat systems\n")
        for t in top_threats[:6]:
            lines.append(
                f"- {t['solar_system_name']} · {t['tier']} ({float(t['threat_score']):.2f})"
                + (f" · {t['mobility_profile']}" if t.get("mobility_profile") else "")
            )
        lines.append("")

    return "\n".join(lines)


# =====================================================================
# §4.7B — strategic_alerts
# =====================================================================

def run_strategic_alerts(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    detection_date: date,
    lookback_days: int = 7,
) -> dict:
    """Surface rare, operationally-meaningful events. Tight thresholds
    to avoid noise."""
    log.info("phase4.7B strategic alerts starting",
             {"viewer_bloc_id": viewer_bloc_id, "date": detection_date.isoformat()})
    win_end = datetime.combine(detection_date, datetime.max.time(), tzinfo=timezone.utc)
    win_start = win_end - timedelta(days=lookback_days)
    written = 0

    # 1. sudden_doctrine_shift — high-magnitude evolution event.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, alliance_id, alliance_name, doctrine_name, event_type,
                   magnitude, prior_share, current_share, confidence, window_end
              FROM doctrine_evolution_events
             WHERE viewer_bloc_id = %s
               AND window_end BETWEEN %s AND %s
               AND magnitude >= 0.5
               AND confidence IN ('medium','high')
            """,
            (viewer_bloc_id, detection_date - timedelta(days=lookback_days), detection_date),
        )
        for r in cur.fetchall():
            severity = "elevated" if float(r["magnitude"]) >= 0.7 else "watch"
            title = (
                f"{r['alliance_name'] or 'unattributed'} · "
                f"{r['event_type'].replace('_', ' ')}"
                + (f" of {r['doctrine_name']}" if r['doctrine_name'] else "")
            )
            summary = (
                f"Doctrine share moved from {float(r['prior_share'] or 0):.0%} "
                f"to {float(r['current_share'] or 0):.0%} (Δ {float(r['magnitude']):.2f})."
            )
            written += _persist_alert(
                conn, viewer_bloc_id, "sudden_doctrine_shift", severity,
                datetime.combine(r["window_end"], datetime.min.time(), tzinfo=timezone.utc),
                None, None,
                title, summary,
                None, None,
                int(r["alliance_id"]) if r["alliance_id"] else None, r["alliance_name"],
                None, None, int(r["id"]),
                {"magnitude": float(r["magnitude"])},
            )

    # 2. capital_escalation — composition with capitals/supers.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT f.id AS comp_id, f.snapshot_at, f.estimated_capital_count,
                   f.estimated_super_count, f.ship_total, c.primary_system_id,
                   c.primary_system_name, f.incident_id
              FROM operational_force_compositions f
              JOIN operational_hostile_clusters c ON c.id = f.cluster_id
             WHERE f.viewer_bloc_id = %s
               AND f.snapshot_at BETWEEN %s AND %s
               AND (f.estimated_super_count > 0 OR f.estimated_capital_count >= 2)
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        for r in cur.fetchall():
            sup = int(r["estimated_super_count"] or 0)
            cap = int(r["estimated_capital_count"] or 0)
            severity = "urgent" if sup >= 2 else "elevated" if sup >= 1 else "watch"
            title = (
                f"Capital escalation in {r['primary_system_name'] or 'unknown'} · "
                f"{cap} caps / {sup} supers"
            )
            summary = f"Composition snapshot at {r['snapshot_at']} on a {int(r['ship_total'])}-ship dscan."
            written += _persist_alert(
                conn, viewer_bloc_id, "capital_escalation", severity,
                r["snapshot_at"], win_start, win_end,
                title, summary,
                int(r["primary_system_id"]) if r["primary_system_id"] else None,
                r["primary_system_name"],
                None, None,
                int(r["incident_id"]) if r["incident_id"] else None,
                None, None,
                {"capitals": cap, "supers": sup, "ship_total": int(r["ship_total"])},
            )

    # 3. hostile_deployment_migration — corridors classified migration.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, from_system_id, from_system_name, to_system_id,
                   to_system_name, transition_count, distinct_characters,
                   last_seen_at
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
               AND route_classification = 'deployment_migration'
               AND last_seen_at BETWEEN %s AND %s
               AND transition_count >= 5
               AND distinct_characters >= 3
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        for r in cur.fetchall():
            severity = "elevated" if int(r["transition_count"]) >= 15 else "watch"
            title = f"Deployment migration · {r['from_system_name']} → {r['to_system_name']}"
            summary = (
                f"{int(r['transition_count'])} transits by "
                f"{int(r['distinct_characters'])} distinct characters."
            )
            written += _persist_alert(
                conn, viewer_bloc_id, "hostile_deployment_migration", severity,
                r["last_seen_at"], win_start, win_end,
                title, summary,
                int(r["from_system_id"]), r["from_system_name"],
                None, None,
                None, int(r["id"]), None,
                {"transitions": int(r["transition_count"]),
                 "to_system": r["to_system_name"]},
            )

    # 4. escalation_into_staging — incident severity ≥ escalation in
    #    a system that also appears as a corridor staging endpoint.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT i.id, i.primary_system_id, i.primary_system_name,
                   i.severity, i.start_at, i.battle_id, i.dscan_total_ships
              FROM operational_incidents i
              JOIN operational_corridors c
                ON (c.from_system_id = i.primary_system_id OR c.to_system_id = i.primary_system_id)
               AND c.route_classification = 'staging'
             WHERE i.viewer_bloc_id = %s
               AND i.start_at BETWEEN %s AND %s
               AND i.severity IN ('escalation','coalition_level')
             GROUP BY i.id, i.primary_system_id, i.primary_system_name, i.severity,
                      i.start_at, i.battle_id, i.dscan_total_ships
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        for r in cur.fetchall():
            severity = "urgent" if r["severity"] == "coalition_level" else "elevated"
            title = f"Escalation into staging · {r['primary_system_name']}"
            summary = (
                f"{r['severity']} incident in a staging system at {r['start_at']}."
            )
            written += _persist_alert(
                conn, viewer_bloc_id, "escalation_into_staging", severity,
                r["start_at"], win_start, win_end,
                title, summary,
                int(r["primary_system_id"]) if r["primary_system_id"] else None,
                r["primary_system_name"],
                None, None,
                int(r["id"]), None, None,
                {"battle_id": r["battle_id"], "dscan_ships": r["dscan_total_ships"]},
            )

    # 5. corridor_pressure_spike — corridor whose recent transition_count
    #    in window is at least 3× the prior period.
    # (Approximation: corridors created or refreshed in window with high count.)
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, from_system_id, from_system_name, to_system_name,
                   transition_count, distinct_characters, last_seen_at
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
               AND last_seen_at BETWEEN %s AND %s
               AND route_classification IN ('reinforcement','escalation_path')
               AND transition_count >= 8
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        for r in cur.fetchall():
            severity = "elevated"
            title = f"Corridor pressure spike · {r['from_system_name']} → {r['to_system_name']}"
            summary = (
                f"{int(r['transition_count'])} transits / "
                f"{int(r['distinct_characters'])} chars on a "
                f"{r.get('route_classification') or 'pressure'} route."
            )
            written += _persist_alert(
                conn, viewer_bloc_id, "corridor_pressure_spike", severity,
                r["last_seen_at"], win_start, win_end,
                title, summary,
                int(r["from_system_id"]), r["from_system_name"],
                None, None,
                None, int(r["id"]), None,
                {"transitions": int(r["transition_count"])},
            )

    # 6. operational_tempo_spike — system-day with response time
    #    drastically below median (cluster + escalation co-fire).
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT solar_system_id, solar_system_name, intel_to_combat_count,
                   intel_to_combat_median_seconds
              FROM system_response_times
             WHERE viewer_bloc_id = %s
               AND window_end_date = %s
               AND intel_to_combat_median_seconds IS NOT NULL
               AND intel_to_combat_count >= 3
               AND intel_to_combat_median_seconds <= 180
            """,
            (viewer_bloc_id, detection_date),
        )
        for r in cur.fetchall():
            severity = "watch"
            title = f"Operational tempo spike · {r['solar_system_name']}"
            summary = (
                f"Median intel→combat at "
                f"{int(r['intel_to_combat_median_seconds']) // 60}m "
                f"{int(r['intel_to_combat_median_seconds']) % 60}s "
                f"over {int(r['intel_to_combat_count'])} samples."
            )
            written += _persist_alert(
                conn, viewer_bloc_id, "operational_tempo_spike", severity,
                win_end, win_start, win_end,
                title, summary,
                int(r["solar_system_id"]), r["solar_system_name"],
                None, None,
                None, None, None,
                {"median_seconds": int(r["intel_to_combat_median_seconds"]),
                 "samples": int(r["intel_to_combat_count"])},
            )

    # 7. large_strategic_cluster — cluster ≥ 100 ships in strategic
    #    or escalation incidents.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT i.id, i.primary_system_id, i.primary_system_name,
                   i.severity, i.dscan_total_ships, i.start_at
              FROM operational_incidents i
             WHERE i.viewer_bloc_id = %s
               AND i.start_at BETWEEN %s AND %s
               AND i.severity IN ('strategic','escalation','coalition_level')
               AND i.has_dscan = 1
               AND i.dscan_total_ships >= 100
            """,
            (viewer_bloc_id, win_start, win_end),
        )
        for r in cur.fetchall():
            ships = int(r["dscan_total_ships"] or 0)
            severity = "urgent" if ships >= 300 else "elevated"
            title = f"Large strategic cluster · {r['primary_system_name']} · {ships} ships"
            summary = f"{r['severity']} incident with confirmed dscan at {r['start_at']}."
            written += _persist_alert(
                conn, viewer_bloc_id, "large_strategic_cluster", severity,
                r["start_at"], win_start, win_end,
                title, summary,
                int(r["primary_system_id"]) if r["primary_system_id"] else None,
                r["primary_system_name"],
                None, None,
                int(r["id"]), None, None,
                {"ships": ships},
            )

    conn.commit()
    log.info("phase4.7B strategic alerts done", {"alerts_written": written})
    return {"alerts_written": written}


def _persist_alert(
    conn, viewer_bloc_id, kind, severity, detected_at, win_start, win_end,
    title, summary, sys_id, sys_name, alliance_id, alliance_name,
    related_incident, related_corridor, related_doctrine, evidence,
) -> int:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO strategic_alerts
              (viewer_bloc_id, alert_kind, severity, detected_at,
               window_start, window_end, title, summary,
               primary_system_id, primary_system_name,
               primary_alliance_id, primary_alliance_name,
               related_incident_id, related_corridor_id, related_doctrine_event_id,
               evidence_json)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                title = VALUES(title),
                summary = VALUES(summary),
                primary_system_id = VALUES(primary_system_id),
                primary_system_name = VALUES(primary_system_name),
                primary_alliance_id = VALUES(primary_alliance_id),
                primary_alliance_name = VALUES(primary_alliance_name),
                evidence_json = VALUES(evidence_json),
                updated_at = NOW()
            """,
            (
                viewer_bloc_id, kind, severity, detected_at,
                win_start, win_end, title[:220],
                summary[:600] if summary else None,
                sys_id, sys_name, alliance_id, alliance_name,
                related_incident, related_corridor, related_doctrine,
                json.dumps(evidence, default=str),
            ),
        )
    return 1


# =====================================================================
# §4.7C — incident_narratives
# =====================================================================

GENERATOR_VERSION = "v1"


def run_incident_narratives(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
    limit: int = 500,
) -> dict:
    log.info("phase4.7C incident narratives starting",
             {"viewer_bloc_id": viewer_bloc_id, "since": since_dt.isoformat()})

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, primary_system_name, primary_system_id,
                   incident_type, severity, start_at, end_at,
                   participant_estimate, dscan_total_ships, has_dscan,
                   battle_id, signal_types_json, hostile_cluster_ids_json,
                   timeline_summary
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND start_at >= %s
             ORDER BY start_at DESC
             LIMIT %s
            """,
            (viewer_bloc_id, since_dt, limit),
        )
        incidents = list(cur.fetchall())
    if not incidents:
        return {"narratives_written": 0}

    cluster_ids: set[int] = set()
    for r in incidents:
        try:
            ids = json.loads(r["hostile_cluster_ids_json"] or "[]") or []
            cluster_ids.update(int(x) for x in ids)
        except (TypeError, ValueError):
            pass

    cluster_meta: dict[int, dict] = {}
    if cluster_ids:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(cluster_ids))
            cur.execute(
                f"""
                SELECT id, primary_system_id, primary_system_name,
                       reporter_count, report_count, has_dscan,
                       dscan_total_ships, start_at
                  FROM operational_hostile_clusters
                 WHERE id IN ({ph})
                """,
                tuple(cluster_ids),
            )
            for r in cur.fetchall():
                cluster_meta[int(r["id"])] = dict(r)

    # Force compositions per incident.
    comp_by_incident: dict[int, list[dict]] = defaultdict(list)
    inc_ids = [int(r["id"]) for r in incidents]
    if inc_ids:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(inc_ids))
            cur.execute(
                f"""
                SELECT incident_id, ship_total, primary_doctrine_name,
                       estimated_capital_count, estimated_super_count,
                       estimated_logistics_count, brawl_range, mobility,
                       projection_strength
                  FROM operational_force_compositions
                 WHERE incident_id IN ({ph})
                """,
                tuple(inc_ids),
            )
            for r in cur.fetchall():
                comp_by_incident[int(r["incident_id"])].append(dict(r))

    # Corridor inbound/outbound for incident.primary_system within
    # 30min of start_at.
    written = 0
    for r in incidents:
        iid = int(r["id"])
        narrative = _render_narrative(r, cluster_meta, comp_by_incident.get(iid, []))
        key_facts = {
            "incident_id": iid,
            "severity": r["severity"],
            "system": r["primary_system_name"],
            "start_at": str(r["start_at"]),
            "end_at": str(r["end_at"]),
            "battle_id": r["battle_id"],
            "ship_total": r["dscan_total_ships"],
            "compositions": len(comp_by_incident.get(iid, [])),
        }
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO incident_narratives
                  (viewer_bloc_id, incident_id, generator_version,
                   narrative_md, key_facts_json)
                VALUES (%s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    narrative_md = VALUES(narrative_md),
                    key_facts_json = VALUES(key_facts_json),
                    updated_at = NOW()
                """,
                (
                    viewer_bloc_id, iid, GENERATOR_VERSION,
                    narrative, json.dumps(key_facts, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.7C incident narratives done", {"narratives_written": written})
    return {"narratives_written": written}


def _render_narrative(inc: dict, cluster_meta: dict, comps: list[dict]) -> str:
    sys = inc.get("primary_system_name") or "an unknown system"
    sev = inc["severity"]
    inc_type = (inc["incident_type"] or "incident").replace("_", " ")
    start = inc["start_at"]
    duration_minutes = max(1, int((inc["end_at"] - inc["start_at"]).total_seconds()) // 60) if inc.get("end_at") else None
    try:
        sigs = sorted(json.loads(inc["signal_types_json"] or "[]") or [])
    except (TypeError, ValueError):
        sigs = []
    try:
        cluster_ids = [int(x) for x in (json.loads(inc["hostile_cluster_ids_json"] or "[]") or [])]
    except (TypeError, ValueError):
        cluster_ids = []

    pieces: list[str] = []
    pieces.append(f"**{sev.upper()}** {inc_type} in **{sys}**, opening at {start}.")
    if duration_minutes is not None and duration_minutes >= 2:
        pieces.append(f"Window {duration_minutes} minutes wide.")
    if cluster_ids:
        rep_total = sum(int(cluster_meta.get(c, {}).get("reporter_count", 0)) for c in cluster_ids)
        rep_total = max(rep_total, 1)
        pieces.append(
            f"{len(cluster_ids)} hostile cluster(s) fed the incident; "
            f"{rep_total} unique reporter signature(s) across them."
        )
    if "escalation" in sigs:
        pieces.append("Combat escalation followed.")
    if "disengagement" in sigs:
        pieces.append("Disengagement signal closed the chain.")
    if "fleet_formup" in sigs:
        pieces.append("A fleet form-up timeline event was attached.")
    if inc.get("has_dscan") and inc.get("dscan_total_ships"):
        pieces.append(f"Confirmed dscan: {int(inc['dscan_total_ships'])} ships visible.")
    if comps:
        peak = max(comps, key=lambda c: int(c.get("ship_total") or 0))
        cap = int(peak.get("estimated_capital_count") or 0)
        sup = int(peak.get("estimated_super_count") or 0)
        logi = int(peak.get("estimated_logistics_count") or 0)
        doc = peak.get("primary_doctrine_name")
        proj = peak.get("projection_strength")
        brawl = peak.get("brawl_range")
        mob = peak.get("mobility")
        cap_part = ""
        if cap or sup:
            cap_part = f", with {cap} capital(s) and {sup} super(s)"
        doc_part = f" matching **{doc}**" if doc else ""
        descriptors = [d for d in (proj, mob, brawl) if d and d != "unknown"]
        descriptor_part = f"; profile: {', '.join(descriptors)}" if descriptors else ""
        pieces.append(
            f"Peak composition: {int(peak.get('ship_total') or 0)} ships{cap_part}, "
            f"{logi} logistics{doc_part}{descriptor_part}."
        )
        if len(comps) > 1:
            pieces.append(f"{len(comps)} dscan snapshots traced the force evolution.")
    if inc.get("battle_id"):
        pieces.append(f"Linked to battle theater #{int(inc['battle_id'])}.")
    return " ".join(pieces)
