"""Phase 4.5 — operational force composition + doctrine matching.

Consumes operational_hostile_clusters with attached dscan snapshots,
maps ship names to group_id roles, computes role totals + estimated
metrics, fuzzy-matches against auto_doctrines fingerprints.

Plus operational_force_transitions: sequential dscan deltas inside
the same incident → tackle→capital, kite→brawl, logistics spike, etc.
"""

from __future__ import annotations

import json
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase4_force_composition")


# ---- ship-class role mapping ----------------------------------------

# group_id → role bucket. Misses go to "dps" by default.
ROLE_BY_GROUP_ID: dict[int, str] = {
    # capitals
    485: "capital", 547: "capital", 1538: "capital", 883: "capital",
    # supers / titans
    659: "super", 30: "super",
    # logistics
    832: "logistics", 1527: "logistics",
    # tackle
    831: "tackle", 894: "tackle", 541: "tackle",
    # bombers
    834: "bomber",
    # ewar / recon
    833: "ewar", 893: "ewar",
    # command
    540: "command", 1201: "command",
    # T3 strategic / tactical
    963: "dps", 1305: "dps",
    # cruisers + frigates + dessies + battleships + battlecruisers default to dps
    25: "dps", 26: "dps", 27: "dps", 28: "dps", 31: "dps",
    324: "dps", 358: "dps", 419: "dps", 420: "dps", 463: "dps",
    540: "command", 833: "ewar", 1972: "dps",
    # noctis / industrial / mining → support
    513: "support", 941: "support", 1404: "support",
    # capsule / pod
    29: "pod",
    # shuttles
    31: "shuttle",
}


