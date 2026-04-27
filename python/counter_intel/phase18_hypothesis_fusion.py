"""§18 — Counter-Intel hypothesis fusion.

Reduces the platform to ranked hypotheses for the operator. Reuses
existing CI signals — no new telemetry. Confidence model + ladder
follow ADR 0013:

    weak single signal      -> low
    2+ corroborating signals -> medium
    longitudinal consistency -> high
    operator-validated      -> confirmed (never set by AI)

Signals fused (single-pilot type, first ship):

    1. Phase 1 review_priority_score / band  (composite anomaly)
    2. hostile_triangle_count + top size     (graph triangulation)
    3. community_hostile_pct                  (community mismatch)
    4. asymmetric_top_pair_outbound_pct       (mutual-presence asymmetry)
    5. recent_hostile_join                    (corp cadence anomaly)
    6. pagerank / betweenness percentile      (graph centrality)
    7. hostile_alliance_count_history         (longitudinal exposure)

Each signal carries a domain tag so corroboration_count is the
number of distinct domains contributing — not the raw signal count.
That keeps "graph + graph + graph" from inflating to the same band
as "graph + operational + temporal".

Output: counter_intel_hypotheses rows. Idempotent UPSERT on
(bloc, type, character). first_seen_at preserved across runs;
last_strengthened_at updates only when score increased.
"""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from typing import Any

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase18_hypothesis_fusion")


# Domain tags — distinct domains contribute to corroboration_count.
DOMAIN_GRAPH       = "graph"
DOMAIN_OPERATIONAL = "operational"
DOMAIN_TEMPORAL    = "temporal"
DOMAIN_COMMUNITY   = "community"
DOMAIN_BATTLE      = "battle"


# Per-signal weights and band thresholds. Conservative — first ship.
# Scores are normalised so a single weak signal stays under 1, and
# multi-signal cross-domain combinations cross 3.
SIGNAL_WEIGHTS: dict[str, float] = {
    # Loop 3 rebalance — review_priority_score is itself composite,
    # so over-weighting it (3.0) drowned out cross-domain
    # corroboration. Knocked down so a battle-domain signal alone
    # can never reach the score threshold without a graph or
    # community signal joining it.
    "review_priority_score":   2.0,
    "hostile_triangle":        2.0,
    "community_hostile_pct":   2.0,
    "asymmetric_pair":         1.5,
    "recent_hostile_join":     2.0,
    "graph_centrality":        1.0,
    "longitudinal_exposure":   1.5,
    "incident_overlap":        2.5,   # operational telemetry (gated, see Loop 6)
    "corridor_overlap":        2.0,
    "synchronized_timing":     1.5,
    "corp_cadence_anomaly":    2.0,   # Loop 20 — 3+ corp moves in 30d
}


def _band_from_score(score: float, corroboration: int, longitudinal: bool) -> tuple[str, str]:
    """Return (confidence, severity) per ADR 0013 ladder.

    Promotion ladder, post-loop-1:
      corroboration >= 3 + score >= 4.0 + longitudinal -> high / critical
      corroboration >= 3 + score >= 4.0                -> high / elevated
      corroboration >= 2 + score >= 3.0                -> medium / elevated|watch
      corroboration >= 2 + score >= 2.0                -> medium / watch
      corroboration >= 1 + score >= 1.5                -> low / watch
      otherwise                                         -> low / info

    Operator validation is what pushes 'high' to 'confirmed' — never
    set by AI per ADR 0013.
    """
    if corroboration >= 3 and score >= 4.0:
        sev = "critical" if longitudinal else "elevated"
        return "high", sev
    if corroboration >= 2 and score >= 3.0:
        return "medium", "elevated" if score >= 4 else "watch"
    # Loop 16 — medium-band floor 2.5 → 3.0. Histogram showed 178
    # rows clustered in the 2.5–3.0 range, all borderline cohort
    # patterns. Operator wants fewer/stronger; raise floor.
    if corroboration >= 2 and score >= 3.0:
        return "medium", "watch"
    if corroboration >= 1 and score >= 1.5:
        return "low", "watch"
    return "low", "info"


