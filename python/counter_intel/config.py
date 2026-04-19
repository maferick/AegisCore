"""Env-driven config. DB credentials share the AegisCore Python worker
convention (DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD).
Neo4j creds follow the NEO4J_* pattern used by battle_graph +
graph_universe_sync.
"""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Config:
    # MariaDB.
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # Neo4j.
    neo4j_host: str
    neo4j_user: str
    neo4j_password: str
    neo4j_database: str

    # Tuning.
    window_days: int
    min_appearances_90d: int  # min killmail appearances (attacker+victim) for eligibility
    coedge_min_shared_battles: int
    coedge_min_shared_killmails: int
    coedge_min_shared_days: int

    # Weighted projection tunables (rework).
    session_gap_hours: int
    edge_min_interactions: int
    edge_min_weight: float
    theater_dampener_floor: float
    large_theater_threshold_participants: int

    @classmethod
    def from_env(cls) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            v = os.environ.get(key)
            if not v:
                v = default
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        return cls(
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306") or "3306"),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            neo4j_host=env("NEO4J_HOST", "bolt://neo4j:7687"),
            neo4j_user=env("NEO4J_USER", "neo4j"),
            neo4j_password=env("NEO4J_PASSWORD", required=True),
            neo4j_database=env("NEO4J_DATABASE", "neo4j"),
            window_days=int(env("CI_WINDOW_DAYS", "90") or "90"),
            min_appearances_90d=int(env("CI_MIN_APPEARANCES_90D", "5") or "5"),
            coedge_min_shared_battles=int(env("CI_EDGE_MIN_BATTLES", "3") or "3"),
            coedge_min_shared_killmails=int(env("CI_EDGE_MIN_KMS", "5") or "5"),
            coedge_min_shared_days=int(env("CI_EDGE_MIN_DAYS", "2") or "2"),
            session_gap_hours=int(env("CI_SESSION_GAP_HOURS", "8") or "8"),
            edge_min_interactions=int(env("CI_EDGE_MIN_INTERACTIONS", "2") or "2"),
            edge_min_weight=float(env("CI_EDGE_MIN_WEIGHT", "0.5") or "0.5"),
            theater_dampener_floor=float(env("CI_THEATER_DAMP_FLOOR", "0.15") or "0.15"),
            large_theater_threshold_participants=int(env("CI_LARGE_THEATER_THRESHOLD", "100") or "100"),
        )
