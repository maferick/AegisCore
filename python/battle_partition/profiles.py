"""Resolve partition algorithm versions + auto-pick graph metrics
profile combo from the most recent successful Spec 2 run."""

from __future__ import annotations

from dataclasses import dataclass

import pymysql


@dataclass(frozen=True)
class PartitionRule:
    partition_algo_version: int
    label: str
    min_community_size: int
    orphan_reassignment_rule: str


def load_partition_rule(
    conn: pymysql.connections.Connection,
    version: int | None,
    label: str | None,
) -> PartitionRule:
    with conn.cursor() as cur:
        if version is not None:
            cur.execute(
                "SELECT * FROM battle_sub_fleet_algo_versions WHERE partition_algo_version=%s",
                (version,),
            )
        elif label is not None:
            cur.execute(
                "SELECT * FROM battle_sub_fleet_algo_versions WHERE label=%s",
                (label,),
            )
        else:
            cur.execute(
                "SELECT * FROM battle_sub_fleet_algo_versions WHERE is_default=1",
            )
        row = cur.fetchone()
    if row is None:
        raise RuntimeError("No matching partition rule found")
    return PartitionRule(
        partition_algo_version=int(row["partition_algo_version"]),
        label=str(row["label"]),
        min_community_size=int(row["min_community_size"]),
        orphan_reassignment_rule=str(row["orphan_reassignment_rule"]),
    )


def resolve_graph_profile_combo(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int | None,
    algo_profile_version: int | None,
) -> tuple[int, int]:
    """If the caller pinned either profile, use their values. Otherwise
    look up the most recent successful Spec 2 run for this
    (battle, alliance) and adopt its combo."""
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
