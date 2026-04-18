"""Resolve partition algo version + graph profile combo for a Spec 4
run. Mirrors battle_partition.profiles — we auto-pick the most recent
successful Spec 2 run when the caller does not pin explicit versions,
then derive the partition version from the latest membership row
under that combo."""

from __future__ import annotations

import pymysql


def resolve_graph_profile_combo(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int | None,
    algo_profile_version: int | None,
) -> tuple[int, int]:
    if edge_profile_version is not None and algo_profile_version is not None:
        return edge_profile_version, algo_profile_version

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
            f"no successful Spec 2 run found for battle={battle_id} alliance={alliance_id}; "
            "run battle_graph first or pin --edge-profile-version / --algo-profile-version"
        )
    return (
        edge_profile_version or int(row["edge_profile_version"]),
        algo_profile_version or int(row["algo_profile_version"]),
    )


def resolve_partition_algo_version(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int | None,
    edge_profile_version: int,
    algo_profile_version: int,
) -> int:
    """If the caller pinned a version, honour it. Otherwise pick the
    partition_algo_version of the most-recent membership row written
    under this graph profile combo (the version Spec 3 last used)."""
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
            f"no Spec 3 membership found for battle={battle_id} alliance={alliance_id} "
            f"edge={edge_profile_version} algo={algo_profile_version}; run battle_partition first"
        )
    return int(row["partition_algo_version"])
