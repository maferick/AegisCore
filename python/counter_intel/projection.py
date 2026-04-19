"""Counter-Intel Dossier — Commit 2: MariaDB → Neo4j projection.

Writes:
  (:CICharacter {character_id, name, current_corp_id, current_alliance_id,
               dominant_role, has_sufficient_history, battles, active_days,
               avg_gang_size, solo_ratio, cooccurrence_density,
               same_side_ratio, affiliation_churn_rate,
               role_fc_pct, role_logi_pct, role_bomber_pct,
               role_command_pct, role_tackle_pct, role_dps_pct,
               distinct_alliances_all_time, distinct_corps_all_time,
               avg_damage_share, days_since_last_activity,
               window_end_date})

  (:CIAlliance {alliance_id, name})

  (:CICharacter)-[:CI_MEMBER_OF {start_at, end_at}]->(:CIAlliance)
    Historical affiliation. One edge per (character, alliance) time-
    segment; gaps allowed. Alliance resolved via
    corporation_alliance_history at the time of the corp-membership.

  (:CICharacter)-[:CI_CO_OCCURS_WITH {
      shared_killmails,
      shared_battles,
      shared_days,
      same_side_count,
      opposing_side_count,
      last_seen_at,
      first_seen_at,
      window_end_date,
   }]-(:CICharacter)
    Promoted only when threshold met:
      shared_battles ≥ CI_EDGE_MIN_BATTLES, OR
      (shared_killmails ≥ CI_EDGE_MIN_KMS AND shared_days ≥ CI_EDGE_MIN_DAYS).
    Keeps fleet-blob cliques out.

Hostility is NOT baked into Alliance nodes — resolved viewer-relative
at render time from MariaDB coalition tables.

Refresh pattern: full rebuild of non-locked partition on each run
(MERGE on Character by character_id; CO_OCCURS rebuilt from scratch
for current window). Daily scheduled invocation.
"""

from __future__ import annotations

from datetime import date, datetime, timedelta, timezone

import pymysql
from neo4j import Driver

from counter_intel.config import Config
from counter_intel.db import neo_session
from counter_intel.log import get

log = get("counter_intel.projection")


def project(conn: pymysql.connections.Connection, driver: Driver, cfg: Config, window_end: date | None = None) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()
    window_start = window_end - timedelta(days=cfg.window_days - 1)
    window_start_dt = datetime.combine(window_start, datetime.min.time(), tzinfo=timezone.utc)
    window_end_dt = datetime.combine(window_end, datetime.max.time(), tzinfo=timezone.utc)

    log.info("projection starting", {"window_end": window_end.isoformat()})

    _ensure_constraints(driver, cfg)

    # 1) Character nodes from ci_character_features_rolling.
    n_chars = _upsert_characters(conn, driver, cfg, window_end)
    log.info("characters upserted", {"n": n_chars})

    # 2) Alliance nodes — only the alliances any scored character
    #    touches (current or historical). Keeps the graph focused.
    alliance_ids = _collect_alliance_ids(conn)
    n_alliances = _upsert_alliances(conn, driver, cfg, alliance_ids)
    log.info("alliances upserted", {"n": n_alliances})

    # 3) CI_MEMBER_OF edges from character_corporation_history + alliance-
    #    at-time.
    n_member_edges = _upsert_member_edges(conn, driver, cfg)
    log.info("member_of edges upserted", {"n": n_member_edges})

    # 4) CI_CO_OCCURS_WITH edges — compute aggregate in MariaDB (threshold
    #    filtered), bulk write to Neo4j.
    n_edges = _upsert_co_occurs(conn, driver, cfg, window_start_dt, window_end_dt, window_end)
    log.info("co_occurs_with edges upserted", {"n": n_edges})

    return {
        "characters": n_chars,
        "alliances": n_alliances,
        "member_edges": n_member_edges,
        "co_occurs_edges": n_edges,
    }


# ----- schema ----------------------------------------------------------


