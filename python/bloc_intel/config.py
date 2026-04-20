"""Env-driven config for the Bloc Intelligence pipeline.

MariaDB creds follow the AegisCore Python worker convention
(DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD).
"""

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

    # Neo4j — only the project-neo4j subcommand uses these; extract
    # path ignores them so MariaDB-only operators don't need to set
    # Neo4j env.
    neo4j_uri: str
    neo4j_user: str
    neo4j_password: str
    neo4j_database: str

    window_days: int
    decay_half_life_days: float

    # Minimum pilots per alliance in a single killmail for a
    # pair-observation to count. Below this = noise / incidental.
    min_pilots_per_observation: int

    # Pair pruning: don't persist pairs below this observation floor.
    min_pair_n_obs: float

    # Conditional-trigger pruning: require at least this many joint
    # observations with-trigger AND without-trigger before emitting.
    min_conditional_trigger_obs: float

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
            neo4j_uri=env("NEO4J_URI", "bolt://neo4j:7687") or "bolt://neo4j:7687",
            neo4j_user=env("NEO4J_USER", "neo4j") or "neo4j",
            neo4j_password=env("NEO4J_PASSWORD", "") or "",
            neo4j_database=env("NEO4J_DATABASE", "neo4j") or "neo4j",
            window_days=int(env("BI_WINDOW_DAYS", "90") or "90"),
            decay_half_life_days=float(env("BI_DECAY_HALF_LIFE_DAYS", "30") or "30"),
            min_pilots_per_observation=int(env("BI_MIN_PILOTS_PER_OBS", "5") or "5"),
            min_pair_n_obs=float(env("BI_MIN_PAIR_OBS", "5") or "5"),
            min_conditional_trigger_obs=float(env("BI_MIN_CONDITIONAL_OBS", "5") or "5"),
        )
