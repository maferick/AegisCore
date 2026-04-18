"""Load battle_character_graph_metrics rows for one (battle, alliance,
edge_profile_version, algo_profile_version) combo. The caller must
already hold the shared advisory lock before calling this."""

from __future__ import annotations

from dataclasses import dataclass

import pymysql


@dataclass(frozen=True)
class GraphMetric:
    character_id: int
    community_id_raw: int | None
    community_size: int | None
    community_rank_by_size: int | None
    graph_tier: str
    skip_reason: str | None


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
            SELECT character_id, community_id_raw, community_size,
                   community_rank_by_size, graph_tier, skip_reason
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
            community_id_raw=int(r["community_id_raw"]) if r["community_id_raw"] is not None else None,
            community_size=int(r["community_size"]) if r["community_size"] is not None else None,
            community_rank_by_size=int(r["community_rank_by_size"]) if r["community_rank_by_size"] is not None else None,
            graph_tier=str(r["graph_tier"]),
            skip_reason=str(r["skip_reason"]) if r["skip_reason"] is not None else None,
        )
        for r in rows
    ]


def spec2_run_exists(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
) -> bool:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT 1 FROM battle_graph_projection_runs
            WHERE battle_id=%s AND alliance_id=%s
              AND edge_profile_version=%s AND algo_profile_version=%s
              AND status IN ('success', 'skipped')
            LIMIT 1
            """,
            (battle_id, alliance_id, edge_profile_version, algo_profile_version),
        )
        return cur.fetchone() is not None
