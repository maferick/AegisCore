"""Profile-resolution helpers. A run needs an edge profile and an algo
profile; either can be specified by version id, by label, or defaulted
to whichever row carries is_default=1."""

from __future__ import annotations

from dataclasses import dataclass

import pymysql


@dataclass(frozen=True)
class EdgeProfile:
    edge_profile_version: int
    label: str
    bucket_seconds: int
    phase_seconds: int
    same_bucket_coef: float
    victim_overlap_coef: float
    phase_cooccur_coef: float
    min_edge_weight: float


@dataclass(frozen=True)
class AlgoProfile:
    algo_profile_version: int
    label: str
    run_pagerank: bool
    run_betweenness: bool
    run_clustering_coefficient: bool
    run_louvain: bool
    pagerank_damping: float
    pagerank_max_iterations: int
    louvain_max_iterations: int
    louvain_tolerance: float
    small_tier_max: int
    medium_tier_max: int
    large_tier_max: int


def load_edge_profile(
    conn: pymysql.connections.Connection,
    version: int | None,
    label: str | None,
) -> EdgeProfile:
    with conn.cursor() as cur:
        if version is not None:
            cur.execute(
                "SELECT * FROM battle_graph_edge_profile_versions WHERE edge_profile_version=%s",
                (version,),
            )
        elif label is not None:
            cur.execute(
                "SELECT * FROM battle_graph_edge_profile_versions WHERE label=%s",
                (label,),
            )
        else:
            cur.execute(
                "SELECT * FROM battle_graph_edge_profile_versions WHERE is_default=1",
            )
        row = cur.fetchone()
    if row is None:
        raise RuntimeError("No matching edge profile found")
    return EdgeProfile(
        edge_profile_version=int(row["edge_profile_version"]),
        label=str(row["label"]),
        bucket_seconds=int(row["bucket_seconds"]),
        phase_seconds=int(row["phase_seconds"]),
        same_bucket_coef=float(row["same_bucket_coef"]),
        victim_overlap_coef=float(row["victim_overlap_coef"]),
        phase_cooccur_coef=float(row["phase_cooccur_coef"]),
        min_edge_weight=float(row["min_edge_weight"]),
    )


def load_algo_profile(
    conn: pymysql.connections.Connection,
    version: int | None,
    label: str | None,
) -> AlgoProfile:
    with conn.cursor() as cur:
        if version is not None:
            cur.execute(
                "SELECT * FROM battle_graph_algo_profile_versions WHERE algo_profile_version=%s",
                (version,),
            )
        elif label is not None:
            cur.execute(
                "SELECT * FROM battle_graph_algo_profile_versions WHERE label=%s",
                (label,),
            )
        else:
            cur.execute(
                "SELECT * FROM battle_graph_algo_profile_versions WHERE is_default=1",
            )
        row = cur.fetchone()
    if row is None:
        raise RuntimeError("No matching algo profile found")
    return AlgoProfile(
        algo_profile_version=int(row["algo_profile_version"]),
        label=str(row["label"]),
        run_pagerank=bool(row["run_pagerank"]),
        run_betweenness=bool(row["run_betweenness"]),
        run_clustering_coefficient=bool(row["run_clustering_coefficient"]),
        run_louvain=bool(row["run_louvain"]),
        pagerank_damping=float(row["pagerank_damping"]),
        pagerank_max_iterations=int(row["pagerank_max_iterations"]),
        louvain_max_iterations=int(row["louvain_max_iterations"]),
        louvain_tolerance=float(row["louvain_tolerance"]),
        small_tier_max=int(row["small_tier_max"]),
        medium_tier_max=int(row["medium_tier_max"]),
        large_tier_max=int(row["large_tier_max"]),
    )


def tier_for(pilot_count: int, algo: AlgoProfile) -> str:
    if pilot_count <= algo.small_tier_max:
        return "small"
    if pilot_count <= algo.medium_tier_max:
        return "medium"
    if pilot_count <= algo.large_tier_max:
        return "large"
    return "huge"