def _hypothesis_summary(name: str, score: float, signals: list[dict[str, Any]]) -> str:
    """Deterministic templated summary — terse. The Command page's
    disclaimer ribbon already handles the "hypothesis-not-verdict"
    framing, so the summary itself stays tight and operationally
    legible.

    Loop 7: dropped the "warrants analyst review — not a verdict"
    closer; it appeared on every card and added noise. The card's
    disclaimer ribbon and confidence chip carry the framing.
    """
    pilot = name or "this pilot"
    n = len(signals)
    if n == 0:
        return f"{pilot}: insufficient signal."
    domains = sorted({s["domain"] for s in signals})
    return (
        f"{pilot}: {n} signal{'s' if n != 1 else ''} across "
        f"{len(domains)} domain{'s' if len(domains) != 1 else ''} "
        f"({', '.join(domains)}); score {score:.2f}."
    )


def _why_strengthened(prior: dict[str, Any] | None, current: dict[str, Any]) -> dict[str, Any]:
    """Compose the why-strengthened payload — required ADR 0013 field."""
    if prior is None:
        return {"first_observation": True}
    out: dict[str, Any] = {"prior_confidence": prior.get("confidence")}
    score_delta = round(float(current["score"]) - float(prior["score"]), 4)
    if abs(score_delta) >= 0.05:
        out["score_delta"] = score_delta
    if prior.get("corroboration") != current.get("corroboration"):
        out["corroboration_delta"] = current["corroboration"] - prior["corroboration"]
    new_signals = sorted({s["kind"] for s in current["signals"]} - {s["kind"] for s in prior.get("signals", [])})
    if new_signals:
        out["new_signals"] = new_signals
    dropped = sorted({s["kind"] for s in prior.get("signals", [])} - {s["kind"] for s in current["signals"]})
    if dropped:
        out["dropped_signals"] = dropped
    return out


def _gather_operational_overlap(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    days: int = 30,
) -> dict[int, dict[str, Any]]:
    """Per-character operational overlap signals.

    Initial implementation joined killmail_attackers ⨝ killmails
    (range filter on killed_at) ⨝ operational_incidents — too
    expensive in practice (9k incidents × billions of attacker
    rows, no covering index). Disabled for first ship; folded
    back in via a materialised aggregate (loop work item).
    """
    return {}