def _ensure_constraints(driver: Driver, cfg: Config) -> None:
    """Idempotent schema bootstrap."""
    stmts = [
        "CREATE CONSTRAINT ci_character_id IF NOT EXISTS FOR (c:CICharacter) REQUIRE c.character_id IS UNIQUE",
        "CREATE CONSTRAINT ci_alliance_id IF NOT EXISTS FOR (a:CIAlliance) REQUIRE a.alliance_id IS UNIQUE",
        "CREATE INDEX ci_character_role IF NOT EXISTS FOR (c:CICharacter) ON (c.dominant_role)",
        "CREATE INDEX ci_character_current_alliance IF NOT EXISTS FOR (c:CICharacter) ON (c.current_alliance_id)",
    ]
    with neo_session(driver, cfg) as sess:
        for s in stmts:
            sess.run(s)


# ----- Characters ------------------------------------------------------


def _upsert_characters(conn, driver: Driver, cfg: Config, window_end: date) -> int:
    """Stream the feature rows into Neo4j in batches. Characters with
    has_sufficient_history=0 are still projected — the UI needs them to
    exist for lookup, just flagged low-signal."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT f.character_id, f.window_end_date, f.has_sufficient_history,
                   f.battles, f.active_days, f.avg_gang_size, f.solo_ratio,
                   f.role_fc_pct, f.role_logi_pct, f.role_bomber_pct,
                   f.role_command_pct, f.role_tackle_pct, f.role_dps_pct,
                   f.dominant_role, f.cooccurrence_density, f.same_side_ratio,
                   f.distinct_corps_in_window, f.distinct_alliances_in_window,
                   f.affiliation_churn_rate,
                   f.distinct_corps_all_time, f.distinct_alliances_all_time,
                   f.avg_damage_share, f.days_since_last_activity,
                   en.name,
                   ch.corporation_id AS current_corp_id
              FROM ci_character_features_rolling f
              LEFT JOIN esi_entity_names en ON en.entity_id = f.character_id AND en.category = 'character'
              LEFT JOIN character_corporation_history ch
                ON ch.character_id = f.character_id AND ch.is_deleted = 0
               AND (ch.end_date IS NULL OR ch.end_date > NOW())
             WHERE f.window_end_date = %s AND f.window_days = %s
            """,
            (window_end, cfg.window_days),
        )
        rows = cur.fetchall()

    # Resolve current alliance via corp-at-now.
    corp_ids = sorted({int(r["current_corp_id"]) for r in rows if r.get("current_corp_id")})
    corp_alliance = _current_alliance_map(conn, corp_ids)
    for r in rows:
        cid_corp = r.get("current_corp_id")
        r["current_alliance_id"] = corp_alliance.get(int(cid_corp)) if cid_corp else None

    total = 0
    batch_size = 1000
    with neo_session(driver, cfg) as sess:
        for i in range(0, len(rows), batch_size):
            batch = rows[i:i + batch_size]
            sess.run(
                """
                UNWIND $batch AS r
                MERGE (c:CICharacter {character_id: r.character_id})
                SET c.name = r.name,
                    c.window_end_date = r.window_end_date,
                    c.has_sufficient_history = r.has_sufficient_history,
                    c.battles = r.battles,
                    c.active_days = r.active_days,
                    c.avg_gang_size = r.avg_gang_size,
                    c.solo_ratio = r.solo_ratio,
                    c.role_fc_pct = r.role_fc_pct,
                    c.role_logi_pct = r.role_logi_pct,
                    c.role_bomber_pct = r.role_bomber_pct,
                    c.role_command_pct = r.role_command_pct,
                    c.role_tackle_pct = r.role_tackle_pct,
                    c.role_dps_pct = r.role_dps_pct,
                    c.dominant_role = r.dominant_role,
                    c.cooccurrence_density = r.cooccurrence_density,
                    c.same_side_ratio = r.same_side_ratio,
                    c.distinct_corps_in_window = r.distinct_corps_in_window,
                    c.distinct_alliances_in_window = r.distinct_alliances_in_window,
                    c.affiliation_churn_rate = r.affiliation_churn_rate,
                    c.distinct_corps_all_time = r.distinct_corps_all_time,
                    c.distinct_alliances_all_time = r.distinct_alliances_all_time,
                    c.avg_damage_share = r.avg_damage_share,
                    c.days_since_last_activity = r.days_since_last_activity,
                    c.current_corp_id = r.current_corp_id,
                    c.current_alliance_id = r.current_alliance_id,
                    c.updated_at = datetime()
                """,
                batch=_normalize_characters_for_neo(batch),
            )
            total += len(batch)
    return total


