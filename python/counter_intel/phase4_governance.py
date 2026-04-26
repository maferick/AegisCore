"""Phase 4.8 — intel governance, trust, analyst controls.

  run_alert_suppression       §4.8E  apply suppression rules to
                                     existing alerts (no new alerts).
  run_trust_metrics           §4.8G  per-surface trust ratios.
  run_enrich_digest_trust     §4.8B  add confidence/evidence/source
                                     reliability JSON to today's digests.
  run_enrich_narrative_sources §4.8C trace each narrative back to
                                     source incidents/clusters/dscan.

All idempotent. Compute reads governance tables and re-shapes; it
does not generate alerts itself (that stays in phase4_workflow).
"""

from __future__ import annotations

import json
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4_governance")


# =====================================================================
# §4.8E — auto suppression
# =====================================================================

def run_alert_suppression(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
) -> dict:
    """Apply suppression rules + heuristic noise controls to
    strategic_alerts. Idempotent — re-running marks the same set
    as suppressed. Never deletes; always sets suppressed_until +
    suppression_reason so the audit trail stays intact."""
    log.info("phase4.8E suppression starting", {"viewer_bloc_id": viewer_bloc_id})
    written = 0

    # 1. Duplicate collapse: same alert_kind + same primary_alliance
    #    OR same primary_system within rolling 6h. Keep the first
    #    (lowest id), suppress the rest.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, alert_kind, severity, primary_system_id,
                   primary_alliance_id, related_corridor_id, detected_at
              FROM strategic_alerts
             WHERE viewer_bloc_id = %s
               AND analyst_status NOT IN ('false_positive','archived')
               AND dismissed_at IS NULL
             ORDER BY detected_at ASC, id ASC
            """,
            (viewer_bloc_id,),
        )
        rows = list(cur.fetchall())

    seen: dict[tuple, datetime] = {}
    duplicates: list[int] = []
    for r in rows:
        key = (
            r["alert_kind"],
            int(r["primary_system_id"] or 0),
            int(r["primary_alliance_id"] or 0),
            int(r["related_corridor_id"] or 0),
        )
        ts = r["detected_at"]
        last = seen.get(key)
        if last is not None and (ts - last).total_seconds() < 6 * 3600:
            duplicates.append(int(r["id"]))
        else:
            seen[key] = ts

    if duplicates:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(duplicates))
            cur.execute(
                f"""
                UPDATE strategic_alerts
                   SET analyst_status = CASE WHEN analyst_status='new' THEN 'suppressed' ELSE analyst_status END,
                       suppressed_until = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                       suppression_reason = 'duplicate within 6h window'
                 WHERE id IN ({ph})
                """,
                tuple(duplicates),
            )
            written += cur.rowcount

    # 2. Corridor spam: ≥10 hostile_deployment_migration alerts on
    #    the same from_system within 7d → suppress all but the top
    #    transition_count one.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT a.id, a.primary_system_id, c.transition_count,
                   a.detected_at
              FROM strategic_alerts a
              LEFT JOIN operational_corridors c ON c.id = a.related_corridor_id
             WHERE a.viewer_bloc_id = %s
               AND a.alert_kind = 'hostile_deployment_migration'
               AND a.dismissed_at IS NULL
               AND a.suppressed_until IS NULL
               AND a.detected_at >= NOW() - INTERVAL 7 DAY
             ORDER BY a.primary_system_id, c.transition_count DESC
            """,
            (viewer_bloc_id,),
        )
        rows = list(cur.fetchall())

    by_sys: dict[int, list[dict]] = defaultdict(list)
    for r in rows:
        sid = int(r["primary_system_id"] or 0)
        if sid:
            by_sys[sid].append(r)
    spam_ids: list[int] = []
    for sid, group in by_sys.items():
        if len(group) >= 10:
            for r in group[1:]:  # keep top by transition_count
                spam_ids.append(int(r["id"]))
    if spam_ids:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(spam_ids))
            cur.execute(
                f"""
                UPDATE strategic_alerts
                   SET analyst_status = CASE WHEN analyst_status='new' THEN 'suppressed' ELSE analyst_status END,
                       suppressed_until = DATE_ADD(NOW(), INTERVAL 7 DAY),
                       suppression_reason = 'corridor spam: ≥10 alerts on same from_system in 7d'
                 WHERE id IN ({ph})
                """,
                tuple(spam_ids),
            )
            written += cur.rowcount

    # 3. Low-confidence incident filter: alerts whose related incident
    #    has confidence='insufficient' AND severity='watch' → suppress.
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE strategic_alerts a
              JOIN operational_incidents i ON i.id = a.related_incident_id
               SET a.analyst_status = CASE WHEN a.analyst_status='new' THEN 'suppressed' ELSE a.analyst_status END,
                   a.suppressed_until = DATE_ADD(NOW(), INTERVAL 14 DAY),
                   a.suppression_reason = 'related incident confidence insufficient'
             WHERE a.viewer_bloc_id = %s
               AND a.severity = 'watch'
               AND a.dismissed_at IS NULL
               AND a.suppressed_until IS NULL
               AND i.confidence = 'insufficient'
            """,
            (viewer_bloc_id,),
        )
        written += cur.rowcount

    # 4. Stale escalation decay: alerts older than 30d with no analyst
    #    interaction → archive automatically.
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE strategic_alerts
               SET analyst_status = 'archived',
                   suppression_reason = COALESCE(suppression_reason, 'stale: no analyst review in 30d')
             WHERE viewer_bloc_id = %s
               AND analyst_status IN ('new','suppressed')
               AND detected_at <= NOW() - INTERVAL 30 DAY
               AND reviewed_at IS NULL
            """,
            (viewer_bloc_id,),
        )
        written += cur.rowcount

    # 5. Apply persisted suppression rules (intel_alert_suppression_rules).
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, target_alert_kind, primary_system_id, primary_alliance_id,
                   related_corridor_id, active_until, reason
              FROM intel_alert_suppression_rules
             WHERE viewer_bloc_id = %s
               AND (active_until IS NULL OR active_until >= NOW())
            """,
            (viewer_bloc_id,),
        )
        rules = list(cur.fetchall())
    for rule in rules:
        wheres = ["viewer_bloc_id = %s", "dismissed_at IS NULL"]
        params: list = [viewer_bloc_id]
        if rule["target_alert_kind"]:
            wheres.append("alert_kind = %s")
            params.append(rule["target_alert_kind"])
        if rule["primary_system_id"]:
            wheres.append("primary_system_id = %s")
            params.append(int(rule["primary_system_id"]))
        if rule["primary_alliance_id"]:
            wheres.append("primary_alliance_id = %s")
            params.append(int(rule["primary_alliance_id"]))
        if rule["related_corridor_id"]:
            wheres.append("related_corridor_id = %s")
            params.append(int(rule["related_corridor_id"]))
        sql = f"""
            UPDATE strategic_alerts
               SET analyst_status = CASE WHEN analyst_status='new' THEN 'suppressed' ELSE analyst_status END,
                   suppressed_until = COALESCE(%s, DATE_ADD(NOW(), INTERVAL 30 DAY)),
                   suppression_reason = %s,
                   suppression_rule_id = %s
             WHERE {' AND '.join(wheres)}
               AND suppression_rule_id IS NULL
        """
        with conn.cursor() as cur:
            cur.execute(
                sql,
                tuple([rule["active_until"], rule["reason"] or "manual suppression rule", int(rule["id"])] + params),
            )
            written += cur.rowcount

    conn.commit()
    log.info("phase4.8E suppression done", {"rows_modified": written})
    return {"rows_modified": written}


# =====================================================================
# §4.8G — trust metrics
# =====================================================================

_SURFACES = ["alert", "digest", "narrative", "incident",
             "corridor", "alliance_profile", "threat_surface"]


def run_trust_metrics(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    log.info("phase4.8G trust metrics starting",
             {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    win_start = datetime.combine(window_end - timedelta(days=window_days - 1),
                                 datetime.min.time(), tzinfo=timezone.utc)
    win_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)

    written = 0
    for surface in _SURFACES:
        # Per-surface item-count baseline.
        total = _surface_total(conn, viewer_bloc_id, surface, win_start, win_end_dt)
        if total == 0:
            continue

        # Aggregated feedback events for this surface.
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT feedback_kind, COUNT(*) AS n
                  FROM intel_feedback_events
                 WHERE viewer_bloc_id = %s
                   AND surface = %s
                   AND created_at BETWEEN %s AND %s
                 GROUP BY feedback_kind
                """,
                (viewer_bloc_id, surface, win_start, win_end_dt),
            )
            fb = {r["feedback_kind"]: int(r["n"]) for r in cur.fetchall()}

        # Surface-specific extra metrics.
        false_positive_count = 0
        override_count = 0
        suppression_count = 0
        narrative_correction_count = 0
        if surface == "alert":
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT
                       SUM(false_positive = 1) AS fp,
                       SUM(analyst_confidence_override IS NOT NULL) AS overr,
                       SUM(analyst_status = 'suppressed' OR suppressed_until IS NOT NULL) AS supp
                      FROM strategic_alerts
                     WHERE viewer_bloc_id = %s
                       AND detected_at BETWEEN %s AND %s
                    """,
                    (viewer_bloc_id, win_start, win_end_dt),
                )
                row = cur.fetchone() or {}
                false_positive_count = int(row.get("fp") or 0)
                override_count = int(row.get("overr") or 0)
                suppression_count = int(row.get("supp") or 0)
        elif surface == "narrative":
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT COUNT(*) AS n
                      FROM verified_intelligence_items
                     WHERE viewer_bloc_id = %s
                       AND item_kind = 'narrative_override'
                       AND created_at BETWEEN %s AND %s
                    """,
                    (viewer_bloc_id, win_start, win_end_dt),
                )
                narrative_correction_count = int((cur.fetchone() or {}).get("n") or 0)

        useful = fb.get("useful", 0) + fb.get("strategic", 0)
        misleading = fb.get("misleading", 0) + fb.get("incorrect_escalation", 0) \
                     + fb.get("incorrect_doctrine", 0) + fb.get("incorrect_linkage", 0)
        noisy = fb.get("noisy", 0)
        duplicate = fb.get("duplicate", 0)
        strategic = fb.get("strategic", 0)

        useful_rate = useful / total if total else 0.0
        fp_rate = (false_positive_count + misleading) / total if total else 0.0
        override_rate = override_count / total if total else 0.0
        suppression_rate = suppression_count / total if total else 0.0

        # Trust score: 0.6×useful_rate + 0.3×(1-fp_rate)
        # + 0.1×(1-suppression_rate). Bounded [0, 1].
        trust = max(0.0, min(1.0,
            0.6 * useful_rate
            + 0.3 * max(0.0, 1.0 - fp_rate)
            + 0.1 * max(0.0, 1.0 - suppression_rate)
        ))
        # When zero feedback collected, trust score is just an
        # "innocent until proven guilty" baseline of 0.5 minus any
        # automatic suppression evidence we have.
        if useful + misleading + noisy + duplicate + strategic == 0:
            trust = max(0.0, 0.5 - 0.4 * suppression_rate)

        tier = (
            "high" if trust >= 0.85 else
            "strong" if trust >= 0.70 else
            "adequate" if trust >= 0.50 else
            "low" if trust >= 0.30 else
            "untrusted"
        )

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO system_trust_metrics
                  (viewer_bloc_id, surface, window_end, window_days,
                   total_items, useful_count, misleading_count, noisy_count,
                   duplicate_count, strategic_count, false_positive_count,
                   analyst_override_count, suppression_count,
                   narrative_correction_count,
                   useful_rate, false_positive_rate, override_rate,
                   suppression_rate, trust_score, trust_tier)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    total_items = VALUES(total_items),
                    useful_count = VALUES(useful_count),
                    misleading_count = VALUES(misleading_count),
                    noisy_count = VALUES(noisy_count),
                    duplicate_count = VALUES(duplicate_count),
                    strategic_count = VALUES(strategic_count),
                    false_positive_count = VALUES(false_positive_count),
                    analyst_override_count = VALUES(analyst_override_count),
                    suppression_count = VALUES(suppression_count),
                    narrative_correction_count = VALUES(narrative_correction_count),
                    useful_rate = VALUES(useful_rate),
                    false_positive_rate = VALUES(false_positive_rate),
                    override_rate = VALUES(override_rate),
                    suppression_rate = VALUES(suppression_rate),
                    trust_score = VALUES(trust_score),
                    trust_tier = VALUES(trust_tier),
                    updated_at = NOW()
                """,
                (
                    viewer_bloc_id, surface, window_end, window_days,
                    total, useful, misleading, noisy, duplicate, strategic,
                    false_positive_count, override_count, suppression_count,
                    narrative_correction_count,
                    round(useful_rate, 4), round(fp_rate, 4),
                    round(override_rate, 4), round(suppression_rate, 4),
                    round(trust, 4), tier,
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.8G trust metrics done", {"surfaces_written": written})
    return {"surfaces_written": written}


def _surface_total(conn, bloc_id, surface, win_start, win_end_dt) -> int:
    table_map = {
        "alert": ("strategic_alerts", "detected_at"),
        "digest": ("daily_operational_digest", "generated_at"),
        "narrative": ("incident_narratives", "computed_at"),
        "incident": ("operational_incidents", "start_at"),
        "corridor": ("operational_corridors", "last_seen_at"),
        "alliance_profile": ("alliance_operational_profiles", "computed_at"),
        "threat_surface": ("system_threat_surface", "computed_at"),
    }
    if surface not in table_map:
        return 0
    table, ts_col = table_map[surface]
    with conn.cursor() as cur:
        cur.execute(
            f"SELECT COUNT(*) AS n FROM {table} "
            f"WHERE viewer_bloc_id = %s AND {ts_col} BETWEEN %s AND %s",
            (bloc_id, win_start, win_end_dt),
        )
        return int((cur.fetchone() or {}).get("n") or 0)


# =====================================================================
# §4.8B — digest trust enrichment
# =====================================================================

def run_enrich_digest_trust(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    digest_date: date | None = None,
) -> dict:
    """Walk recent digests and fill section_confidence_json /
    evidence_summary_json / source_reliability_json. Compute
    confidence per section based on the underlying source counts."""
    log.info("phase4.8B digest trust enrichment starting", {"viewer_bloc_id": viewer_bloc_id})

    where = "viewer_bloc_id = %s"
    params: list = [viewer_bloc_id]
    if digest_date is not None:
        where += " AND digest_date = %s"
        params.append(digest_date)

    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT id, digest_date, window_kind,
                   top_incident_ids_json, escalation_summary_json,
                   doctrine_evolution_json, coalition_movement_json,
                   new_corridors_json, unusual_compositions_json,
                   emerging_operators_json, response_anomalies_json
              FROM daily_operational_digest
             WHERE {where}
            """,
            tuple(params),
        )
        digests = list(cur.fetchall())

    written = 0
    for d in digests:
        def _decode(j):
            try:
                return json.loads(j or "[]") or []
            except (TypeError, ValueError):
                return []

        sections = {
            "top_incidents": _decode(d["top_incident_ids_json"]),
            "doctrine_evolution": _decode(d["doctrine_evolution_json"]),
            "coalition_movement": _decode(d["coalition_movement_json"]),
            "new_corridors": _decode(d["new_corridors_json"]),
            "unusual_compositions": _decode(d["unusual_compositions_json"]),
            "emerging_operators": _decode(d["emerging_operators_json"]),
            "response_anomalies": _decode(d["response_anomalies_json"]),
        }

        confidence: dict[str, dict] = {}
        evidence: dict[str, dict] = {}
        sev = _decode(d["escalation_summary_json"])
        if isinstance(sev, dict):
            sev_dist = sev
        else:
            sev_dist = {}

        # Top incidents — confidence proportional to dscan support count.
        top_inc_ids = sections["top_incidents"]
        dscan_support = 0
        if top_inc_ids:
            with conn.cursor() as cur:
                ph = ",".join(["%s"] * len(top_inc_ids))
                cur.execute(
                    f"SELECT SUM(has_dscan = 1) AS s FROM operational_incidents WHERE id IN ({ph})",
                    tuple(top_inc_ids),
                )
                dscan_support = int((cur.fetchone() or {}).get("s") or 0)
        confidence["top_incidents"] = _section_confidence(
            count=len(top_inc_ids),
            evidence_strength=dscan_support / max(1, len(top_inc_ids)),
        )
        evidence["top_incidents"] = {
            "count": len(top_inc_ids),
            "dscan_supported": dscan_support,
        }

        # Doctrine evolution — confidence by avg per-event confidence.
        evo = sections["doctrine_evolution"] if isinstance(sections["doctrine_evolution"], list) else []
        evo_high = sum(1 for e in evo if isinstance(e, dict) and e.get("confidence") in ("medium", "high"))
        confidence["doctrine_evolution"] = _section_confidence(
            count=len(evo),
            evidence_strength=evo_high / max(1, len(evo)),
        )
        evidence["doctrine_evolution"] = {
            "count": len(evo), "medium_or_high": evo_high,
        }

        # Coalition movement — confidence by alliance attribution coverage.
        cm = sections["coalition_movement"] if isinstance(sections["coalition_movement"], list) else []
        confidence["coalition_movement"] = _section_confidence(
            count=len(cm),
            evidence_strength=min(1.0, len(cm) / 5.0),
        )
        evidence["coalition_movement"] = {"count": len(cm)}

        # New corridors — confidence prefers medium+high corridor confidence.
        nc = sections["new_corridors"] if isinstance(sections["new_corridors"], list) else []
        nc_solid = sum(1 for c in nc if isinstance(c, dict) and c.get("confidence") in ("medium", "high"))
        confidence["new_corridors"] = _section_confidence(
            count=len(nc), evidence_strength=nc_solid / max(1, len(nc)),
        )
        evidence["new_corridors"] = {"count": len(nc), "solid_confidence": nc_solid}

        # Unusual compositions — confidence by non-null doctrine_match_pct.
        uc = sections["unusual_compositions"] if isinstance(sections["unusual_compositions"], list) else []
        uc_doc = sum(1 for c in uc if isinstance(c, dict) and c.get("primary_doctrine_name"))
        confidence["unusual_compositions"] = _section_confidence(
            count=len(uc), evidence_strength=uc_doc / max(1, len(uc)),
        )
        evidence["unusual_compositions"] = {"count": len(uc), "doctrine_matched": uc_doc}

        # Emerging operators.
        eo = sections["emerging_operators"] if isinstance(sections["emerging_operators"], list) else []
        confidence["emerging_operators"] = _section_confidence(
            count=len(eo), evidence_strength=min(1.0, len(eo) / 6.0),
        )
        evidence["emerging_operators"] = {"count": len(eo)}

        # Response anomalies.
        ra = sections["response_anomalies"] if isinstance(sections["response_anomalies"], list) else []
        confidence["response_anomalies"] = _section_confidence(
            count=len(ra), evidence_strength=min(1.0, len(ra) / 4.0),
        )
        evidence["response_anomalies"] = {"count": len(ra)}

        # Source reliability — pull intel_reliability_profiles average.
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT AVG(reliability_score) AS avg_score, COUNT(*) AS n
                  FROM intel_reliability_profiles
                 WHERE viewer_bloc_id = %s
                """,
                (viewer_bloc_id,),
            )
            rel_row = cur.fetchone() or {}
        source_reliability = {
            "avg_reporter_reliability": float(rel_row.get("avg_score") or 0.0),
            "reporter_count": int(rel_row.get("n") or 0),
            "severity_distribution": sev_dist,
        }

        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE daily_operational_digest
                   SET section_confidence_json = %s,
                       evidence_summary_json = %s,
                       source_reliability_json = %s,
                       updated_at = NOW()
                 WHERE id = %s
                """,
                (
                    json.dumps(confidence, default=str),
                    json.dumps(evidence, default=str),
                    json.dumps(source_reliability, default=str),
                    int(d["id"]),
                ),
            )
        written += 1

    conn.commit()
    log.info("phase4.8B digest trust enrichment done", {"digests_written": written})
    return {"digests_written": written}


