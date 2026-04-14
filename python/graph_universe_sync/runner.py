"""End-to-end orchestrator for the universe → Neo4j projection.

Steps:
  1. Open MariaDB + Neo4j connections.
  2. (optional) DETACH DELETE owned labels (`--rebuild`).
  3. Bootstrap constraints (idempotent; can be skipped via `--skip-indices`).
  4. Run each enabled stage in dependency order:
       regions → constellations → systems → jumps → stations
  5. Read `ref_snapshot.build_number` for the outbox payload.
  6. Emit `graph.universe_projected` to the MariaDB outbox.

Stages are independent under MERGE semantics, but order matters when
edges reference nodes from a prior stage (e.g. constellations need
regions to MATCH against). The `--only` flag lets operators replay one
stage; the runner respects dependencies but doesn't synthesize missing
predecessors.
"""

from __future__ import annotations

from typing import Callable

import pymysql

from graph_universe_sync.config import Config
from graph_universe_sync.db import connect, fetch_all
from graph_universe_sync.log import get
from graph_universe_sync.neo4j_client import Neo4jClient
from graph_universe_sync.outbox import emit_universe_projected
from graph_universe_sync.projection import (
    project_constellations,
    project_regions,
    project_stargate_edges,
    project_stations,
    project_systems,
)


log = get(__name__)


# Stages in the order required by referential integrity. Each entry is
# (stage-name-as-cli-flag, callable).
StageFn = Callable[..., dict[str, int]]
_STAGES: list[tuple[str, StageFn]] = [
    ("regions", project_regions),
    ("constellations", project_constellations),
    ("systems", project_systems),
    ("jumps", project_stargate_edges),
    ("stations", project_stations),
]


def run(cfg: Config) -> int:
    log.info(
        "graph_universe_sync starting",
        new_eden_only=cfg.new_eden_only,
        dry_run=cfg.dry_run,
        rebuild=cfg.rebuild,
        only_stages=",".join(sorted(cfg.only_stages)) or "all",
        batch_size=cfg.batch_size,
    )

    node_counts: dict[str, int] = {}
    edge_counts: dict[str, int] = {}

    with connect(cfg) as conn:
        with Neo4jClient(cfg) as nj:
            if cfg.rebuild and not cfg.dry_run:
                nj.wipe()
            if not cfg.skip_indices:
                nj.bootstrap_constraints()

            for name, stage in _STAGES:
                if not cfg.stage_enabled(name):
                    log.info("stage skipped", stage=name)
                    continue
                result = stage(
                    conn,
                    nj,
                    batch_size=cfg.batch_size,
                    new_eden_only=cfg.new_eden_only,
                    dry_run=cfg.dry_run,
                )
                node_counts[name] = result.get("nodes", 0)
                edge_counts[name] = result.get("edges", 0)

        # Outbox emission after Neo4j work commits. Skipped on dry-run
        # because the run produced nothing observable to project.
        build_number = _read_build_number(conn)
        if not cfg.dry_run:
            emit_universe_projected(
                conn,
                build_number=build_number,
                node_counts=node_counts,
                edge_counts=edge_counts,
                only_new_eden=cfg.new_eden_only,
            )

    log.info(
        "graph_universe_sync complete",
        nodes_total=sum(node_counts.values()),
        edges_total=sum(edge_counts.values()),
        build_number=build_number,
    )
    return 0


def _read_build_number(conn: pymysql.connections.Connection) -> int | None:
    """Read `ref_snapshot.build_number` so the outbox payload can pin
    the projection to a specific SDE snapshot. Returns None if no
    snapshot has been imported yet (the SDE importer hasn't run)."""
    rows = fetch_all(conn, "SELECT build_number FROM ref_snapshot LIMIT 1")
    if not rows:
        log.warning("no ref_snapshot row — projection will not pin to a build")
        return None
    return int(rows[0]["build_number"])
