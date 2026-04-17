"""Idempotent write-back of metrics to battle_character_graph_metrics.

INSERT ... ON DUPLICATE KEY UPDATE keyed on
(battle_id, alliance_id, character_id, edge_profile_version,
 algo_profile_version). Explicit refresh of computed_at so the column
tracks the most recent compute run rather than the row's first insert.
"""

from __future__ import annotations

from datetime import datetime, timezone

import pymysql

from battle_graph.compute import MetricsRow


_UPSERT = """
INSERT INTO battle_character_graph_metrics
  (battle_id, alliance_id, character_id,
   edge_profile_version, algo_profile_version,
   weighted_degree_raw, pagerank_raw, betweenness_raw, clustering_coefficient,
   community_id_raw, community_size, community_rank_by_size,
   pilot_count_in_projection, graph_tier, skip_reason, computed_at)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
    weighted_degree_raw = VALUES(weighted_degree_raw),
    pagerank_raw = VALUES(pagerank_raw),
    betweenness_raw = VALUES(betweenness_raw),
    clustering_coefficient = VALUES(clustering_coefficient),
    community_id_raw = VALUES(community_id_raw),
    community_size = VALUES(community_size),
    community_rank_by_size = VALUES(community_rank_by_size),
    pilot_count_in_projection = VALUES(pilot_count_in_projection),
    graph_tier = VALUES(graph_tier),
    skip_reason = VALUES(skip_reason),
    computed_at = VALUES(computed_at)
"""


def write_skip_rows(
    conn: pymysql.connections.Connection,
    *,
    battle_id: int,
    alliance_id: int,
    character_ids: list[int],
    edge_profile_version: int,
    algo_profile_version: int,
    graph_tier: str,
    skip_reason: str,
) -> None:
    """One row per pilot with skip_reason set. Keeps the downstream
    contract uniform: every pilot on a processed alliance-side has a
    row; skip_reason tells consumers why metrics are null."""
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    pilot_count = len(character_ids)
    params = [
        (
            battle_id, alliance_id, cid,
            edge_profile_version, algo_profile_version,
            None, None, None, None,  # metrics
            None, None, None,          # community
            pilot_count, graph_tier, skip_reason, now,
        )
        for cid in character_ids
    ]
    with conn.cursor() as cur:
        cur.executemany(_UPSERT, params)
    conn.commit()


def write_metrics(
    conn: pymysql.connections.Connection,
    *,
    battle_id: int,
    alliance_id: int,
    metrics: dict[int, MetricsRow],
    edge_profile_version: int,
    algo_profile_version: int,
    graph_tier: str,
) -> None:
    if not metrics:
        return
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    pilot_count = len(metrics)
    params = []
    for cid, m in metrics.items():
        params.append((
            battle_id, alliance_id, cid,
            edge_profile_version, algo_profile_version,
            m.weighted_degree, m.pagerank, m.betweenness, m.clustering_coefficient,
            m.community_id_raw, m.community_size, m.community_rank_by_size,
            pilot_count, graph_tier, None, now,
        ))
    with conn.cursor() as cur:
        cur.executemany(_UPSERT, params)
    conn.commit()
