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
    """Aggregate in MariaDB, stream to Neo4j.

    Thresholded: shared_battles ≥ N OR (shared_killmails ≥ M AND shared_days ≥ K).
    Undirected edge stored as :CI_CO_OCCURS_WITH between char_a < char_b
    (deterministic direction avoids duplicates).
    """
    # Wipe current-window edges first so rebuild is clean.
    char_ids_sql = """
        SELECT character_id FROM ci_character_features_rolling
         WHERE has_sufficient_history = 1 AND window_end_date = %s AND window_days = %s
    """
    with conn.cursor() as cur:
        cur.execute(char_ids_sql, (window_end, cfg.window_days))
        scored_ids = sorted({int(r["character_id"]) for r in cur.fetchall()})

    if not scored_ids:
        return 0

    with neo_session(driver, cfg) as sess:
        for i in range(0, len(scored_ids), 5000):
            sess.run(
                """
                UNWIND $ids AS cid
                MATCH (c:CICharacter {character_id: cid})-[e:CI_CO_OCCURS_WITH]-()
                WHERE e.window_end_date = $we
                DELETE e
                """,
                ids=scored_ids[i:i + 5000],
                we=window_end.isoformat(),
            )

    # Compute co-occurrence aggregates keyed by (char_a < char_b).
    # Filter to pairs where BOTH characters are in scored_ids.
    ph = ",".join(["%s"] * len(scored_ids))
    agg_sql = f"""
        SELECT LEAST(ka1.character_id, ka2.character_id) AS a,
               GREATEST(ka1.character_id, ka2.character_id) AS b,
               COUNT(DISTINCT ka1.killmail_id) AS shared_killmails,
               COUNT(DISTINCT DATE(k.killed_at)) AS shared_days,
               MIN(k.killed_at) AS first_seen_at,
               MAX(k.killed_at) AS last_seen_at
          FROM killmail_attackers ka1
          JOIN killmail_attackers ka2 ON ka2.killmail_id = ka1.killmail_id
                                      AND ka2.character_id IS NOT NULL
                                      AND ka2.character_id > ka1.character_id
          JOIN killmails k ON k.killmail_id = ka1.killmail_id
         WHERE ka1.character_id IN ({ph})
           AND ka2.character_id IN ({ph})
           AND k.killed_at BETWEEN %s AND %s
         GROUP BY LEAST(ka1.character_id, ka2.character_id),
                  GREATEST(ka1.character_id, ka2.character_id)
        HAVING shared_killmails >= %s
    """
    bindings = list(scored_ids) + list(scored_ids) + [ws, we, cfg.coedge_min_shared_killmails]
    log.info("co_occurs aggregation starting", {"scored": len(scored_ids)})
    with conn.cursor() as cur:
        cur.execute(agg_sql, bindings)
        pairs = cur.fetchall()
    log.info("co_occurs pairs loaded", {"n": len(pairs)})

    # Battle-level shared count — compute separately to avoid huge join.
    # Fold into pairs dict.
    battles_sql = f"""
        SELECT LEAST(p1.character_id, p2.character_id) AS a,
               GREATEST(p1.character_id, p2.character_id) AS b,
               COUNT(DISTINCT p1.theater_id) AS shared_battles
          FROM battle_theater_participants p1
          JOIN battle_theater_participants p2 ON p2.theater_id = p1.theater_id
                                              AND p2.character_id > p1.character_id
          JOIN battle_theaters bt ON bt.id = p1.theater_id
         WHERE p1.character_id IN ({ph})
           AND p2.character_id IN ({ph})
           AND bt.end_time BETWEEN %s AND %s
         GROUP BY LEAST(p1.character_id, p2.character_id),
                  GREATEST(p1.character_id, p2.character_id)
    """
    with conn.cursor() as cur:
        cur.execute(battles_sql, list(scored_ids) + list(scored_ids) + [ws, we])
        battle_map: dict[tuple[int, int], int] = {}
        for r in cur.fetchall():
            battle_map[(int(r["a"]), int(r["b"]))] = int(r["shared_battles"])
    log.info("co_occurs battle pairs loaded", {"n": len(battle_map)})

    # Threshold + merge.
    kept: list[dict] = []
    for p in pairs:
        a = int(p["a"]); b = int(p["b"])
        shared_kms = int(p["shared_killmails"])
        shared_days = int(p["shared_days"])
        shared_battles = battle_map.get((a, b), 0)
        if shared_battles >= cfg.coedge_min_shared_battles or (
            shared_kms >= cfg.coedge_min_shared_killmails and shared_days >= cfg.coedge_min_shared_days
        ):
            kept.append({
                "a": a,
                "b": b,
                "shared_killmails": shared_kms,
                "shared_battles": shared_battles,
                "shared_days": shared_days,
                "first_seen_at": p["first_seen_at"].isoformat() if p["first_seen_at"] else None,
                "last_seen_at": p["last_seen_at"].isoformat() if p["last_seen_at"] else None,
                "window_end_date": window_end.isoformat(),
            })
    log.info("co_occurs pairs above threshold", {"kept": len(kept), "dropped": len(pairs) - len(kept)})

    total = 0
    with neo_session(driver, cfg) as sess:
        for i in range(0, len(kept), 1000):
            b = kept[i:i + 1000]
            sess.run(
                """
                UNWIND $batch AS r
                MATCH (a:CICharacter {character_id: r.a})
                MATCH (b:CICharacter {character_id: r.b})
                CREATE (a)-[:CI_CO_OCCURS_WITH {
                    shared_killmails: r.shared_killmails,
                    shared_battles: r.shared_battles,
                    shared_days: r.shared_days,
                    first_seen_at: r.first_seen_at,
                    last_seen_at: r.last_seen_at,
                    window_end_date: r.window_end_date
                }]->(b)
                """,
                batch=b,
            )
            total += len(b)
    return total
