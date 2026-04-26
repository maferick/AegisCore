"""Phase 4.6 — coalition + doctrine behavior intelligence.

Five passes, each idempotent (UPSERT on stable key):

  run_alliance_profiles      §4.6A  alliance_operational_profiles
  run_coalition_comparisons  §4.6B  coalition_behavior_comparisons
  run_doctrine_evolution     §4.6C  doctrine_evolution_events
  run_route_pressure         §4.6D  operational_corridors classifier
  run_operator_fingerprints  §4.6E  operator_operational_fingerprints

Alliance attribution bridge:
  cluster.involved_character_ids_json  → characters.alliance_id
  incident.battle_id                   → battle_theater_participants.alliance_id
"""

from __future__ import annotations

import json
import math
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4_coalition")


# Quality / severity ladders re-used across passes.
_SEVERITY = ["noise", "tactical", "strategic", "escalation", "coalition_level"]


def _window(window_end: date, window_days: int) -> tuple[datetime, datetime, date]:
    start = datetime.combine(window_end - timedelta(days=window_days - 1),
                             datetime.min.time(), tzinfo=timezone.utc)
    end = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)
    return start, end, window_end - timedelta(days=window_days - 1)


# =====================================================================
# §4.6A — alliance_operational_profiles
# =====================================================================

@dataclass
class _AllianceAcc:
    alliance_id: int
    alliance_name: str | None = None
    bloc_id: int | None = None
    incident_ids: set[int] = field(default_factory=set)
    cluster_ids: set[int] = field(default_factory=set)
    composition_ids: set[int] = field(default_factory=set)
    escalation_count: int = 0
    disengage_count: int = 0
    fleet_sizes: list[int] = field(default_factory=list)
    capital_counts: list[int] = field(default_factory=list)
    super_counts: list[int] = field(default_factory=list)
    logi_ratios: list[float] = field(default_factory=list)
    tackle_ratios: list[float] = field(default_factory=list)
    mobility_votes: dict[str, int] = field(default_factory=lambda: defaultdict(int))
    projection_votes: dict[str, int] = field(default_factory=lambda: defaultdict(int))
    brawl_votes: dict[str, int] = field(default_factory=lambda: defaultdict(int))
    doctrine_hits: dict[str, int] = field(default_factory=lambda: defaultdict(int))
    response_minutes: list[float] = field(default_factory=list)
    engagement_minutes: list[float] = field(default_factory=list)
    strategic_systems: set[int] = field(default_factory=set)
    all_systems: set[int] = field(default_factory=set)
    corridor_pairs: dict[tuple[int, int], int] = field(default_factory=lambda: defaultdict(int))


_MOBILITY_SCORE = {"static": 0.0, "slow": 0.25, "medium": 0.5, "fast": 0.85, "warp_capable": 1.0, "mixed": 0.5}


