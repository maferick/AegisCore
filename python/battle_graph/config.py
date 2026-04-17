"""Env-driven config for battle_graph. DB_* matches the rest of the
Python workers; NEO4J_* matches graph_universe_sync."""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Config:
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    neo4j_host: str
    neo4j_user: str
    neo4j_password: str
    neo4j_database: str

    # Soft caps — defaults match the Spec 2 algo-profile seeds, but
    # the actual tier thresholds are read from the algo-profile row
    # per-run. These exist only as an emergency backstop if someone
    # runs without a profile; the CLI always resolves a profile first.
    default_edge_profile_label: str
    default_algo_profile_label: str

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
            default_edge_profile_label=env("BATTLE_GRAPH_EDGE_PROFILE", "v1_seed"),
            default_algo_profile_label=env("BATTLE_GRAPH_ALGO_PROFILE", "v1_seed"),
        )