# Mobility / brawl-range estimation by role mix.
def _estimate_mobility(roles: dict[str, int]) -> str:
    if roles.get("super", 0) > 0 or roles.get("capital", 0) > 0:
        return "slow"
    if roles.get("tackle", 0) >= max(2, roles.get("dps", 0) // 4):
        return "fast"
    if sum(roles.values()) > 100 and roles.get("logistics", 0) >= 5:
        return "medium"
    return "medium"


def _estimate_brawl_range(ship_breakdown: dict[str, int]) -> str:
    """Heuristic from common doctrine archetypes. Cheap; can be
    swapped for a proper turret/launcher analysis later."""
    name_low = {n.lower() for n in ship_breakdown.keys()}
    has_long = any(n in name_low for n in {"rokh", "maelstrom", "tempest", "naga", "raven navy issue", "barghest"})
    has_close = any(n in name_low for n in {"machariel", "vagabond", "ashimmu", "vindicator", "armageddon"})
    has_kite = any(n in name_low for n in {"hurricane", "muninn", "cerberus", "jackdaw", "svipul"})
    if has_long and not has_close:
        return "long"
    if has_close and not has_long:
        return "close"
    if has_kite:
        return "mid"
    return "mixed"


def _estimate_projection(roles: dict[str, int]) -> str:
    total = sum(roles.values())
    if roles.get("super", 0) > 0 and total >= 50:
        return "coalition"
    if roles.get("capital", 0) >= 3 or total >= 200:
        return "strategic"
    if total >= 80:
        return "regional"
    if total >= 30:
        return "sub_regional"
    return "local"


def run_force_compositions(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
) -> dict:
    """Per cluster (with dscan), build a force composition row."""
    log.info("phase4.5A force compositions starting", {"viewer_bloc_id": viewer_bloc_id})

    # Pull clusters with dscan in the window.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT c.id AS cluster_id, c.start_at, c.end_at,
                   c.dscan_snapshot_ids_json, c.dscan_total_ships
              FROM operational_hostile_clusters c
             WHERE c.viewer_bloc_id = %s
               AND c.start_at >= %s
               AND c.has_dscan = 1
            """,
            (viewer_bloc_id, since_dt),
        )
        clusters = list(cur.fetchall())
    if not clusters:
        return {"clusters": 0, "compositions_written": 0}

    # Map cluster→incident.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT i.id, i.hostile_cluster_ids_json
              FROM operational_incidents i
             WHERE i.viewer_bloc_id = %s
               AND i.start_at >= %s
            """,
            (viewer_bloc_id, since_dt),
        )
        incident_for_cluster: dict[int, int] = {}
        for r in cur.fetchall():
            ids = json.loads(r["hostile_cluster_ids_json"] or "[]") or []
            for cid in ids:
                incident_for_cluster[int(cid)] = int(r["id"])

    # Pre-load successful dscan snapshots referenced by these clusters.
    snap_ids = set()
    cluster_snap_ids: dict[int, list[str]] = {}
    for c in clusters:
        ids = json.loads(c.get("dscan_snapshot_ids_json") or "[]") or []
        cluster_snap_ids[int(c["cluster_id"])] = list(map(str, ids))
        for sid in ids: snap_ids.add(str(sid))
    if not snap_ids:
        return {"clusters": len(clusters), "compositions_written": 0}

    snaps: dict[str, dict] = {}
    with conn.cursor() as cur:
        ph = ",".join(["%s"] * len(snap_ids))
        cur.execute(
            f"SELECT snapshot_id, ship_count, ship_types_json, last_seen_at "
            f"FROM eve_log_dscan_snapshots WHERE snapshot_id IN ({ph}) AND fetch_status='success'",
            tuple(snap_ids),
        )
        for r in cur.fetchall():
            try:
                ships = json.loads(r["ship_types_json"] or "{}") or {}
            except (TypeError, ValueError):
                ships = {}
            if not ships:
                continue
            snaps[str(r["snapshot_id"])] = {
                "snapshot_id": str(r["snapshot_id"]),
                "ship_count": int(r["ship_count"] or 0),
                "ship_types": ships,
                "snapshot_at": r["last_seen_at"],
            }

    # Build name→group_id map for every ship name we've seen.
    all_names: set[str] = set()
    for s in snaps.values():
        for n in s["ship_types"].keys():
            all_names.add(n)
    name_to_group: dict[str, int] = {}
    if all_names:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(all_names))
            cur.execute(
                f"SELECT name, group_id FROM ref_item_types WHERE name IN ({ph})",
                tuple(all_names),
            )
            for r in cur.fetchall():
                name_to_group[str(r["name"])] = int(r["group_id"])

    # Pre-load doctrines for matching.
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, hull_type_id, role_key, canonical_name, observation_count, confidence "
            "FROM auto_doctrines WHERE is_active=1 ORDER BY observation_count DESC LIMIT 200"
        )
        doctrines = list(cur.fetchall())
    # Resolve hull names for doctrine fingerprint matching.
    hull_ids = list({int(d["hull_type_id"]) for d in doctrines})
    hull_name_by_id: dict[int, str] = {}
    if hull_ids:
        with conn.cursor() as cur:
            ph = ",".join(["%s"] * len(hull_ids))
            cur.execute(
                f"SELECT id, name FROM ref_item_types WHERE id IN ({ph})",
                tuple(hull_ids),
            )
            for r in cur.fetchall():
                hull_name_by_id[int(r["id"])] = str(r["name"])

    written = 0
    for c in clusters:
        cid = int(c["cluster_id"])
        for sid in cluster_snap_ids.get(cid, []):
            snap = snaps.get(sid)
            if not snap:
                continue
            comp = _build_composition(snap, name_to_group)
            doctrine_match = _match_doctrine(snap["ship_types"], doctrines, hull_name_by_id)
            evidence = {
                "snapshot_id": sid,
                "ship_total": snap["ship_count"],
                "doctrine_top_candidates": doctrine_match.get("candidates", [])[:5],
            }
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO operational_force_compositions
                      (viewer_bloc_id, cluster_id, incident_id, dscan_snapshot_id,
                       snapshot_at, primary_doctrine_name, primary_doctrine_id,
                       doctrine_confidence, doctrine_match_pct, doctrine_secondary_json,
                       ship_breakdown_json, ship_total,
                       estimated_pilot_count, estimated_logistics_count,
                       estimated_tackle_count, estimated_dps_count,
                       estimated_bomber_count, estimated_ewar_count,
                       estimated_command_count, estimated_capital_count,
                       estimated_super_count, estimated_logistics_ratio,
                       estimated_tackle_ratio, projection_strength, mobility,
                       brawl_range, evidence_json)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                            %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                            %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        snapshot_at = VALUES(snapshot_at),
                        primary_doctrine_name = VALUES(primary_doctrine_name),
                        primary_doctrine_id = VALUES(primary_doctrine_id),
                        doctrine_confidence = VALUES(doctrine_confidence),
                        doctrine_match_pct = VALUES(doctrine_match_pct),
                        doctrine_secondary_json = VALUES(doctrine_secondary_json),
                        ship_breakdown_json = VALUES(ship_breakdown_json),
                        ship_total = VALUES(ship_total),
                        estimated_pilot_count = VALUES(estimated_pilot_count),
                        estimated_logistics_count = VALUES(estimated_logistics_count),
                        estimated_tackle_count = VALUES(estimated_tackle_count),
                        estimated_dps_count = VALUES(estimated_dps_count),
                        estimated_bomber_count = VALUES(estimated_bomber_count),
                        estimated_ewar_count = VALUES(estimated_ewar_count),
                        estimated_command_count = VALUES(estimated_command_count),
                        estimated_capital_count = VALUES(estimated_capital_count),
                        estimated_super_count = VALUES(estimated_super_count),
                        estimated_logistics_ratio = VALUES(estimated_logistics_ratio),
                        estimated_tackle_ratio = VALUES(estimated_tackle_ratio),
                        projection_strength = VALUES(projection_strength),
                        mobility = VALUES(mobility),
                        brawl_range = VALUES(brawl_range),
                        evidence_json = VALUES(evidence_json),
                        computed_at = NOW()
                    """,
                    (
                        viewer_bloc_id, cid, incident_for_cluster.get(cid), sid,
                        snap.get("snapshot_at"),
                        doctrine_match.get("name"),
                        doctrine_match.get("id"),
                        doctrine_match.get("confidence"),
                        doctrine_match.get("match_pct"),
                        json.dumps(doctrine_match.get("candidates") or []),
                        json.dumps(snap["ship_types"], ensure_ascii=False),
                        snap["ship_count"],
                        comp["pilot_count"], comp["roles"]["logistics"],
                        comp["roles"]["tackle"], comp["roles"]["dps"],
                        comp["roles"]["bomber"], comp["roles"]["ewar"],
                        comp["roles"]["command"], comp["roles"]["capital"],
                        comp["roles"]["super"],
                        comp["logistics_ratio"], comp["tackle_ratio"],
                        comp["projection"], comp["mobility"], comp["brawl_range"],
                        json.dumps(evidence, default=str),
                    ),
                )
            written += 1
    conn.commit()
    log.info("phase4.5A force compositions done",
             {"clusters": len(clusters), "compositions_written": written})
    return {"clusters": len(clusters), "compositions_written": written}


def _build_composition(snap: dict, name_to_group: dict[str, int]) -> dict:
    roles = {"logistics": 0, "tackle": 0, "dps": 0, "bomber": 0, "ewar": 0,
             "command": 0, "capital": 0, "super": 0, "support": 0, "shuttle": 0,
             "pod": 0}
    for ship_name, count in snap["ship_types"].items():
        gid = name_to_group.get(ship_name)
        role = ROLE_BY_GROUP_ID.get(gid, "dps") if gid else "dps"
        if role not in roles:
            role = "dps"
        roles[role] += int(count)
    pilot_count = sum(v for k, v in roles.items() if k not in ("pod", "shuttle"))
    total_combat = max(1, pilot_count - roles["support"])
    return {
        "roles": roles,
        "pilot_count": pilot_count,
        "logistics_ratio": round(roles["logistics"] / total_combat, 4) if total_combat else 0.0,
        "tackle_ratio": round(roles["tackle"] / total_combat, 4) if total_combat else 0.0,
        "projection": _estimate_projection(roles),
        "mobility": _estimate_mobility(roles),
        "brawl_range": _estimate_brawl_range(snap["ship_types"]),
    }


def _match_doctrine(
    ship_types: dict[str, int],
    doctrines: list[dict],
    hull_name_by_id: dict[int, str],
) -> dict:
    """Best-doctrine fuzzy match. Compute Jaccard between top-N hulls
    in the dscan and the (hull_name, role) doctrine entries. Return
    primary + top secondary candidates."""
    if not ship_types or not doctrines:
        return {"name": None, "id": None, "confidence": None, "match_pct": None, "candidates": []}

    sorted_ships = sorted(ship_types.items(), key=lambda kv: -kv[1])
    top_n = sorted_ships[:8]
    top_names = {n for n, _ in top_n}
    total = sum(ship_types.values())

    candidates: list[dict] = []
    for d in doctrines:
        hull_name = hull_name_by_id.get(int(d["hull_type_id"]))
        if not hull_name:
            continue
        if hull_name in top_names:
            ship_count = int(ship_types.get(hull_name, 0))
            match_pct = ship_count / total if total else 0
            score = match_pct * float(d.get("confidence") or 0.0)
            candidates.append({
                "id": int(d["id"]),
                "name": str(d.get("canonical_name") or hull_name),
                "hull": hull_name,
                "role": str(d.get("role_key")),
                "match_pct": round(match_pct, 4),
                "doctrine_confidence": float(d.get("confidence") or 0.0),
                "score": round(score, 4),
                "observation_count": int(d.get("observation_count") or 0),
            })
    candidates.sort(key=lambda c: -c["score"])

    if not candidates:
        return {"name": None, "id": None, "confidence": None, "match_pct": None, "candidates": []}

    primary = candidates[0]
    return {
        "name": primary["name"],
        "id": primary["id"],
        "confidence": round(min(primary["score"] * 1.5, 1.0), 4),
        "match_pct": primary["match_pct"],
        "candidates": candidates,
    }


def run_force_transitions(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    since_dt: datetime,
) -> dict:
    """Sequential dscan deltas inside an incident."""
    log.info("phase4.5C force transitions starting", {"viewer_bloc_id": viewer_bloc_id})

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT incident_id, id AS comp_id, snapshot_at,
                   estimated_logistics_count, estimated_tackle_count,
                   estimated_capital_count, estimated_super_count,
                   estimated_bomber_count, estimated_dps_count,
                   ship_total, brawl_range
              FROM operational_force_compositions
             WHERE viewer_bloc_id = %s
               AND snapshot_at IS NOT NULL
               AND incident_id IS NOT NULL
               AND snapshot_at >= %s
             ORDER BY incident_id, snapshot_at
            """,
            (viewer_bloc_id, since_dt),
        )
        rows = list(cur.fetchall())
    if len(rows) < 2:
        return {"compositions": len(rows), "transitions_written": 0}

    by_incident: dict[int, list[dict]] = defaultdict(list)
    for r in rows:
        by_incident[int(r["incident_id"])].append(r)

    written = 0
    for inc_id, seq in by_incident.items():
        if len(seq) < 2:
            continue
        for i in range(1, len(seq)):
            a, b = seq[i - 1], seq[i]
            ship_delta = int(b["ship_total"]) - int(a["ship_total"])
            logi_delta = int(b["estimated_logistics_count"]) - int(a["estimated_logistics_count"])
            tackle_delta = int(b["estimated_tackle_count"]) - int(a["estimated_tackle_count"])
            cap_delta = (int(b["estimated_capital_count"]) + int(b["estimated_super_count"])) - (int(a["estimated_capital_count"]) + int(a["estimated_super_count"]))
            bomber_delta = int(b["estimated_bomber_count"]) - int(a["estimated_bomber_count"])
            duration = max(0, int((b["snapshot_at"] - a["snapshot_at"]).total_seconds()))

            t_type = _classify_transition(
                ship_delta, logi_delta, tackle_delta, cap_delta, bomber_delta,
                a, b,
            )
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO operational_force_transitions
                      (viewer_bloc_id, incident_id, from_composition_id,
                       to_composition_id, from_at, to_at, transition_type,
                       ship_count_delta, logistics_delta, tackle_delta,
                       capital_delta, duration_seconds, evidence_json)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        transition_type = VALUES(transition_type),
                        ship_count_delta = VALUES(ship_count_delta),
                        logistics_delta = VALUES(logistics_delta),
                        tackle_delta = VALUES(tackle_delta),
                        capital_delta = VALUES(capital_delta),
                        duration_seconds = VALUES(duration_seconds),
                        evidence_json = VALUES(evidence_json),
                        computed_at = NOW()
                    """,
                    (
                        viewer_bloc_id, inc_id,
                        int(a["comp_id"]), int(b["comp_id"]),
                        a["snapshot_at"], b["snapshot_at"], t_type,
                        ship_delta, logi_delta, tackle_delta, cap_delta,
                        duration,
                        json.dumps({
                            "ship_total_a": a["ship_total"],
                            "ship_total_b": b["ship_total"],
                            "bomber_delta": bomber_delta,
                            "brawl_a": a["brawl_range"], "brawl_b": b["brawl_range"],
                        }, default=str),
                    ),
                )
            written += 1
    conn.commit()
    log.info("phase4.5C force transitions done", {"transitions_written": written})
    return {"compositions": len(rows), "transitions_written": written}


def _classify_transition(ship_delta, logi_delta, tackle_delta, cap_delta, bomber_delta, a, b) -> str:
    if cap_delta >= 1 and (a["estimated_tackle_count"] or 0) > 0 and (a["estimated_capital_count"] or 0) == 0:
        return "tackle_to_capital"
    if cap_delta >= 1:
        return "subcap_to_capital"
    if bomber_delta >= 5:
        return "bomber_reinforcement"
    if logi_delta >= 5 and ship_delta >= 0:
        return "logistics_spike"
    if a["brawl_range"] in ("long", "mid") and b["brawl_range"] == "close":
        return "kite_to_brawl"
    if a["brawl_range"] == "close" and b["brawl_range"] in ("long", "mid"):
        return "brawl_to_kite"
    if ship_delta >= 30:
        return "escalation"
    if ship_delta <= -30:
        return "de_escalation"
    return "unknown"