def _gather_signals(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    window_end: str,
) -> dict[int, list[dict[str, Any]]]:
    """Pull the latest rolling row per character + assemble a list of
    signal dicts. Returns character_id -> [signal, ...]."""
    out: dict[int, list[dict[str, Any]]] = {}

    # Operational corroboration in one pass.
    op_overlap = _gather_operational_overlap(conn, bloc_id, days=30)

    # Loop 20 — corp-cadence anomaly. Pilots with 3+ corp moves in
    # 30d are unusual; legitimate pilots rarely jump corps that
    # often. Bulk-load to avoid per-pilot subquery.
    corp_cadence: dict[int, int] = {}
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT character_id, COUNT(*) AS hops
              FROM character_corporation_history
             WHERE is_deleted = 0
               AND start_date >= NOW() - INTERVAL 30 DAY
             GROUP BY character_id
             HAVING hops >= 3
            """
        )
        for r in cur.fetchall() or []:
            corp_cadence[int(r["character_id"])] = int(r["hops"])

    # Pull rolling anomalies for the latest available window.
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT *
              FROM ci_character_anomalies_rolling
             WHERE viewer_bloc_id = %s
               AND window_end_date = %s
            """,
            (bloc_id, window_end),
        )
        rows = cur.fetchall() or []

    for r in rows:
        cid = int(r["character_id"])
        signals: list[dict[str, Any]] = []

        score = float(r.get("review_priority_score") or 0)
        band  = (r.get("review_priority_band") or "").lower()
        # Loop 4 — only fire on the explicit Phase 1 band, not on a
        # raw score floor. The score is monotonic with the band, but
        # the loose 0.40 fallback was admitting borderline cohort
        # members. Restrict to `elevated` and above.
        if band in {"elevated", "high", "critical"}:
            signals.append({
                "kind": "review_priority_score",
                "domain": DOMAIN_BATTLE,
                "value": round(score, 4),
                "band": band,
                "weight": SIGNAL_WEIGHTS["review_priority_score"],
                "strength": min(1.0, score / 0.80),
                "evidence": f"Phase 1 composite review priority = {score:.3f} (band {band})",
            })

        triangle = int(r.get("hostile_triangle_count") or 0)
        triangle_top = int(r.get("hostile_triangle_top_size") or 0)
        # Loops 9-10 — for a combat pilot in nullsec, 3 hostile
        # triangles is routine. Tighten: require >=5 triangles AND
        # the top triangle to involve >=4 hostile pilots so the
        # signal flags genuine recurring multi-pilot adjacency, not
        # opportunistic combat overlap.
        if triangle >= 5 and triangle_top >= 4:
            signals.append({
                "kind": "hostile_triangle",
                "domain": DOMAIN_GRAPH,
                "value": triangle,
                "top_size": triangle_top,
                "weight": SIGNAL_WEIGHTS["hostile_triangle"],
                "strength": min(1.0, triangle / 8.0),
                "evidence": f"{triangle} hostile-pilot triangle{'s' if triangle != 1 else ''} (top size {triangle_top})",
            })

        cmt = float(r.get("community_hostile_pct") or 0)
        # Loop 5 — 20% hostile-community share is normal in nullsec
        # because bloc members engage hostiles on every operation.
        # Bump floor to 35% so the signal flags genuine community
        # mismatch, not routine combat exposure.
        if cmt >= 0.35:
            signals.append({
                "kind": "community_hostile_pct",
                "domain": DOMAIN_COMMUNITY,
                "value": round(cmt, 4),
                "weight": SIGNAL_WEIGHTS["community_hostile_pct"],
                "strength": min(1.0, cmt / 0.65),
                "evidence": f"Community hostile share = {cmt * 100:.1f}% of graph neighbours",
            })

        out_pct = float(r.get("asymmetric_top_pair_outbound_pct") or 0)
        in_pct  = float(r.get("asymmetric_top_pair_inbound_pct") or 0)
        if out_pct >= 0.40 and abs(out_pct - in_pct) >= 0.20:
            signals.append({
                "kind": "asymmetric_pair",
                "domain": DOMAIN_GRAPH,
                "value_outbound": round(out_pct, 4),
                "value_inbound": round(in_pct, 4),
                "delta": round(out_pct - in_pct, 4),
                "weight": SIGNAL_WEIGHTS["asymmetric_pair"],
                "strength": min(1.0, abs(out_pct - in_pct) / 0.50),
                "evidence": f"Asymmetric mutual presence with top hostile pair (outbound {out_pct * 100:.0f}% / inbound {in_pct * 100:.0f}%)",
            })

        if int(r.get("recent_hostile_join") or 0):
            signals.append({
                "kind": "recent_hostile_join",
                "domain": DOMAIN_TEMPORAL,
                "value": True,
                "weight": SIGNAL_WEIGHTS["recent_hostile_join"],
                "strength": 1.0,
                "evidence": "Recent corp/alliance change touching a known hostile alliance",
            })

        pr = float(r.get("pagerank") or 0)
        bt = float(r.get("betweenness") or 0)
        # Loop 17 — bump centrality threshold. Pagerank 0.001 fires
        # on every active FC; lift to 0.0025 so the signal flags
        # genuine cross-side bridge behaviour, not routine cohort
        # role. Same on betweenness 50 → 150.
        if pr >= 0.0025 or bt >= 150:
            signals.append({
                "kind": "graph_centrality",
                "domain": DOMAIN_GRAPH,
                "pagerank": round(pr, 6),
                "betweenness": round(bt, 4),
                "weight": SIGNAL_WEIGHTS["graph_centrality"],
                "strength": min(1.0, max(pr / 0.008, bt / 400)),
                "evidence": f"Graph centrality outlier (pagerank {pr:.4f}, betweenness {bt:.1f})",
            })

        # Longitudinal exposure: hostile_alliance_count_history is a
        # cumulative counter. Loop 6 — 4 distinct hostile alliances
        # is normal for a multi-year pilot. Threshold raised to 8 so
        # the signal flags unusually broad hostile exposure, not
        # routine combat history.
        hist = int(r.get("hostile_alliance_count_history") or 0)
        if hist >= 8:
            signals.append({
                "kind": "longitudinal_exposure",
                "domain": DOMAIN_TEMPORAL,
                "value": hist,
                "weight": SIGNAL_WEIGHTS["longitudinal_exposure"],
                "strength": min(1.0, hist / 12.0),
                "evidence": f"{hist} distinct hostile alliances across history",
            })

        # Loop 20 — corp-cadence anomaly.
        hops = corp_cadence.get(cid)
        if hops:
            signals.append({
                "kind": "corp_cadence_anomaly",
                "domain": DOMAIN_TEMPORAL,
                "hops_30d": hops,
                "weight": SIGNAL_WEIGHTS["corp_cadence_anomaly"],
                "strength": min(1.0, hops / 6.0),
                "evidence": f"{hops} corp moves in last 30 days — unusual cadence",
            })

        # Operational corroboration — pilot was active on incidents
        # tagged for this bloc in the last 30d.
        op = op_overlap.get(cid)
        if op:
            top_sev = op["top_severity"]
            sev_weight = {
                "noise": 0.4, "tactical": 0.7, "strategic": 1.0,
                "escalation": 1.0, "coalition_level": 1.0,
            }.get(top_sev, 0.5)
            signals.append({
                "kind": "incident_overlap",
                "domain": DOMAIN_OPERATIONAL,
                "incident_count": op["incident_n"],
                "top_severity": top_sev,
                "weight": SIGNAL_WEIGHTS["incident_overlap"],
                "strength": min(1.0, op["incident_n"] / 30.0) * sev_weight,
                "evidence": (
                    f"Active on {op['incident_n']} bloc-tagged operational "
                    f"incidents in last 30d (top severity: {top_sev})"
                ),
            })

        # Capture 30d-prior score for longitudinal consistency check.
        prior_score = r.get("review_priority_score_30d_ago")
        out[cid] = {
            "signals": signals,
            "prior_score_30d": float(prior_score) if prior_score is not None else None,
        }
    return out


