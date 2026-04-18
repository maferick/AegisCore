"""Resolve partition algo version + edge/algo profile combo by looking
up the most-recent successful upstream runs. Mirrors the convention
used by battle_features.profiles."""

from __future__ import annotations

import pymysql


def resolve_graph_profile_combo(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
) -> tuple[int, int]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT edge_profile_version, algo_profile_version
              FROM battle_graph_projection_runs
             WHERE battle_id=%s AND alliance_id=%s AND status='success'
             ORDER BY completed_at DESC
             LIMIT 1
            """,
            (battle_id, alliance_id),
        )
        row = cur.fetchone()
    if row is None:
        raise RuntimeError(
            f"no successful Spec 2 run for battle={battle_id} alliance={alliance_id}"
        )
    return int(row["edge_profile_version"]), int(row["algo_profile_version"])


def resolve_partition_algo_version(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int | None,
    edge_profile_version: int,
    algo_profile_version: int,
) -> int:
    if partition_algo_version is not None:
        return partition_algo_version

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT partition_algo_version, MAX(computed_at) AS last_written
              FROM battle_character_sub_fleet_membership
             WHERE battle_id=%s AND alliance_id=%s
               AND source_edge_profile_version=%s
               AND source_algo_profile_version=%s
             GROUP BY partition_algo_version
             ORDER BY last_written DESC
             LIMIT 1
            """,
            (battle_id, alliance_id, edge_profile_version, algo_profile_version),
        )
        row = cur.fetchone()
    if row is None:
        raise RuntimeError(
            f"no Spec 3 membership for battle={battle_id} alliance={alliance_id} "
            f"edge={edge_profile_version} algo={algo_profile_version}"
        )
    return int(row["partition_algo_version"])