def _normalize_characters_for_neo(rows: list[dict]) -> list[dict]:
    """Convert Decimal / datetime → plain python scalars Neo4j tolerates."""
    out = []
    for r in rows:
        o: dict = {}
        for k, v in r.items():
            if v is None:
                o[k] = None
            elif hasattr(v, "isoformat"):
                o[k] = v.isoformat()
            else:
                # pymysql Decimal → float for numeric fields
                try:
                    if k in ("avg_gang_size", "solo_ratio", "role_fc_pct", "role_logi_pct",
                             "role_bomber_pct", "role_command_pct", "role_tackle_pct",
                             "role_dps_pct", "cooccurrence_density", "same_side_ratio",
                             "affiliation_churn_rate", "avg_damage_share"):
                        o[k] = float(v)
                    elif k in ("character_id", "battles", "active_days",
                               "distinct_corps_in_window", "distinct_alliances_in_window",
                               "distinct_corps_all_time", "distinct_alliances_all_time",
                               "days_since_last_activity", "has_sufficient_history",
                               "current_corp_id", "current_alliance_id"):
                        o[k] = int(v) if v is not None else None
                    else:
                        o[k] = v
                except (TypeError, ValueError):
                    o[k] = v
        out.append(o)
    return out


def _current_alliance_map(conn, corp_ids: list[int]) -> dict[int, int]:
    """For each corp_id, return its current alliance_id via
    corporation_alliance_history."""
    if not corp_ids:
        return {}
    out: dict[int, int] = {}
    batch = 5000
    for i in range(0, len(corp_ids), batch):
        chunk = corp_ids[i:i + batch]
        ph = ",".join(["%s"] * len(chunk))
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT corporation_id, alliance_id
                  FROM corporation_alliance_history
                 WHERE corporation_id IN ({ph})
                   AND (end_date IS NULL OR end_date > NOW())
                   AND alliance_id IS NOT NULL
                """,
                chunk,
            )
            for r in cur.fetchall():
                out[int(r["corporation_id"])] = int(r["alliance_id"])
    return out


# ----- Alliances -------------------------------------------------------


def _collect_alliance_ids(conn) -> list[int]:
    """Every alliance ever touched by a character in ci_character_features_rolling
    (via corp history → alliance-at-time). Keeps the Alliance projection
    scoped to what the dossier might display."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT DISTINCT cah.alliance_id
              FROM ci_character_features_rolling f
              JOIN character_corporation_history cch ON cch.character_id = f.character_id AND cch.is_deleted = 0
              JOIN corporation_alliance_history cah ON cah.corporation_id = cch.corporation_id
                                                    AND (cah.start_date <= IFNULL(cch.end_date, NOW()))
                                                    AND (cah.end_date IS NULL OR cah.end_date >= cch.start_date)
             WHERE cah.alliance_id IS NOT NULL
            """
        )
        return [int(r["alliance_id"]) for r in cur.fetchall() if r["alliance_id"] is not None]


def _upsert_alliances(conn, driver: Driver, cfg: Config, alliance_ids: list[int]) -> int:
    if not alliance_ids:
        return 0
    names = {}
    batch = 5000
    for i in range(0, len(alliance_ids), batch):
        chunk = alliance_ids[i:i + batch]
        ph = ",".join(["%s"] * len(chunk))
        with conn.cursor() as cur:
            cur.execute(
                f"SELECT entity_id, name FROM esi_entity_names WHERE category='alliance' AND entity_id IN ({ph})",
                chunk,
            )
            for r in cur.fetchall():
                names[int(r["entity_id"])] = str(r["name"])
    rows = [{"alliance_id": aid, "name": names.get(aid, f"#{aid}")} for aid in alliance_ids]
    total = 0
    with neo_session(driver, cfg) as sess:
        for i in range(0, len(rows), 1000):
            b = rows[i:i + 1000]
            sess.run(
                """
                UNWIND $batch AS r
                MERGE (a:CIAlliance {alliance_id: r.alliance_id})
                SET a.name = r.name, a.updated_at = datetime()
                """,
                batch=b,
            )
            total += len(b)
    return total


# ----- CI_MEMBER_OF edges -------------------------------------------------


def _upsert_member_edges(conn, driver: Driver, cfg: Config) -> int:
    """One edge per (character, alliance) time segment derived from
    corp-history × corp-alliance-history. Use deterministic segment key
    to avoid duplicates when rerunning."""
    # For scored characters only — otherwise we'd project 500k chars.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT cch.character_id, cah.alliance_id,
                   GREATEST(cch.start_date, cah.start_date) AS start_at,
                   LEAST(IFNULL(cch.end_date, '9999-12-31'), IFNULL(cah.end_date, '9999-12-31')) AS end_at
              FROM ci_character_features_rolling f
              JOIN character_corporation_history cch ON cch.character_id = f.character_id AND cch.is_deleted = 0
              JOIN corporation_alliance_history cah ON cah.corporation_id = cch.corporation_id
                                                    AND cah.alliance_id IS NOT NULL
                                                    AND cah.start_date <= IFNULL(cch.end_date, NOW())
                                                    AND (cah.end_date IS NULL OR cah.end_date >= cch.start_date)
             WHERE f.has_sufficient_history = 1
            """
        )
        rows = [dict(r) for r in cur.fetchall()]

    # Normalise dates for Neo4j.
    for r in rows:
        r["character_id"] = int(r["character_id"])
        r["alliance_id"] = int(r["alliance_id"])
        for k in ("start_at", "end_at"):
            v = r.get(k)
            if v is None:
                r[k] = None
            elif hasattr(v, "isoformat"):
                r[k] = v.isoformat()
            else:
                r[k] = str(v)

    total = 0
    with neo_session(driver, cfg) as sess:
        # Wipe stale CI_MEMBER_OF for covered characters so reruns stay
        # clean (history can shift if is_deleted flips).
        char_ids = sorted({r["character_id"] for r in rows})
        for i in range(0, len(char_ids), 5000):
            sess.run(
                """
                UNWIND $ids AS cid
                MATCH (c:CICharacter {character_id: cid})-[m:CI_MEMBER_OF]->(:CIAlliance)
                DELETE m
                """,
                ids=char_ids[i:i + 5000],
            )
        for i in range(0, len(rows), 1000):
            b = rows[i:i + 1000]
            sess.run(
                """
                UNWIND $batch AS r
                MATCH (c:CICharacter {character_id: r.character_id})
                MATCH (a:CIAlliance {alliance_id: r.alliance_id})
                CREATE (c)-[:CI_MEMBER_OF {start_at: r.start_at, end_at: r.end_at}]->(a)
                """,
                batch=b,
            )
            total += len(b)
    return total