def _compute_one(
    cid: int,
    bundle: dict[str, Any],
    name_map: dict[int, str],
) -> dict[str, Any] | None:
    """Score one pilot. Returns hypothesis dict or None when below
    the minimum signal threshold."""
    signals = bundle["signals"]
    if not signals:
        return None
    score = sum(float(s["weight"]) * float(s["strength"]) for s in signals)
    domains = sorted({s["domain"] for s in signals})
    corroboration = len(domains)

    longitudinal = (
        bundle.get("prior_score_30d") is not None
        and float(bundle["prior_score_30d"]) >= 0.40
        and score >= 3.0
    )

    confidence, severity = _band_from_score(score, corroboration, longitudinal)

    # Loop 2 — drop the entire 'low' band. The Command page filters
    # to 'medium' by default, so 'low' rows only sit in the table as
    # noise. The "fewer, stronger" directive prefers no row over a
    # below-threshold one. Floor: only write `medium` and above.
    if confidence == "low":
        return None

    name = name_map.get(cid)
    summary = _hypothesis_summary(name, score, signals)
    caveats: list[str] = []
    # Loop 8 — longitudinal caveat now informative, not always-on.
    # Only surface when prior data exists; phrase based on whether
    # the signal is rising, persistent, or fading.
    prior_30 = bundle.get("prior_score_30d")
    if prior_30 is None:
        caveats.append("no 30-day prior — fresh signal, persistence unverified")
    elif longitudinal:
        caveats.append(f"persistent: 30d-prior priority {prior_30:.2f} corroborates current state")
    elif prior_30 < 0.40:
        caveats.append(f"rising: 30d-prior priority was only {prior_30:.2f} — recent escalation")
    if corroboration < 2:
        caveats.append("single-domain signal — corroboration weak")
    if any(s["kind"] == "graph_centrality" for s in signals) and len(signals) == 1:
        caveats.append("graph-only signal — could reflect routine cohort role")

    refs = [
        {
            "table": "ci_character_anomalies_rolling",
            "field": "character_id",
            "where": f"character_id={cid} AND viewer_bloc_id=...",
            "url": f"/portal/intelligence/character-lookup?cid={cid}",
        },
    ]
    if any(s["kind"] == "hostile_triangle" for s in signals):
        refs.append({
            "table": "ci_hostile_triangulation",
            "field": "character_id",
            "where": f"character_id={cid}",
            "url": f"/portal/intelligence/character-lookup?cid={cid}",
        })

    return {
        "primary_character_id": cid,
        "primary_character_name": name,
        "score": round(score, 4),
        "corroboration": corroboration,
        "longitudinal": longitudinal,
        "confidence": confidence,
        "severity": severity,
        "signals": signals,
        "summary": summary,
        "caveats": caveats,
        "source_refs": refs,
    }


