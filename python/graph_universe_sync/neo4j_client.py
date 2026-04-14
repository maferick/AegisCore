"""Thin wrapper over the official `neo4j` Python driver.

Exposes a `Neo4jClient` with:
  - `session()` context manager (enforces the configured database).
  - `bootstrap_constraints()` — idempotent CREATE CONSTRAINT DDL for the
    universe projection labels (System/Region/Constellation/Station id
    uniqueness).
  - `wipe()` — DETACH DELETE for the labels we own. Used by `--rebuild`.

Driver lifetime is per-process: `__enter__` opens the driver, `__exit__`
closes it. Sessions are short-lived and acquired per stage.
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

from neo4j import Driver, GraphDatabase, Session

from graph_universe_sync.config import Config
from graph_universe_sync.log import get


log = get(__name__)


# Constraints we own on the universe projection. Listed in label order so
# logs read top-down. `IF NOT EXISTS` makes the bootstrap idempotent.
_CONSTRAINTS: tuple[str, ...] = (
    "CREATE CONSTRAINT region_id_unique IF NOT EXISTS "
    "FOR (r:Region) REQUIRE r.id IS UNIQUE",
    "CREATE CONSTRAINT constellation_id_unique IF NOT EXISTS "
    "FOR (c:Constellation) REQUIRE c.id IS UNIQUE",
    "CREATE CONSTRAINT system_id_unique IF NOT EXISTS "
    "FOR (s:System) REQUIRE s.id IS UNIQUE",
    "CREATE CONSTRAINT station_id_unique IF NOT EXISTS "
    "FOR (st:Station) REQUIRE st.id IS UNIQUE",
)

# Labels we manage. `--rebuild` wipes these (and anything attached to
# them via DETACH DELETE) before MERGE.
_OWNED_LABELS: tuple[str, ...] = ("Region", "Constellation", "System", "Station")


class Neo4jClient:
    """Driver + session helpers."""

    __slots__ = ("_cfg", "_driver")

    def __init__(self, cfg: Config) -> None:
        self._cfg = cfg
        self._driver: Driver | None = None

    def __enter__(self) -> "Neo4jClient":
        # Auth tuple keeps it simple — no token-based auth on the
        # internal compose network in phase 1.
        self._driver = GraphDatabase.driver(
            self._cfg.neo4j_host,
            auth=(self._cfg.neo4j_user, self._cfg.neo4j_password),
        )
        # `verify_connectivity()` raises early if the bolt port doesn't
        # answer or auth is wrong, so we fail with a clear message
        # instead of choking inside a stage.
        self._driver.verify_connectivity()
        log.info(
            "neo4j driver opened",
            host=self._cfg.neo4j_host,
            database=self._cfg.neo4j_database,
        )
        return self

    def __exit__(self, exc_type, exc, tb) -> None:
        if self._driver is not None:
            self._driver.close()
            self._driver = None

    @contextmanager
    def session(self) -> Iterator[Session]:
        if self._driver is None:
            raise RuntimeError("Neo4jClient used outside `with` block")
        with self._driver.session(database=self._cfg.neo4j_database) as s:
            yield s

    # -- Bootstrap / teardown --------------------------------------------------

    def bootstrap_constraints(self) -> None:
        """Run the IF NOT EXISTS DDL for owned labels. Cheap to repeat."""
        with self.session() as s:
            for ddl in _CONSTRAINTS:
                s.run(ddl)
        log.info("constraints ensured", count=len(_CONSTRAINTS))

    def wipe(self) -> None:
        """Drop everything attached to our owned labels.

        Uses CALL { ... } IN TRANSACTIONS to avoid blowing the heap on a
        full universe (~8k systems, ~7.5k jump edges, ~3-4k stations).
        """
        with self.session() as s:
            for label in _OWNED_LABELS:
                s.run(
                    f"MATCH (n:{label}) "
                    f"CALL {{ WITH n DETACH DELETE n }} IN TRANSACTIONS OF 1000 ROWS"
                )
        log.info("wiped owned labels", labels=",".join(_OWNED_LABELS))