# ----- CI_CO_OCCURS_WITH --------------------------------------------------


def _upsert_co_occurs(conn, driver: Driver, cfg: Config, ws: datetime, we: datetime, window_end: date) -> int:
    """Weighted full-ingest projection (Commit A of the rework).

    Semantics:
      - SAME-side events = both characters appear as attackers on the
        same killmail.
      - OPPOSING-side events = one character is an attacker while the
        other is the victim on the same killmail.
      - Per-event weight = (1 / sqrt(attacker_count)) × theater_dampener,
        where theater_dampener = 1.0 if km is outside any battle
        theater, else sqrt(10) / sqrt(theater_participant_count)
        floored at 0.15 to prevent a Keepstar brawl from being flatlined.
      - Persistence = `distinct_interactions`, sessions of shared events
        separated by ≥CI_SESSION_GAP_HOURS (default 8h).
      - Two edge types per pair:
          CI_CO_OCCURS_WITH — aggregated SAME-side evidence.
          CI_FOUGHT_AGAINST — aggregated OPPOSING-side evidence.
        A pair can have both.
      - Threshold to promote (per edge independently):
          distinct_interactions >= CI_EDGE_MIN_INTERACTIONS (default 2)
          AND total_weight >= CI_EDGE_MIN_WEIGHT (default 0.5)
      - Diagnostic property `max_single_event_weight_share` on every edge
        so a validation pass can catch single-event-dominated edges.

    Returns total edges written across both types.
    """
    char_ids_sql = """
        SELECT character_id FROM ci_character_features_rolling
         WHERE has_sufficient_history = 1 AND window_end_date = %s AND window_days = %s
    """
    with conn.cursor() as cur:
        cur.execute(char_ids_sql, (window_end, cfg.window_days))
        scored_ids = sorted({int(r["character_id"]) for r in cur.fetchall()})
    if not scored_ids:
        return 0

    # Tunables (read via env on the Config; default values live there).
    session_gap_seconds = cfg.session_gap_hours * 3600
    min_interactions = cfg.edge_min_interactions
    min_weight = cfg.edge_min_weight
    theater_floor = cfg.theater_dampener_floor
    large_theater_threshold = cfg.large_theater_threshold_participants

    # 1) Wipe stale edges for covered characters.
    with neo_session(driver, cfg) as sess:
        for i in range(0, len(scored_ids), 5000):
            sess.run(
                """
                UNWIND $ids AS cid
                MATCH (c:CICharacter {character_id: cid})-[e:CI_CO_OCCURS_WITH|CI_FOUGHT_AGAINST]-()
                DELETE e
                """,
                ids=scored_ids[i:i + 5000],
            )
    log.info("stale edges cleared")

    # 2) Build killmail metadata map for the window.
    #    km_id → (attacker_count, theater_participant_count or None).
    log.info("loading killmail metadata")
    km_meta = _load_killmail_metadata(conn, ws, we)
    log.info("killmail metadata loaded", {"n": len(km_meta)})

    # 3) Fetch SAME-side shared events in bulk.
    log.info("loading same-side events")
    same_events = _load_same_side_events(conn, scored_ids, ws, we)
    log.info("same-side events", {"rows": len(same_events)})

    # 4) Fetch OPPOSING-side shared events in bulk.
    log.info("loading opposing-side events")
    opposing_events = _load_opposing_side_events(conn, scored_ids, ws, we)
    log.info("opposing-side events", {"rows": len(opposing_events)})

    # 5) Per-pair aggregation with weights + sessions.
    same_edges = _aggregate_weighted(
        same_events, km_meta, session_gap_seconds, theater_floor, large_theater_threshold,
    )
    opp_edges = _aggregate_weighted(
        opposing_events, km_meta, session_gap_seconds, theater_floor, large_theater_threshold,
    )
    log.info("pair aggregation complete", {"same_pairs": len(same_edges), "opposing_pairs": len(opp_edges)})

    # 6) Threshold.
    same_kept = [e for e in same_edges if e["distinct_interactions"] >= min_interactions and e["total_weight"] >= min_weight]
    opp_kept = [e for e in opp_edges if e["distinct_interactions"] >= min_interactions and e["total_weight"] >= min_weight]
    log.info("same-side thresholded", {"kept": len(same_kept), "dropped": len(same_edges) - len(same_kept)})
    log.info("opposing-side thresholded", {"kept": len(opp_kept), "dropped": len(opp_edges) - len(opp_kept)})

    # 7) Diagnostic: max_single_event_weight_share distribution.
    _log_weight_diagnostic(same_kept, "CI_CO_OCCURS_WITH")
    _log_weight_diagnostic(opp_kept, "CI_FOUGHT_AGAINST")

    # 8) Write both edge types into Neo4j.
    total = 0
    total += _write_edges(driver, cfg, same_kept, "CI_CO_OCCURS_WITH", window_end)
    total += _write_edges(driver, cfg, opp_kept, "CI_FOUGHT_AGAINST", window_end)
    return total