def run_hypothesis_fusion(
    conn: pymysql.connections.Connection,
    cfg: Config,
    *,
    viewer_bloc_id: int,
) -> dict[str, Any]:
    """Compute + UPSERT hypotheses for one bloc.

    Idempotent on (viewer_bloc_id, hypothesis_type, primary_character_id).
    first_seen_at preserved on UPDATE; last_strengthened_at advances
    only when score increases. last_recomputed_at always advances.
    """
    log.info("phase18 hypothesis fusion starting", {"viewer_bloc_id": viewer_bloc_id})

    # Determine the latest available rolling window.
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            "SELECT MAX(window_end_date) AS mx FROM ci_character_anomalies_rolling WHERE viewer_bloc_id = %s",
            (viewer_bloc_id,),
        )
        row = cur.fetchone() or {}
    window_end = row.get("mx")
    if window_end is None:
        log.warning("no rolling anomaly data for bloc — nothing to fuse",
                    {"viewer_bloc_id": viewer_bloc_id})
        return {"hypotheses_written": 0, "candidates": 0}

    bundles = _gather_signals(conn, viewer_bloc_id, window_end)
    log.info("phase18 candidates gathered",
             {"candidates": len(bundles), "window_end": str(window_end)})

    # Resolve names in one shot.
    cids = list(bundles.keys())
    name_map: dict[int, str] = {}
    if cids:
        ph = ",".join(["%s"] * len(cids))
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute(
                f"SELECT entity_id, name FROM esi_entity_names "
                f"WHERE category='character' AND entity_id IN ({ph})",
                tuple(cids),
            )
            for r in cur.fetchall():
                name_map[int(r["entity_id"])] = str(r["name"])

    written = 0
    skipped = 0
    # Truncate microseconds — MariaDB DATETIME stores seconds only, so
    # comparing a Python datetime with microseconds against the stored
    # value lets every just-written row look "older" by < 1s and the
    # archive sweep eats everything.
    run_started = datetime.now(timezone.utc).replace(microsecond=0)
    for cid, bundle in bundles.items():
        h = _compute_one(cid, bundle, name_map)
        if h is None:
            skipped += 1
            continue
        _upsert_hypothesis(conn, viewer_bloc_id, h, run_started)
        written += 1
    conn.commit()

    # Loop 13 — freshness decay. Hypotheses that haven't been
    # strengthened recently lose their 'fresh' badge so the operator
    # sees which rows are stable observations vs current ones.
    decayed = _decay_freshness(conn, viewer_bloc_id, run_started)
    conn.commit()

    # Hypothesis decay — any row in this bloc that did NOT get
    # recomputed in this run no longer meets the threshold. Mark it
    # archived (status) and expired (freshness) so the Command page
    # filter drops it from the active queue. Preserved for audit;
    # operator can re-surface via min_band/status filter if needed.
    archived = _archive_stale(conn, viewer_bloc_id, run_started)
    conn.commit()

    log.info("phase18 hypothesis fusion complete",
             {"viewer_bloc_id": viewer_bloc_id, "candidates": len(bundles),
              "hypotheses_written": written, "skipped_below_threshold": skipped,
              "archived_stale": archived})
    return {
        "candidates": len(bundles),
        "hypotheses_written": written,
        "skipped_below_threshold": skipped,
        "archived_stale": archived,
    }