def run_alliance_profiles(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    log.info("phase4.6A alliance profiles starting",
             {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat(), "days": window_days})

    win_start, win_end_dt, win_start_date = _window(window_end, window_days)

    # Pull bloc map for alliances.
    with conn.cursor() as cur:
        cur.execute(
            "SELECT entity_id AS aid, bloc_id FROM coalition_entity_labels "
            "WHERE entity_type='alliance' AND is_active=1"
        )
        bloc_for_alliance = {int(r["aid"]): int(r["bloc_id"]) for r in cur.fetchall() if r["bloc_id"]}

    with conn.cursor() as cur:
        cur.execute(
            "SELECT entity_id AS aid, entity_name FROM coalition_entity_labels "
            "WHERE entity_type='alliance' AND is_active=1 AND entity_name IS NOT NULL"
        )
        alliance_name = {int(r["aid"]): str(r["entity_name"]) for r in cur.fetchall()}

    # Pull characters → alliance map (lazy: only those we need later).
    char_alliance: dict[int, int] = {}
    with conn.cursor() as cur:
        cur.execute("SELECT character_id, alliance_id FROM characters WHERE alliance_id IS NOT NULL")
        for r in cur.fetchall():
            char_alliance[int(r["character_id"])] = int(r["alliance_id"])

    accs: dict[int, _AllianceAcc] = {}

    def get_acc(aid: int) -> _AllianceAcc:
        a = accs.get(aid)
        if a is None:
            a = _AllianceAcc(alliance_id=aid, alliance_name=alliance_name.get(aid),
                             bloc_id=bloc_for_alliance.get(aid))
            accs[aid] = a
        return a

    # Incident-level scan: walk operational_incidents in the window;
    # for each incident, attribute to alliances via (a) battle
    # participants and (b) involved characters in clusters.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT i.id, i.severity, i.battle_id, i.primary_system_id,
                   i.start_at, i.end_at, i.signal_types_json,
                   i.hostile_cluster_ids_json,
                   TIMESTAMPDIFF(MINUTE, i.start_at, i.end_at) AS dur
              FROM operational_incidents i
             WHERE i.viewer_bloc_id = %s
               AND i.start_at BETWEEN %s AND %s
            """,
            (viewer_bloc_id, win_start, win_end_dt),
        )
        incidents = list(cur.fetchall())

    incident_ids = [int(r["id"]) for r in incidents]
    incident_to_alliances: dict[int, set[int]] = defaultdict(set)

    # (a) Battle participants → alliance attribution.
    battle_ids = [int(r["battle_id"]) for r in incidents if r["battle_id"]]
    battle_alliances: dict[int, set[int]] = defaultdict(set)
    if battle_ids:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(battle_ids))
            cur.execute(
                f"SELECT theater_id, alliance_id FROM battle_theater_participants "
                f"WHERE theater_id IN ({ph}) AND alliance_id IS NOT NULL",
                tuple(battle_ids),
            )
            for r in cur.fetchall():
                battle_alliances[int(r["theater_id"])].add(int(r["alliance_id"]))

    # (b) Cluster character IDs → alliance map.
    all_cluster_ids: set[int] = set()
    for r in incidents:
        try:
            ids = json.loads(r["hostile_cluster_ids_json"] or "[]") or []
            all_cluster_ids.update(int(x) for x in ids)
        except (TypeError, ValueError):
            pass

    cluster_alliances: dict[int, set[int]] = defaultdict(set)
    cluster_chars: dict[int, list[int]] = {}
    cluster_systems: dict[int, int | None] = {}
    if all_cluster_ids:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(all_cluster_ids))
            cur.execute(
                f"SELECT id, primary_system_id, involved_character_ids_json "
                f"FROM operational_hostile_clusters WHERE id IN ({ph})",
                tuple(all_cluster_ids),
            )
            for r in cur.fetchall():
                cid = int(r["id"])
                cluster_systems[cid] = int(r["primary_system_id"]) if r["primary_system_id"] else None
                try:
                    char_ids = [int(x) for x in (json.loads(r["involved_character_ids_json"] or "[]") or [])]
                except (TypeError, ValueError):
                    char_ids = []
                cluster_chars[cid] = char_ids
                for cid_char in char_ids:
                    aid = char_alliance.get(cid_char)
                    if aid is not None:
                        cluster_alliances[cid].add(aid)

    # Walk incidents and accumulate.
    strategic_threshold = ("strategic", "escalation", "coalition_level")
    for r in incidents:
        iid = int(r["id"])
        sev = str(r["severity"])
        sigs = set(json.loads(r["signal_types_json"] or "[]") or [])
        try:
            cluster_ids = [int(x) for x in (json.loads(r["hostile_cluster_ids_json"] or "[]") or [])]
        except (TypeError, ValueError):
            cluster_ids = []

        attrib = set()
        if r["battle_id"] and battle_alliances.get(int(r["battle_id"])):
            attrib |= battle_alliances[int(r["battle_id"])]
        for cid in cluster_ids:
            attrib |= cluster_alliances.get(cid, set())
        incident_to_alliances[iid] = attrib
        if not attrib:
            continue

        for aid in attrib:
            a = get_acc(aid)
            a.incident_ids.add(iid)
            for cid in cluster_ids:
                a.cluster_ids.add(cid)
                sid = cluster_systems.get(cid)
                if sid is not None:
                    a.all_systems.add(sid)
                    if sev in strategic_threshold:
                        a.strategic_systems.add(sid)
            if "escalation" in sigs:
                a.escalation_count += 1
            if "disengagement" in sigs:
                a.disengage_count += 1
            if r["dur"] is not None:
                a.engagement_minutes.append(float(r["dur"]))

    # Build cluster → incident → battle attrib lookup so compositions
    # attached to a cluster inherit battle-level alliance attribution
    # even when the cluster's characters aren't in our characters table.
    cluster_to_incident: dict[int, int] = {}
    for r in incidents:
        try:
            cids = json.loads(r["hostile_cluster_ids_json"] or "[]") or []
        except (TypeError, ValueError):
            cids = []
        for cid in cids:
            cluster_to_incident[int(cid)] = int(r["id"])
    incident_battle: dict[int, int] = {int(r["id"]): int(r["battle_id"]) for r in incidents if r["battle_id"]}

    def composition_attrib(cid: int) -> set[int]:
        out = set(cluster_alliances.get(cid, set()))
        iid = cluster_to_incident.get(cid)
        if iid is not None:
            bid = incident_battle.get(iid)
            if bid is not None:
                out |= battle_alliances.get(bid, set())
        return out

    # Pull force compositions linked to attributed clusters → roll up
    # ship totals, capital, logi, doctrine.
    if all_cluster_ids:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(all_cluster_ids))
            cur.execute(
                f"""
                SELECT cluster_id, id AS comp_id, ship_total,
                       estimated_capital_count, estimated_super_count,
                       estimated_logistics_ratio, estimated_tackle_ratio,
                       mobility, projection_strength, brawl_range,
                       primary_doctrine_name
                  FROM operational_force_compositions
                 WHERE viewer_bloc_id = %s AND cluster_id IN ({ph})
                """,
                tuple([viewer_bloc_id] + list(all_cluster_ids)),
            )
            for r in cur.fetchall():
                cid = int(r["cluster_id"])
                attrib_aids = composition_attrib(cid)
                if not attrib_aids:
                    continue
                for aid in attrib_aids:
                    a = get_acc(aid)
                    a.composition_ids.add(int(r["comp_id"]))
                    a.fleet_sizes.append(int(r["ship_total"] or 0))
                    a.capital_counts.append(int(r["estimated_capital_count"] or 0))
                    a.super_counts.append(int(r["estimated_super_count"] or 0))
                    a.logi_ratios.append(float(r["estimated_logistics_ratio"] or 0.0))
                    a.tackle_ratios.append(float(r["estimated_tackle_ratio"] or 0.0))
                    if r["mobility"]:
                        a.mobility_votes[str(r["mobility"])] += 1
                    if r["projection_strength"]:
                        a.projection_votes[str(r["projection_strength"])] += 1
                    if r["brawl_range"]:
                        a.brawl_votes[str(r["brawl_range"])] += 1
                    if r["primary_doctrine_name"]:
                        a.doctrine_hits[str(r["primary_doctrine_name"])] += 1

    # Pull response times for systems each alliance touched. Average
    # the system-level intel_to_combat median over the systems they
    # operated in.
    sys_to_response: dict[int, float] = {}
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT solar_system_id, intel_to_combat_median_seconds AS med
              FROM system_response_times
             WHERE viewer_bloc_id = %s
               AND window_end_date = %s
               AND intel_to_combat_median_seconds IS NOT NULL
            """,
            (viewer_bloc_id, window_end),
        )
        for r in cur.fetchall():
            sys_to_response[int(r["solar_system_id"])] = float(r["med"]) / 60.0

    # Pull corridor usage for alliance-attributed clusters by re-using
    # cluster_systems and pairing within incidents.
    for a in accs.values():
        for sid in a.all_systems:
            if sid in sys_to_response:
                a.response_minutes.append(sys_to_response[sid])

    # Persist.
    written = 0
    for aid, a in accs.items():
        if not a.incident_ids:
            continue
        n_incidents = len(a.incident_ids)
        avg_fleet = sum(a.fleet_sizes) / len(a.fleet_sizes) if a.fleet_sizes else None
        avg_cap = sum(a.capital_counts) / len(a.capital_counts) if a.capital_counts else 0.0
        avg_super = sum(a.super_counts) / len(a.super_counts) if a.super_counts else 0.0
        avg_logi = sum(a.logi_ratios) / len(a.logi_ratios) if a.logi_ratios else 0.0
        avg_tackle = sum(a.tackle_ratios) / len(a.tackle_ratios) if a.tackle_ratios else 0.0
        avg_resp = sum(a.response_minutes) / len(a.response_minutes) if a.response_minutes else None
        avg_engage = sum(a.engagement_minutes) / len(a.engagement_minutes) if a.engagement_minutes else None
        escalation_rate = a.escalation_count / n_incidents if n_incidents else 0.0
        disengage_rate = a.disengage_count / n_incidents if n_incidents else 0.0
        strat_share = (len(a.strategic_systems) / len(a.all_systems)) if a.all_systems else 0.0
        primary_mob = max(a.mobility_votes.items(), key=lambda x: x[1])[0] if a.mobility_votes else None
        primary_proj = max(a.projection_votes.items(), key=lambda x: x[1])[0] if a.projection_votes else None
        primary_brawl = max(a.brawl_votes.items(), key=lambda x: x[1])[0] if a.brawl_votes else None
        avg_mob_score = (
            sum(_MOBILITY_SCORE.get(k, 0.5) * v for k, v in a.mobility_votes.items())
            / max(1, sum(a.mobility_votes.values()))
        ) if a.mobility_votes else None

        # Map warp_capable → fast for enum compat.
        if primary_mob == "warp_capable":
            primary_mob = "fast"

        doctrine_dist = dict(sorted(a.doctrine_hits.items(), key=lambda x: -x[1])[:10])

        op_style, style_conf = _classify_operational_style(
            avg_fleet, avg_cap, avg_super, avg_logi, avg_tackle,
            escalation_rate, disengage_rate, avg_resp, avg_engage,
            primary_mob, primary_proj, primary_brawl, strat_share,
            n_incidents,
        )

        confidence = (
            "high" if n_incidents >= 50 else
            "medium" if n_incidents >= 15 else
            "low" if n_incidents >= 5 else
            "insufficient"
        )

        evidence = {
            "incident_ids_sample": sorted(a.incident_ids)[:20],
            "doctrine_distribution": doctrine_dist,
            "mobility_votes": dict(a.mobility_votes),
            "projection_votes": dict(a.projection_votes),
            "brawl_votes": dict(a.brawl_votes),
            "n_clusters": len(a.cluster_ids),
            "n_compositions": len(a.composition_ids),
            "n_systems": len(a.all_systems),
            "n_strategic_systems": len(a.strategic_systems),
        }

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO alliance_operational_profiles
                  (viewer_bloc_id, alliance_id, alliance_name, bloc_id,
                   window_start, window_end, window_days,
                   incident_count, cluster_count, composition_count,
                   doctrine_distribution_json, escalation_rate, disengagement_rate,
                   avg_response_minutes, avg_fleet_size,
                   avg_capital_presence, avg_super_presence,
                   avg_logistics_ratio, avg_tackle_ratio,
                   avg_mobility_score, primary_mobility, primary_projection, primary_brawl_range,
                   avg_engagement_minutes, strategic_system_share,
                   corridor_usage_json, operational_style, style_confidence,
                   confidence, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    alliance_name = VALUES(alliance_name),
                    bloc_id = VALUES(bloc_id),
                    window_start = VALUES(window_start),
                    incident_count = VALUES(incident_count),
                    cluster_count = VALUES(cluster_count),
                    composition_count = VALUES(composition_count),
                    doctrine_distribution_json = VALUES(doctrine_distribution_json),
                    escalation_rate = VALUES(escalation_rate),
                    disengagement_rate = VALUES(disengagement_rate),
                    avg_response_minutes = VALUES(avg_response_minutes),
                    avg_fleet_size = VALUES(avg_fleet_size),
                    avg_capital_presence = VALUES(avg_capital_presence),
                    avg_super_presence = VALUES(avg_super_presence),
                    avg_logistics_ratio = VALUES(avg_logistics_ratio),
                    avg_tackle_ratio = VALUES(avg_tackle_ratio),
                    avg_mobility_score = VALUES(avg_mobility_score),
                    primary_mobility = VALUES(primary_mobility),
                    primary_projection = VALUES(primary_projection),
                    primary_brawl_range = VALUES(primary_brawl_range),
                    avg_engagement_minutes = VALUES(avg_engagement_minutes),
                    strategic_system_share = VALUES(strategic_system_share),
                    corridor_usage_json = VALUES(corridor_usage_json),
                    operational_style = VALUES(operational_style),
                    style_confidence = VALUES(style_confidence),
                    confidence = VALUES(confidence),
                    evidence_json = VALUES(evidence_json),
                    updated_at = NOW()
                """,
                (
                    viewer_bloc_id, aid, a.alliance_name, a.bloc_id,
                    win_start_date, window_end, window_days,
                    n_incidents, len(a.cluster_ids), len(a.composition_ids),
                    json.dumps(doctrine_dist, ensure_ascii=False),
                    round(escalation_rate, 4), round(disengage_rate, 4),
                    round(avg_resp, 2) if avg_resp is not None else None,
                    round(avg_fleet, 2) if avg_fleet is not None else None,
                    round(avg_cap, 2), round(avg_super, 2),
                    round(avg_logi, 4), round(avg_tackle, 4),
                    round(avg_mob_score, 4) if avg_mob_score is not None else None,
                    primary_mob, primary_proj, primary_brawl,
                    round(avg_engage, 2) if avg_engage is not None else None,
                    round(strat_share, 4),
                    None,  # corridor_usage_json — populated by 4.6D pass
                    op_style, round(style_conf, 4),
                    confidence, json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.6A alliance profiles done", {"alliances_written": written})
    return {"alliances_written": written, "incidents_seen": len(incidents)}


def _classify_operational_style(
    avg_fleet, avg_cap, avg_super, avg_logi, avg_tackle,
    escalation_rate, disengage_rate, avg_resp, avg_engage,
    mobility, projection, brawl, strat_share,
    n_incidents,
) -> tuple[str, float]:
    """Operational style classifier. Score every candidate, pick max."""
    if n_incidents < 3:
        return ("undetermined", 0.0)
    scores: dict[str, float] = {}
    # capital_heavy: caps + supers per composition
    scores["capital_heavy"] = min(1.0, ((avg_cap or 0) * 0.4) + ((avg_super or 0) * 1.5))
    # heavy_brawl: medium-close fleets, high logi, large size
    if brawl in ("close", "mid"):
        scores["heavy_brawl"] = min(1.0, (avg_logi or 0) * 2.5 + ((avg_fleet or 0) / 200))
    # fast_response: fast mobility + low avg response
    fast_mob = 1.0 if mobility in ("fast", "warp_capable") else (0.5 if mobility == "medium" else 0.0)
    resp_score = max(0.0, 1.0 - ((avg_resp or 30) / 30.0)) if avg_resp is not None else 0.0
    scores["fast_response"] = (fast_mob * 0.6) + (resp_score * 0.4)
    # harassment: small fleets, high mobility, low logi, low engage time
    if (avg_fleet or 0) < 30 and fast_mob >= 0.5:
        scores["harassment"] = min(1.0, fast_mob * 0.5 + (1.0 - min(1.0, (avg_logi or 0) * 5)) * 0.5)
    # corridor_control: many systems but low strategic share, mid mobility
    scores["corridor_control"] = min(1.0, max(0.0, (strat_share or 0)) * 0.3
                                     + (1.0 if projection in ("regional", "strategic") else 0.0) * 0.5)
    # structure_warfare: long engagements + structures isn't tracked yet
    # Substitute: long engagement minutes + escalation_rate low.
    if avg_engage and avg_engage >= 60 and escalation_rate < 0.1:
        scores["structure_warfare"] = min(1.0, (avg_engage / 240.0) * 0.6 + (1.0 - escalation_rate) * 0.4)
    # defensive: high disengagement_rate + low escalation
    scores["defensive"] = min(1.0, disengage_rate * 1.2 + (1.0 - escalation_rate) * 0.3)
    # opportunistic: low fleet, mixed brawl, medium mobility, low logi
    if mobility == "medium" and (avg_logi or 0) < 0.05:
        scores["opportunistic"] = 0.6
    # escalation_prone: escalation_rate high
    scores["escalation_prone"] = min(1.0, escalation_rate * 1.5 + (avg_super or 0) * 0.5)

    best = max(scores.items(), key=lambda kv: kv[1])
    if best[1] < 0.25:
        return ("undetermined", round(best[1], 4))
    return (best[0], round(best[1], 4))


# =====================================================================
# §4.6B — coalition_behavior_comparisons
# =====================================================================

def run_coalition_comparisons(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    log.info("phase4.6B coalition comparisons starting",
             {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()})

    # Load alliance profiles for the same window + bloc roster.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT aop.*
              FROM alliance_operational_profiles aop
             WHERE aop.viewer_bloc_id = %s
               AND aop.window_end = %s
               AND aop.window_days = %s
               AND aop.bloc_id IS NOT NULL
            """,
            (viewer_bloc_id, window_end, window_days),
        )
        profiles = list(cur.fetchall())

    with conn.cursor() as cur:
        cur.execute("SELECT id, bloc_code, display_name FROM coalition_blocs WHERE is_active = 1")
        bloc_meta = {int(r["id"]): (str(r["bloc_code"]), str(r["display_name"])) for r in cur.fetchall()}

    by_bloc: dict[int, list[dict]] = defaultdict(list)
    for p in profiles:
        by_bloc[int(p["bloc_id"])].append(p)

    written = 0
    for bid, group in by_bloc.items():
        bcode, bname = bloc_meta.get(bid, (None, None))
        n_alliances = len(group)
        n_incidents = sum(int(p["incident_count"]) for p in group)
        if n_incidents == 0:
            continue
        # Weighted average by incident_count.
        total_weight = sum(int(p["incident_count"]) for p in group) or 1
        def w_avg(field, default=None):
            num = 0.0
            den = 0.0
            for p in group:
                v = p.get(field)
                if v is None: continue
                w = int(p["incident_count"])
                num += float(v) * w
                den += w
            return (num / den) if den else default

        avg_resp = w_avg("avg_response_minutes")
        avg_fleet = w_avg("avg_fleet_size")
        avg_logi = w_avg("avg_logistics_ratio") or 0.0
        cap_rate = w_avg("avg_capital_presence") or 0.0
        escalation_rate = w_avg("escalation_rate") or 0.0
        strat_share = w_avg("strategic_system_share") or 0.0
        # Doctrine diversity = # distinct doctrines / sum_incidents (capped 1.0).
        doctrine_set: dict[str, int] = defaultdict(int)
        sys_set: set[int] = set()
        style_dist: dict[str, int] = defaultdict(int)
        mobility_votes: dict[str, int] = defaultdict(int)
        for p in group:
            try:
                dd = json.loads(p["doctrine_distribution_json"] or "{}") or {}
            except (TypeError, ValueError):
                dd = {}
            for k, v in dd.items():
                doctrine_set[k] += int(v)
            try:
                ev = json.loads(p["evidence_json"] or "{}") or {}
                if isinstance(ev.get("mobility_votes"), dict):
                    for k, v in ev["mobility_votes"].items():
                        mobility_votes[k] += int(v)
            except (TypeError, ValueError):
                pass
            style_dist[str(p["operational_style"])] += int(p["incident_count"])
        doctrine_diversity = min(1.0, len(doctrine_set) / max(1, math.sqrt(n_incidents)))

        # Operational footprint: load distinct system count via re-query
        # to avoid re-walking incidents.
        primary_mob = max(mobility_votes.items(), key=lambda x: x[1])[0] if mobility_votes else None
        if primary_mob == "warp_capable":
            primary_mob = "fast"

        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT COUNT(DISTINCT primary_system_id) AS n
                  FROM operational_incidents
                 WHERE viewer_bloc_id = %s
                   AND start_at BETWEEN %s AND %s
                   AND primary_system_id IS NOT NULL
                """,
                (viewer_bloc_id,
                 datetime.combine(window_end - timedelta(days=window_days - 1),
                                  datetime.min.time(), tzinfo=timezone.utc),
                 datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)),
            )
            footprint = int(cur.fetchone()["n"] or 0)

        top_doctrines = sorted(doctrine_set.items(), key=lambda x: -x[1])[:10]
        confidence = (
            "high" if n_incidents >= 100 else
            "medium" if n_incidents >= 30 else
            "low" if n_incidents >= 10 else
            "insufficient"
        )

        evidence = {
            "alliance_ids": [int(p["alliance_id"]) for p in group],
            "incident_count_sum": n_incidents,
            "primary_styles": dict(style_dist),
        }

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO coalition_behavior_comparisons
                  (viewer_bloc_id, bloc_id, bloc_code, bloc_display_name,
                   window_start, window_end, window_days,
                   alliance_count, incident_count, escalation_count,
                   avg_response_minutes, avg_fleet_size, escalation_rate,
                   doctrine_diversity, strategic_density, capital_usage_rate,
                   avg_logistics_ratio, primary_mobility,
                   operational_footprint_systems,
                   top_doctrines_json, top_systems_json, style_distribution_json,
                   confidence, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    bloc_code = VALUES(bloc_code),
                    bloc_display_name = VALUES(bloc_display_name),
                    window_start = VALUES(window_start),
                    alliance_count = VALUES(alliance_count),
                    incident_count = VALUES(incident_count),
                    escalation_count = VALUES(escalation_count),
                    avg_response_minutes = VALUES(avg_response_minutes),
                    avg_fleet_size = VALUES(avg_fleet_size),
                    escalation_rate = VALUES(escalation_rate),
                    doctrine_diversity = VALUES(doctrine_diversity),
                    strategic_density = VALUES(strategic_density),
                    capital_usage_rate = VALUES(capital_usage_rate),
                    avg_logistics_ratio = VALUES(avg_logistics_ratio),
                    primary_mobility = VALUES(primary_mobility),
                    operational_footprint_systems = VALUES(operational_footprint_systems),
                    top_doctrines_json = VALUES(top_doctrines_json),
                    top_systems_json = VALUES(top_systems_json),
                    style_distribution_json = VALUES(style_distribution_json),
                    confidence = VALUES(confidence),
                    evidence_json = VALUES(evidence_json),
                    updated_at = NOW()
                """,
                (
                    viewer_bloc_id, bid, bcode, bname,
                    window_end - timedelta(days=window_days - 1), window_end, window_days,
                    n_alliances, n_incidents,
                    sum(int(round(float(p["escalation_rate"]) * int(p["incident_count"]))) for p in group),
                    round(avg_resp, 2) if avg_resp is not None else None,
                    round(avg_fleet, 2) if avg_fleet is not None else None,
                    round(escalation_rate, 4),
                    round(doctrine_diversity, 4),
                    round(strat_share, 4),
                    round(min(1.0, cap_rate / 5.0), 4),  # cap "rate" normalized: 5 caps avg = 1.0
                    round(avg_logi, 4),
                    primary_mob, footprint,
                    json.dumps(top_doctrines, ensure_ascii=False),
                    None, json.dumps(dict(style_dist)),
                    confidence, json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.6B coalition comparisons done", {"blocs_written": written})
    return {"blocs_written": written}


# =====================================================================
# §4.6C — doctrine_evolution_events
# =====================================================================

def run_doctrine_evolution(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 14,
) -> dict:
    """Compare current window doctrine distribution per alliance to
    the previous window. Emit adoption / abandonment / sudden shift
    events. Detection thresholds are deliberately loose — analyst
    triages, not autonomous flags."""
    log.info("phase4.6C doctrine evolution starting", {"window_end": window_end.isoformat()})

    prior_end = window_end - timedelta(days=window_days)
    # Need a prior alliance_operational_profiles row at prior_end with
    # same window_days. If absent, recompute it on the fly using the
    # same alliance attribution logic (cheaper: just use existing
    # current+prior profiles if they exist).
    def fetch_profiles(end: date):
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT alliance_id, alliance_name, bloc_id,
                       incident_count, doctrine_distribution_json,
                       avg_capital_presence, avg_super_presence,
                       avg_logistics_ratio, avg_tackle_ratio,
                       primary_brawl_range
                  FROM alliance_operational_profiles
                 WHERE viewer_bloc_id = %s
                   AND window_end = %s
                   AND window_days = %s
                """,
                (viewer_bloc_id, end, window_days),
            )
            return {int(r["alliance_id"]): r for r in cur.fetchall()}

    cur_p = fetch_profiles(window_end)
    prior_p = fetch_profiles(prior_end)
    if not cur_p:
        log.info("phase4.6C no current profiles", {})
        return {"events_written": 0}

    written = 0
    for aid, cur_row in cur_p.items():
        prior_row = prior_p.get(aid)
        try:
            cur_d = json.loads(cur_row["doctrine_distribution_json"] or "{}") or {}
        except (TypeError, ValueError):
            cur_d = {}
        prior_d = {}
        if prior_row is not None:
            try:
                prior_d = json.loads(prior_row["doctrine_distribution_json"] or "{}") or {}
            except (TypeError, ValueError):
                prior_d = {}

        cur_total = sum(cur_d.values()) or 1
        prior_total = sum(prior_d.values()) or 1

        cur_share = {k: v / cur_total for k, v in cur_d.items()}
        prior_share = {k: v / prior_total for k, v in prior_d.items()}
        all_doctrines = set(cur_share) | set(prior_share)

        for d_name in all_doctrines:
            c = cur_share.get(d_name, 0.0)
            p = prior_share.get(d_name, 0.0)
            delta = c - p
            etype = None
            if p == 0 and c >= 0.10:
                etype = "adoption"
            elif c == 0 and p >= 0.10:
                etype = "abandonment"
            elif delta >= 0.20 and c >= 0.20:
                etype = "sudden_increase"
            elif delta <= -0.20 and p >= 0.20:
                etype = "sudden_decrease"
            if etype is None:
                continue
            magnitude = abs(delta)
            confidence = (
                "high" if magnitude >= 0.5 else
                "medium" if magnitude >= 0.3 else
                "low"
            )
            written += _persist_evolution_event(
                conn, viewer_bloc_id, aid, cur_row, etype, d_name,
                window_end, window_days, p, c, delta, magnitude, confidence,
            )

        # Capital emergence / kite↔brawl transitions / logistics-heavy
        # are derived from per-alliance metric deltas.
        if prior_row is not None:
            prior_cap = float(prior_row["avg_capital_presence"] or 0)
            cur_cap = float(cur_row["avg_capital_presence"] or 0)
            if prior_cap < 0.5 and cur_cap >= 1.0:
                written += _persist_evolution_event(
                    conn, viewer_bloc_id, aid, cur_row, "capital_emergence", None,
                    window_end, window_days, prior_cap / 5.0, cur_cap / 5.0,
                    cur_cap - prior_cap, abs(cur_cap - prior_cap),
                    "high" if cur_cap >= 3 else "medium",
                )
            prior_logi = float(prior_row["avg_logistics_ratio"] or 0)
            cur_logi = float(cur_row["avg_logistics_ratio"] or 0)
            if cur_logi - prior_logi >= 0.05 and cur_logi >= 0.10:
                written += _persist_evolution_event(
                    conn, viewer_bloc_id, aid, cur_row, "logistics_heavy_shift", None,
                    window_end, window_days, prior_logi, cur_logi,
                    cur_logi - prior_logi, abs(cur_logi - prior_logi),
                    "medium",
                )
            prior_brawl = str(prior_row["primary_brawl_range"] or "")
            cur_brawl = str(cur_row["primary_brawl_range"] or "")
            if prior_brawl in ("long", "mid") and cur_brawl == "close":
                written += _persist_evolution_event(
                    conn, viewer_bloc_id, aid, cur_row, "kite_to_brawl", None,
                    window_end, window_days, None, None, None, 1.0, "medium",
                )
            elif prior_brawl == "close" and cur_brawl in ("long", "mid"):
                written += _persist_evolution_event(
                    conn, viewer_bloc_id, aid, cur_row, "brawl_to_kite", None,
                    window_end, window_days, None, None, None, 1.0, "medium",
                )

    conn.commit()
    log.info("phase4.6C doctrine evolution done", {"events_written": written})
    return {"events_written": written}


def _persist_evolution_event(
    conn, viewer_bloc_id, aid, cur_row, etype, d_name,
    window_end, window_days, p_share, c_share, delta, magnitude, confidence,
) -> int:
    # Look up doctrine_id when possible.
    d_id = None
    if d_name:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM auto_doctrines WHERE canonical_name = %s LIMIT 1",
                (d_name,),
            )
            row = cur.fetchone()
            if row: d_id = int(row["id"])
    evidence = {"alliance_id": aid, "alliance_name": cur_row["alliance_name"]}
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO doctrine_evolution_events
              (viewer_bloc_id, alliance_id, alliance_name, bloc_id,
               event_type, doctrine_id, doctrine_name,
               window_end, window_days,
               prior_share, current_share, delta, magnitude,
               confidence, evidence_json)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                alliance_name = VALUES(alliance_name),
                bloc_id = VALUES(bloc_id),
                prior_share = VALUES(prior_share),
                current_share = VALUES(current_share),
                delta = VALUES(delta),
                magnitude = VALUES(magnitude),
                confidence = VALUES(confidence),
                evidence_json = VALUES(evidence_json),
                computed_at = NOW()
            """,
            (
                viewer_bloc_id, aid, cur_row["alliance_name"], cur_row["bloc_id"],
                etype, d_id, d_name,
                window_end, window_days,
                round(p_share, 4) if p_share is not None else None,
                round(c_share, 4) if c_share is not None else None,
                round(delta, 4) if delta is not None else None,
                round(magnitude, 4),
                confidence, json.dumps(evidence, default=str),
            ),
        )
    return 1