def _section_confidence(count: int, evidence_strength: float) -> dict:
    """Section-level confidence + tier from count + evidence strength."""
    if count == 0:
        return {"score": 0.0, "tier": "insufficient", "count": 0}
    raw = min(1.0, 0.4 * min(1.0, count / 5.0) + 0.6 * max(0.0, min(1.0, evidence_strength)))
    tier = (
        "high" if raw >= 0.75 else
        "medium" if raw >= 0.50 else
        "low" if raw >= 0.25 else
        "insufficient"
    )
    return {"score": round(raw, 4), "tier": tier, "count": count}


# =====================================================================
# §4.8C — narrative source tracing
# =====================================================================

def run_enrich_narrative_sources(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
    limit: int = 1000,
) -> dict:
    log.info("phase4.8C narrative tracing starting", {"viewer_bloc_id": viewer_bloc_id})

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT n.id AS narrative_id, n.incident_id, i.battle_id,
                   i.hostile_cluster_ids_json, i.timeline_event_ids_json
              FROM incident_narratives n
              JOIN operational_incidents i ON i.id = n.incident_id
             WHERE n.viewer_bloc_id = %s
               AND n.computed_at >= %s
               AND n.source_incident_ids_json IS NULL
             LIMIT %s
            """,
            (viewer_bloc_id, since_dt, limit),
        )
        rows = list(cur.fetchall())
    if not rows:
        log.info("phase4.8C narrative tracing no rows", {})
        return {"narratives_traced": 0}

    written = 0
    for r in rows:
        try:
            cluster_ids = [int(x) for x in (json.loads(r["hostile_cluster_ids_json"] or "[]") or [])]
        except (TypeError, ValueError):
            cluster_ids = []
        try:
            timeline_ids = [int(x) for x in (json.loads(r["timeline_event_ids_json"] or "[]") or [])]
        except (TypeError, ValueError):
            timeline_ids = []

        # dscan ids inside contributing clusters.
        dscan_ids: set[str] = set()
        if cluster_ids:
            with conn.cursor() as cur:
                ph = ",".join(["%s"] * len(cluster_ids))
                cur.execute(
                    f"SELECT dscan_snapshot_ids_json FROM operational_hostile_clusters WHERE id IN ({ph})",
                    tuple(cluster_ids),
                )
                for cr in cur.fetchall():
                    try:
                        for sid in (json.loads(cr["dscan_snapshot_ids_json"] or "[]") or []):
                            dscan_ids.add(str(sid))
                    except (TypeError, ValueError):
                        pass

        # Score narrative confidence from evidence breadth.
        ev_count = (
            (3 if r["battle_id"] else 0)
            + (2 * len(dscan_ids))
            + len(cluster_ids)
            + len(timeline_ids)
        )
        narrative_conf = max(0.05, min(1.0, ev_count / 12.0))

        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE incident_narratives
                   SET source_incident_ids_json = %s,
                       source_cluster_ids_json = %s,
                       source_dscan_snapshot_ids_json = %s,
                       source_timeline_event_ids_json = %s,
                       source_battle_id = %s,
                       narrative_confidence = %s,
                       updated_at = NOW()
                 WHERE id = %s
                """,
                (
                    json.dumps([int(r["incident_id"])]),
                    json.dumps(cluster_ids),
                    json.dumps(sorted(dscan_ids)),
                    json.dumps(timeline_ids),
                    int(r["battle_id"]) if r["battle_id"] else None,
                    round(narrative_conf, 4),
                    int(r["narrative_id"]),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.8C narrative tracing done", {"narratives_traced": written})
    return {"narratives_traced": written}