def _archive_stale(conn, bloc_id: int, run_started: datetime) -> int:
    """Archive rows that were not refreshed in this run."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE counter_intel_hypotheses
               SET status = 'archived',
                   freshness_state = 'expired',
                   updated_at = %s
             WHERE viewer_bloc_id = %s
               AND last_recomputed_at < %s
               AND status <> 'archived'
            """,
            (run_started, bloc_id, run_started),
        )
        return cur.rowcount or 0


def _decay_freshness(conn, bloc_id: int, run_started: datetime) -> int:
    """Loop 13 — flag stable hypotheses as `aging` after 7d without
    a fresh strengthen, `stale` after 21d. Active rows recomputed
    this run but with old last_strengthened_at lose their 'fresh'
    badge so the operator can tell at a glance which rows are
    *current* signals vs persistent-but-quiet observations."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE counter_intel_hypotheses
               SET freshness_state = CASE
                     WHEN last_strengthened_at >= %s - INTERVAL 7 DAY  THEN 'fresh'
                     WHEN last_strengthened_at >= %s - INTERVAL 21 DAY THEN 'aging'
                     ELSE 'stale'
                   END,
                   updated_at = %s
             WHERE viewer_bloc_id = %s
               AND status <> 'archived'
            """,
            (run_started, run_started, run_started, bloc_id),
        )
        return cur.rowcount or 0