# =====================================================================
# §4.6D — operational_corridors classifier
# =====================================================================

def run_route_pressure(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
) -> dict:
    """Re-classify every corridor in the table as:

      staging              high distinct_chars + low transit time + repeat
      reinforcement        transit endpoint near recent escalation incidents
      escalation_path      both endpoints in escalation incidents
      deployment_migration shifting first_seen→last_seen window long
      transit              normal hostile traffic
      unclassified         insufficient data
    """
    log.info("phase4.6D route pressure starting", {"viewer_bloc_id": viewer_bloc_id})

    # Pull recent incidents per system, severity-graded.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT primary_system_id AS sid, severity, COUNT(*) AS n
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND primary_system_id IS NOT NULL
               AND start_at >= NOW() - INTERVAL 60 DAY
             GROUP BY primary_system_id, severity
            """,
            (viewer_bloc_id,),
        )
        sys_severity: dict[int, dict[str, int]] = defaultdict(lambda: defaultdict(int))
        for r in cur.fetchall():
            sys_severity[int(r["sid"])][str(r["severity"])] += int(r["n"])

    def has_escalation(sid: int) -> bool:
        m = sys_severity.get(sid, {})
        return (m.get("escalation", 0) + m.get("coalition_level", 0)) > 0

    def strategic_count(sid: int) -> int:
        m = sys_severity.get(sid, {})
        return m.get("strategic", 0) + m.get("escalation", 0) + m.get("coalition_level", 0)

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, from_system_id, to_system_id,
                   transition_count, distinct_characters, avg_transition_seconds,
                   first_seen_at, last_seen_at, confidence
              FROM operational_corridors
             WHERE viewer_bloc_id = %s
            """,
            (viewer_bloc_id,),
        )
        rows = list(cur.fetchall())

    written = 0
    for r in rows:
        from_sid = int(r["from_system_id"])
        to_sid = int(r["to_system_id"])
        n = int(r["transition_count"])
        chars = int(r["distinct_characters"])
        avg_sec = int(r["avg_transition_seconds"]) if r["avg_transition_seconds"] else None
        from_esc = has_escalation(from_sid)
        to_esc = has_escalation(to_sid)
        strat = strategic_count(from_sid) + strategic_count(to_sid)

        # Score each route class.
        staging_s = 0.0
        reinforce_s = 0.0
        escalation_s = 0.0
        if avg_sec is not None and avg_sec <= 90 and chars >= 3 and n >= 4:
            staging_s = min(1.0, n / 50.0 + chars / 30.0)
        if to_esc and not from_esc:
            reinforce_s = min(1.0, 0.3 + n / 30.0)
        elif from_esc and not to_esc:
            reinforce_s = min(1.0, 0.2 + n / 40.0)
        if from_esc and to_esc:
            escalation_s = min(1.0, 0.5 + strat / 20.0)

        # Pick the dominant tag.
        cls = "unclassified"
        # Migration: long span (>14d) with consistent small traffic.
        span_days = 0
        if r["first_seen_at"] and r["last_seen_at"]:
            span_days = (r["last_seen_at"] - r["first_seen_at"]).days
        if escalation_s >= 0.5:
            cls = "escalation_path"
        elif staging_s >= 0.5:
            cls = "staging"
        elif reinforce_s >= 0.4:
            cls = "reinforcement"
        elif span_days >= 14 and n >= 3 and chars >= 2:
            cls = "deployment_migration"
        elif n >= 2:
            cls = "transit"

        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE operational_corridors
                   SET route_classification = %s,
                       staging_score = %s,
                       reinforcement_score = %s,
                       escalation_path_score = %s,
                       computed_at = NOW()
                 WHERE id = %s
                """,
                (
                    cls,
                    round(staging_s, 4),
                    round(reinforce_s, 4),
                    round(escalation_s, 4),
                    int(r["id"]),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.6D route pressure done", {"corridors_classified": written})
    return {"corridors_classified": written}


# =====================================================================
# §4.6E — operator_operational_fingerprints (non-identity)
# =====================================================================

def run_operator_fingerprints(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date,
    window_days: int = 30,
) -> dict:
    """Per-character non-identity operational style. Looks ONLY at
    cluster co-presence + linked compositions/transitions.
    Does not attempt to identify humans — produces operational
    behavior tags only."""
    log.info("phase4.6E operator fingerprints starting", {"window_end": window_end.isoformat()})

    win_start, win_end_dt, win_start_date = _window(window_end, window_days)

    # Pull every cluster + their character ids in window.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, primary_system_id, start_at,
                   involved_character_ids_json, involved_character_names_json,
                   has_dscan, dscan_total_ships
              FROM operational_hostile_clusters
             WHERE viewer_bloc_id = %s
               AND start_at BETWEEN %s AND %s
            """,
            (viewer_bloc_id, win_start, win_end_dt),
        )
        clusters = list(cur.fetchall())
    if not clusters:
        return {"operators_written": 0}

    # Pull cluster→incident severity + signal_types.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id AS incident_id, severity, signal_types_json,
                   hostile_cluster_ids_json
              FROM operational_incidents
             WHERE viewer_bloc_id = %s
               AND start_at BETWEEN %s AND %s
            """,
            (viewer_bloc_id, win_start, win_end_dt),
        )
        incidents = list(cur.fetchall())
    cluster_incident: dict[int, dict] = {}
    for i in incidents:
        try:
            cids = json.loads(i["hostile_cluster_ids_json"] or "[]") or []
        except (TypeError, ValueError):
            cids = []
        for cid in cids:
            cluster_incident[int(cid)] = i

    # Force comp by cluster.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT cluster_id, ship_total, estimated_logistics_count,
                   estimated_logistics_ratio, estimated_tackle_count,
                   estimated_capital_count, estimated_super_count,
                   mobility, brawl_range
              FROM operational_force_compositions
             WHERE viewer_bloc_id = %s
               AND snapshot_at BETWEEN %s AND %s
            """,
            (viewer_bloc_id, win_start, win_end_dt),
        )
        comp_for_cluster: dict[int, list[dict]] = defaultdict(list)
        for r in cur.fetchall():
            comp_for_cluster[int(r["cluster_id"])].append(r)

    # Per-character accumulators.
    @dataclass
    class _Op:
        character_id: int
        character_name: str | None = None
        alliance_id: int | None = None
        cluster_count: int = 0
        incident_count: int = 0
        escalation_count: int = 0
        disengage_count: int = 0
        big_logi_clusters: int = 0
        capital_clusters: int = 0
        bait_signals: int = 0  # solo/small cluster + escalated incident
        camp_signals: int = 0  # repeat appearance same system within 3 days
        fast_response_signals: int = 0  # mobility=fast in their composition
        appearance_systems: dict[int, list[datetime]] = field(default_factory=lambda: defaultdict(list))
        incident_ids: set[int] = field(default_factory=set)

    ops: dict[int, _Op] = {}

    # Char alliance map.
    char_alliance: dict[int, int] = {}
    char_name: dict[int, str] = {}
    with conn.cursor() as cur:
        cur.execute("SELECT character_id, alliance_id, name FROM characters WHERE alliance_id IS NOT NULL")
        for r in cur.fetchall():
            char_alliance[int(r["character_id"])] = int(r["alliance_id"])
            char_name[int(r["character_id"])] = str(r["name"])

    for c in clusters:
        cid = int(c["id"])
        try:
            char_ids = [int(x) for x in (json.loads(c["involved_character_ids_json"] or "[]") or [])]
            names = json.loads(c["involved_character_names_json"] or "[]") or []
        except (TypeError, ValueError):
            char_ids = []
            names = []
        if not char_ids:
            continue
        sys_id = int(c["primary_system_id"]) if c["primary_system_id"] else None
        ts = c["start_at"]
        comps = comp_for_cluster.get(cid, [])
        big_logi = any(int(co["estimated_logistics_count"] or 0) >= 5 for co in comps)
        cap = any((int(co["estimated_capital_count"] or 0) + int(co["estimated_super_count"] or 0)) > 0 for co in comps)
        fast_mob = any(str(co["mobility"]) in ("fast", "warp_capable") for co in comps)
        small_size = any(int(co["ship_total"] or 0) <= 5 for co in comps)
        inc = cluster_incident.get(cid)
        sigs = set()
        sev = None
        if inc:
            try:
                sigs = set(json.loads(inc["signal_types_json"] or "[]") or [])
            except (TypeError, ValueError):
                sigs = set()
            sev = str(inc["severity"])

        for i, char_id in enumerate(char_ids):
            o = ops.get(char_id)
            if o is None:
                o = _Op(
                    character_id=char_id,
                    character_name=char_name.get(char_id) or (str(names[i]) if i < len(names) else None),
                    alliance_id=char_alliance.get(char_id),
                )
                ops[char_id] = o
            o.cluster_count += 1
            if sys_id is not None:
                o.appearance_systems[sys_id].append(ts)
            if inc:
                o.incident_ids.add(int(inc["incident_id"]))
                if sev in ("escalation", "coalition_level"):
                    o.escalation_count += 1
                if "disengagement" in sigs:
                    o.disengage_count += 1
                if small_size and sev in ("strategic", "escalation", "coalition_level"):
                    o.bait_signals += 1
            if big_logi:
                o.big_logi_clusters += 1
            if cap:
                o.capital_clusters += 1
            if fast_mob:
                o.fast_response_signals += 1

    # Compute camp_signals: repeat appearances same system within 3d.
    for o in ops.values():
        camps = 0
        for sid, times in o.appearance_systems.items():
            if len(times) < 2: continue
            times.sort()
            for j in range(1, len(times)):
                if (times[j] - times[j-1]).total_seconds() <= 3 * 86400:
                    camps += 1
        o.camp_signals = camps
        o.incident_count = len(o.incident_ids)

    # Persist.
    written = 0
    for char_id, o in ops.items():
        if o.cluster_count < 2:
            continue
        n = o.cluster_count or 1
        rapid_esc = min(1.0, o.escalation_count / max(1, o.incident_count))
        heavy_logi = min(1.0, o.big_logi_clusters / n)
        conservative = min(1.0, o.disengage_count / max(1, o.incident_count))
        bait = min(1.0, o.bait_signals / max(1, o.incident_count))
        camp = min(1.0, o.camp_signals / max(1, n - 1))
        response_tempo = min(1.0, o.fast_response_signals / n)

        scores = {
            "rapid_escalator": rapid_esc,
            "heavy_logi_anchor": heavy_logi,
            "conservative_disengager": conservative,
            "bait_specialist": bait,
            "corridor_camper": camp,
            "fast_responder": response_tempo,
        }
        primary, val = max(scores.items(), key=lambda kv: kv[1])
        if val < 0.3:
            primary = "generalist" if n >= 5 else "undetermined"
            val = 0.0

        confidence = (
            "high" if n >= 30 else
            "medium" if n >= 10 else
            "low" if n >= 4 else
            "insufficient"
        )

        evidence = {
            "incident_ids_sample": sorted(o.incident_ids)[:20],
            "n_systems": len(o.appearance_systems),
            "scores": {k: round(v, 4) for k, v in scores.items()},
        }

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO operator_operational_fingerprints
                  (viewer_bloc_id, character_id, character_name, alliance_id,
                   window_start, window_end, window_days,
                   incident_count, cluster_appearances,
                   escalation_appearances, disengagement_appearances,
                   rapid_escalation_score, heavy_logistics_score,
                   conservative_disengage_score, bait_engagement_score,
                   corridor_camp_score, response_tempo_score,
                   primary_style, style_confidence, confidence, evidence_json)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    character_name = VALUES(character_name),
                    alliance_id = VALUES(alliance_id),
                    window_start = VALUES(window_start),
                    incident_count = VALUES(incident_count),
                    cluster_appearances = VALUES(cluster_appearances),
                    escalation_appearances = VALUES(escalation_appearances),
                    disengagement_appearances = VALUES(disengagement_appearances),
                    rapid_escalation_score = VALUES(rapid_escalation_score),
                    heavy_logistics_score = VALUES(heavy_logistics_score),
                    conservative_disengage_score = VALUES(conservative_disengage_score),
                    bait_engagement_score = VALUES(bait_engagement_score),
                    corridor_camp_score = VALUES(corridor_camp_score),
                    response_tempo_score = VALUES(response_tempo_score),
                    primary_style = VALUES(primary_style),
                    style_confidence = VALUES(style_confidence),
                    confidence = VALUES(confidence),
                    evidence_json = VALUES(evidence_json),
                    updated_at = NOW()
                """,
                (
                    viewer_bloc_id, char_id, o.character_name, o.alliance_id,
                    win_start_date, window_end, window_days,
                    o.incident_count, o.cluster_count,
                    o.escalation_count, o.disengage_count,
                    round(rapid_esc, 4), round(heavy_logi, 4),
                    round(conservative, 4), round(bait, 4),
                    round(camp, 4), round(response_tempo, 4),
                    primary, round(val, 4), confidence,
                    json.dumps(evidence, default=str),
                ),
            )
        written += 1
    conn.commit()
    log.info("phase4.6E operator fingerprints done", {"operators_written": written})
    return {"operators_written": written}