def _load_killmail_metadata(conn, ws: datetime, we: datetime) -> dict[int, tuple[int, int | None]]:
    """km_id → (attacker_count, theater_participant_count-or-None)."""
    meta: dict[int, tuple[int, int | None]] = {}
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT k.killmail_id, k.attacker_count, bt.participant_count AS theater_pc
              FROM killmails k
              LEFT JOIN battle_theater_killmails btk ON btk.killmail_id = k.killmail_id
              LEFT JOIN battle_theaters bt ON bt.id = btk.theater_id
             WHERE k.killed_at BETWEEN %s AND %s
            """,
            (ws, we),
        )
        for r in cur.fetchall():
            kid = int(r["killmail_id"])
            ac = int(r["attacker_count"] or 1)
            tpc = int(r["theater_pc"]) if r.get("theater_pc") is not None else None
            # LEFT JOIN can produce duplicates if a km appears in
            # multiple theaters (shouldn't but be safe). Keep the largest
            # theater participant count observed.
            existing = meta.get(kid)
            if existing is None or (tpc is not None and (existing[1] is None or tpc > existing[1])):
                meta[kid] = (ac, tpc)
    return meta


def _load_same_side_events(conn, scored_ids: list[int], ws: datetime, we: datetime) -> list[tuple[int, int, int, datetime]]:
    """Return list of (a, b, killmail_id, killed_at) for every pair of
    eligible characters who co-attacked on the same killmail. Keyed a<b."""
    ph = ",".join(["%s"] * len(scored_ids))
    out: list[tuple[int, int, int, datetime]] = []
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT LEAST(ka1.character_id, ka2.character_id) AS a,
                   GREATEST(ka1.character_id, ka2.character_id) AS b,
                   ka1.killmail_id AS kid,
                   k.killed_at AS t
              FROM killmail_attackers ka1
              JOIN killmail_attackers ka2 ON ka2.killmail_id = ka1.killmail_id
                                          AND ka2.character_id IS NOT NULL
                                          AND ka2.character_id > ka1.character_id
              JOIN killmails k ON k.killmail_id = ka1.killmail_id
             WHERE ka1.character_id IN ({ph})
               AND ka2.character_id IN ({ph})
               AND k.killed_at BETWEEN %s AND %s
            """,
            scored_ids + scored_ids + [ws, we],
        )
        for r in cur.fetchall():
            out.append((int(r["a"]), int(r["b"]), int(r["kid"]), r["t"]))
    return out