def _upsert_hypothesis(
    conn: pymysql.connections.Connection,
    bloc_id: int,
    h: dict[str, Any],
    now: datetime,
) -> None:
    cid = int(h["primary_character_id"])
    score = float(h["score"])
    summary = h["summary"][:500]

    # Read prior so we can compute why-strengthened + preserve
    # first_seen_at + advance last_strengthened_at only on score rise.
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute(
            """
            SELECT id, suspicion_score, first_seen_at, last_strengthened_at,
                   confidence, evidence_summary_json
              FROM counter_intel_hypotheses
             WHERE viewer_bloc_id = %s AND hypothesis_type = 'single_pilot_high_priority'
               AND primary_character_id = %s
            """,
            (bloc_id, cid),
        )
        prior_row = cur.fetchone()

    prior_payload = None
    last_strengthened = now
    first_seen = now
    if prior_row:
        first_seen = prior_row["first_seen_at"] or now
        try:
            prior_signals = json.loads(prior_row["evidence_summary_json"] or "[]")
        except Exception:
            prior_signals = []
        prior_payload = {
            "score": float(prior_row["suspicion_score"] or 0),
            "confidence": prior_row["confidence"],
            "corroboration": len({s.get("domain") for s in prior_signals if isinstance(s, dict)}),
            "signals": prior_signals,
        }
        last_strengthened = prior_row["last_strengthened_at"] or now
        if score > prior_payload["score"] + 0.01:
            last_strengthened = now

    why = _why_strengthened(prior_payload, {
        "score": score,
        "corroboration": h["corroboration"],
        "signals": h["signals"],
    })

    refs_hash = hashlib.sha256(
        json.dumps(h["source_refs"], sort_keys=True, default=str).encode()
    ).hexdigest()
    _ = refs_hash  # reserved — useful for cluster dedup later

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO counter_intel_hypotheses
              (viewer_bloc_id, hypothesis_type, primary_character_id,
               related_character_ids_json,
               confidence, severity, suspicion_score,
               evidence_count, corroboration_count,
               first_seen_at, last_strengthened_at, last_recomputed_at,
               freshness_state, status, hypothesis_summary,
               evidence_summary_json, source_signal_refs_json,
               caveats_json, why_strengthened_json,
               ai_model, ai_prompt_hash, created_at, updated_at)
            VALUES (%s, 'single_pilot_high_priority', %s, %s,
                    %s, %s, %s, %s, %s,
                    %s, %s, %s, 'fresh', 'new', %s,
                    %s, %s, %s, %s,
                    %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
              related_character_ids_json = VALUES(related_character_ids_json),
              confidence = VALUES(confidence),
              severity = VALUES(severity),
              suspicion_score = VALUES(suspicion_score),
              evidence_count = VALUES(evidence_count),
              corroboration_count = VALUES(corroboration_count),
              last_strengthened_at = VALUES(last_strengthened_at),
              last_recomputed_at = VALUES(last_recomputed_at),
              freshness_state = 'fresh',
              hypothesis_summary = VALUES(hypothesis_summary),
              evidence_summary_json = VALUES(evidence_summary_json),
              source_signal_refs_json = VALUES(source_signal_refs_json),
              caveats_json = VALUES(caveats_json),
              why_strengthened_json = VALUES(why_strengthened_json),
              ai_model = VALUES(ai_model),
              updated_at = VALUES(updated_at)
            """,
            (
                bloc_id, cid,
                json.dumps([], default=str),
                h["confidence"], h["severity"], score,
                len(h["signals"]), h["corroboration"],
                first_seen, last_strengthened, now,
                summary,
                json.dumps(h["signals"], default=str),
                json.dumps(h["source_refs"], default=str),
                json.dumps(h["caveats"], default=str),
                json.dumps(why, default=str),
                "rule_based_v1", None,
                first_seen if not prior_row else (prior_row.get("first_seen_at") or now),
                now,
            ),
        )
        # Audit row — actor_kind='ai', surface='ai_hypothesis'.
        cur.execute("SELECT id FROM counter_intel_hypotheses "
                    "WHERE viewer_bloc_id = %s AND hypothesis_type = 'single_pilot_high_priority' "
                    "AND primary_character_id = %s",
                    (bloc_id, cid))
        idrow = cur.fetchone()
        hid = (idrow["id"] if isinstance(idrow, dict) else (idrow[0] if idrow else 0)) if idrow else 0
        if hid:
            cur.execute(
                """
                INSERT INTO intel_audit_log
                  (actor_user_id, actor_alliance_id, actor_bloc_id, actor_kind,
                   surface, surface_ref_id, action,
                   prior_state_json, new_state_json, metadata_json,
                   ip_address, user_agent, created_at)
                VALUES (NULL, NULL, %s, 'ai',
                        'ai_hypothesis', %s, %s,
                        NULL, NULL, %s,
                        NULL, NULL, %s)
                """,
                (
                    bloc_id, hid,
                    "generate" if not prior_row else "refresh",
                    json.dumps({
                        "confidence": h["confidence"],
                        "severity":   h["severity"],
                        "score":      score,
                        "corroboration": h["corroboration"],
                        "longitudinal":  h["longitudinal"],
                        "ai_model":   "rule_based_v1",
                        "pipeline":   "phase18-hypothesis-fusion",
                    }, default=str),
                    now,
                ),
            )
