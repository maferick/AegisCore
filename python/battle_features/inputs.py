"""Load all MariaDB inputs needed to extract Spec 4 v1 features.

Every loader is scoped to one (battle_id, alliance_id) tuple plus the
pinned profile combo. Callers must hold both shared advisory locks
(graph-metrics + partition) before invoking these."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime

import pymysql


@dataclass(frozen=True)
class MembershipRow:
    character_id: int
    sub_fleet_id: int
    was_orphan: bool


@dataclass(frozen=True)
class SubFleetHeader:
    sub_fleet_id: int
    member_count: int
    absorbed_orphan_count: int


@dataclass(frozen=True)
class GraphMetric:
    character_id: int
    weighted_degree_raw: float | None
    pagerank_raw: float | None
    skip_reason: str | None
    graph_tier: str


@dataclass(frozen=True)
class AttackerEvent:
    killmail_id: int
    character_id: int
    alliance_id: int | None
    ship_type_id: int | None
    damage_done: int
    killed_at: datetime


@dataclass(frozen=True)
class VictimEvent:
    killmail_id: int
    character_id: int
    alliance_id: int | None
    killed_at: datetime


def load_memberships(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> list[MembershipRow]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT character_id, sub_fleet_id, was_orphan
              FROM battle_character_sub_fleet_membership
             WHERE battle_id=%s AND alliance_id=%s AND partition_algo_version=%s
            """,
            (battle_id, alliance_id, partition_algo_version),
        )
        rows = cur.fetchall()
    return [
        MembershipRow(
            character_id=int(r["character_id"]),
            sub_fleet_id=int(r["sub_fleet_id"]),
            was_orphan=bool(r["was_orphan"]),
        )
        for r in rows
    ]


def load_sub_fleet_headers(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> list[SubFleetHeader]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT sub_fleet_id, member_count, absorbed_orphan_count
              FROM battle_sub_fleets
             WHERE battle_id=%s AND alliance_id=%s AND partition_algo_version=%s
            """,
            (battle_id, alliance_id, partition_algo_version),
        )
        rows = cur.fetchall()
    return [
        SubFleetHeader(
            sub_fleet_id=int(r["sub_fleet_id"]),
            member_count=int(r["member_count"]),
            absorbed_orphan_count=int(r["absorbed_orphan_count"]),
        )
        for r in rows
    ]


def load_graph_metrics(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
) -> list[GraphMetric]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT character_id, weighted_degree_raw, pagerank_raw,
                   skip_reason, graph_tier
              FROM battle_character_graph_metrics
             WHERE battle_id=%s AND alliance_id=%s
               AND edge_profile_version=%s AND algo_profile_version=%s
            """,
            (battle_id, alliance_id, edge_profile_version, algo_profile_version),
        )
        rows = cur.fetchall()
    return [
        GraphMetric(
            character_id=int(r["character_id"]),
            weighted_degree_raw=float(r["weighted_degree_raw"]) if r["weighted_degree_raw"] is not None else None,
            pagerank_raw=float(r["pagerank_raw"]) if r["pagerank_raw"] is not None else None,
            skip_reason=str(r["skip_reason"]) if r["skip_reason"] is not None else None,
            graph_tier=str(r["graph_tier"]),
        )
        for r in rows
    ]


def load_theater_attacker_events(
    conn: pymysql.connections.Connection,
    theater_id: int,
) -> list[AttackerEvent]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT a.killmail_id, a.character_id, a.alliance_id,
                   a.ship_type_id, a.damage_done, k.killed_at
              FROM battle_theater_killmails btk
              JOIN killmail_attackers a ON a.killmail_id = btk.killmail_id
              JOIN killmails k           ON k.killmail_id = btk.killmail_id
             WHERE btk.theater_id = %s
               AND a.character_id IS NOT NULL
            """,
            (theater_id,),
        )
        rows = cur.fetchall()
    return [
        AttackerEvent(
            killmail_id=int(r["killmail_id"]),
            character_id=int(r["character_id"]),
            alliance_id=int(r["alliance_id"]) if r["alliance_id"] is not None else None,
            ship_type_id=int(r["ship_type_id"]) if r["ship_type_id"] is not None else None,
            damage_done=int(r["damage_done"] or 0),
            killed_at=r["killed_at"],
        )
        for r in rows
    ]


def load_theater_victim_events(
    conn: pymysql.connections.Connection,
    theater_id: int,
) -> list[VictimEvent]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT k.killmail_id, k.victim_character_id AS character_id,
                   k.victim_alliance_id AS alliance_id, k.killed_at
              FROM battle_theater_killmails btk
              JOIN killmails k ON k.killmail_id = btk.killmail_id
             WHERE btk.theater_id = %s
               AND k.victim_character_id IS NOT NULL
            """,
            (theater_id,),
        )
        rows = cur.fetchall()
    return [
        VictimEvent(
            killmail_id=int(r["killmail_id"]),
            character_id=int(r["character_id"]),
            alliance_id=int(r["alliance_id"]) if r["alliance_id"] is not None else None,
            killed_at=r["killed_at"],
        )
        for r in rows
    ]


def load_hull_category_map(
    conn: pymysql.connections.Connection,
) -> dict[int, str]:
    """Map ship_type_id → category. Missing keys imply 'other' (not in
    v1 scope); callers must treat `None` as "unknown category" rather
    than writing a specific label."""
    with conn.cursor() as cur:
        cur.execute("SELECT ship_type_id, category FROM ship_class_category_mapping")
        rows = cur.fetchall()
    return {int(r["ship_type_id"]): str(r["category"]) for r in rows}


def spec3_membership_exists(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> bool:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT 1 FROM battle_character_sub_fleet_membership
             WHERE battle_id=%s AND alliance_id=%s AND partition_algo_version=%s
             LIMIT 1
            """,
            (battle_id, alliance_id, partition_algo_version),
        )
        return cur.fetchone() is not None