def _load_opposing_side_events(conn, scored_ids: list[int], ws: datetime, we: datetime) -> list[tuple[int, int, int, datetime]]:
    """attacker vs victim on same km, across eligible characters. Keyed a<b."""
    ph = ",".join(["%s"] * len(scored_ids))
    out: list[tuple[int, int, int, datetime]] = []
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT LEAST(ka.character_id, k.victim_character_id) AS a,
                   GREATEST(ka.character_id, k.victim_character_id) AS b,
                   k.killmail_id AS kid,
                   k.killed_at AS t
              FROM killmails k
              JOIN killmail_attackers ka ON ka.killmail_id = k.killmail_id
                                         AND ka.character_id IS NOT NULL
             WHERE k.victim_character_id IS NOT NULL
               AND ka.character_id <> k.victim_character_id
               AND ka.character_id IN ({ph})
               AND k.victim_character_id IN ({ph})
               AND k.killed_at BETWEEN %s AND %s
            """,
            scored_ids + scored_ids + [ws, we],
        )
        for r in cur.fetchall():
            out.append((int(r["a"]), int(r["b"]), int(r["kid"]), r["t"]))
    return out


def _aggregate_weighted(
    events: list[tuple[int, int, int, datetime]],
    km_meta: dict[int, tuple[int, int | None]],
    session_gap_seconds: int,
    theater_floor: float,
    large_theater_threshold: int,
) -> list[dict]:
    """Group events by pair, compute per-event weight, session counts,
    and weighted aggregates. Returns one dict per pair."""
    from math import sqrt
    from collections import defaultdict

    pair_events: dict[tuple[int, int], list[tuple[datetime, float, int | None]]] = defaultdict(list)
    # One row per event: (killed_at, weight, theater_pc)
    for a, b, kid, t in events:
        ac, tpc = km_meta.get(kid, (1, None))
        attendee = 1.0 / sqrt(max(1, ac))
        if tpc is None:
            theater_damp = 1.0
        else:
            theater_damp = max(theater_floor, sqrt(10.0) / sqrt(max(1, tpc)))
        weight = attendee * theater_damp
        pair_events[(a, b)].append((t, weight, tpc))

    out: list[dict] = []
    for (a, b), rows in pair_events.items():
        rows.sort(key=lambda r: r[0])
        # Sessions.
        interactions = 1 if rows else 0
        prev_t = rows[0][0]
        for t, _w, _tpc in rows[1:]:
            if (t - prev_t).total_seconds() > session_gap_seconds:
                interactions += 1
            prev_t = t
        # Day / week counts.
        days = {t.date() for t, _w, _tpc in rows}
        weeks = {t.isocalendar()[:2] for t, _w, _tpc in rows}
        total_w = sum(w for _t, w, _tpc in rows)
        max_w = max(w for _t, w, _tpc in rows) if rows else 0.0
        share = (max_w / total_w) if total_w > 0 else 0.0
        large_w = sum(w for _t, w, tpc in rows if tpc is not None and tpc >= large_theater_threshold)
        small_w = total_w - large_w
        out.append({
            "a": a,
            "b": b,
            "event_count": len(rows),
            "distinct_interactions": interactions,
            "distinct_days": len(days),
            "distinct_weeks": len(weeks),
            "total_weight": round(total_w, 6),
            "large_theater_weighted_count": round(large_w, 6),
            "non_theater_weighted_count": round(small_w, 6),
            "max_single_event_weight_share": round(share, 4),
            "first_seen_at": rows[0][0].isoformat(),
            "last_seen_at": rows[-1][0].isoformat(),
        })
    return out


def _log_weight_diagnostic(edges: list[dict], label: str) -> None:
    """Emit a single log line with the max_single_event_weight_share
    distribution so the validation pass can eyeball curve-fit quality
    without a separate query."""
    if not edges:
        log.info(f"{label} diagnostic: no edges")
        return
    shares = sorted(e["max_single_event_weight_share"] for e in edges)
    n = len(shares)
    p50 = shares[n // 2]
    p90 = shares[int(n * 0.9)]
    p99 = shares[min(n - 1, int(n * 0.99))]
    hi_dom = sum(1 for s in shares if s >= 0.8)
    log.info(f"{label} weight-share diagnostic", {
        "n_edges": n,
        "p50_max_share": round(p50, 3),
        "p90_max_share": round(p90, 3),
        "p99_max_share": round(p99, 3),
        "edges_single_event_dominated_gte_0_8": hi_dom,
    })


def _write_edges(driver: Driver, cfg: Config, edges: list[dict], rel_type: str, window_end: date) -> int:
    if not edges:
        return 0
    we_iso = window_end.isoformat()
    total = 0
    with neo_session(driver, cfg) as sess:
        for i in range(0, len(edges), 1000):
            batch = edges[i:i + 1000]
            cypher = f"""
                UNWIND $batch AS r
                MATCH (a:CICharacter {{character_id: r.a}})
                MATCH (b:CICharacter {{character_id: r.b}})
                CREATE (a)-[:{rel_type} {{
                    event_count: r.event_count,
                    distinct_interactions: r.distinct_interactions,
                    distinct_days: r.distinct_days,
                    distinct_weeks: r.distinct_weeks,
                    total_weight: r.total_weight,
                    large_theater_weighted_count: r.large_theater_weighted_count,
                    non_theater_weighted_count: r.non_theater_weighted_count,
                    max_single_event_weight_share: r.max_single_event_weight_share,
                    first_seen_at: r.first_seen_at,
                    last_seen_at: r.last_seen_at,
                    window_end_date: $we
                }}]->(b)
            """
            sess.run(cypher, batch=batch, we=we_iso)
            total += len(batch)
    return total


