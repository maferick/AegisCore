"""Runtime configuration for graph_universe_sync.

Mirrors the MariaDB env-var convention used by sde_importer
(`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) and
adds Neo4j (`NEO4J_HOST`, `NEO4J_USER`, `NEO4J_PASSWORD`). Compose
passes everything through; no dotenv reads here.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, replace


# Stages we know how to project. `--only` validates against this set.
KNOWN_STAGES: frozenset[str] = frozenset({
    "regions",
    "constellations",
    "systems",
    "jumps",
    "stations",
})


@dataclass(frozen=True)
class Config:
    # MariaDB (source of truth for ref_* tables).
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # Neo4j (destination — the projection we build).
    neo4j_host: str          # e.g. "bolt://neo4j:7687"
    neo4j_user: str
    neo4j_password: str
    neo4j_database: str      # default DB name; "neo4j" in single-DB setups

    # Batching: tuned for ~1MB UNWIND payloads in Neo4j Bolt.
    batch_size: int

    # Filtering: New Eden cluster IDs are 30000000..30999999. The phase-1
    # graph only covers known space; wormhole and abyssal systems live
    # outside the in-game stargate graph and would only add noise.
    new_eden_only: bool

    # Op modes
    dry_run: bool                  # log everything; never write to Neo4j
    rebuild: bool                  # DETACH DELETE before MERGE (full rebuild)
    skip_indices: bool             # don't run constraint DDL (idempotent regardless)
    only_stages: frozenset[str]    # subset of KNOWN_STAGES; empty = all stages

    @classmethod
    def from_env(cls, **overrides) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            v = os.environ.get(key, default)
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        cfg = cls(
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306")),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            neo4j_host=env("NEO4J_HOST", "bolt://neo4j:7687"),
            neo4j_user=env("NEO4J_USER", "neo4j"),
            neo4j_password=env("NEO4J_PASSWORD", required=True),
            neo4j_database=env("NEO4J_DATABASE", "neo4j"),
            batch_size=int(env("GRAPH_BATCH_SIZE", "2000")),
            new_eden_only=env("NEW_EDEN_ONLY", "true").lower() != "false",
            dry_run=False,
            rebuild=False,
            skip_indices=False,
            only_stages=frozenset(),
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg

    def stage_enabled(self, stage: str) -> bool:
        """`--only` is additive: empty = run everything; otherwise subset filter."""
        if not self.only_stages:
            return True
        return stage in self.only_stages
