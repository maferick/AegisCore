"""Neo4j executor — spatial/topology questions over the SDE graph.

The graph is populated by ``python/graph_universe_sync`` and has this
shape::

    (:Region {id, name})
    (:Constellation {id, name}) -[:IN_REGION]-> (:Region)
    (:System {id, name, security_status}) -[:IN_CONSTELLATION]-> (:Constellation)
    (:System) -[:JUMPS_TO]- (:System)   // undirected gate jumps
    (:System) -[:HAS_STATION]-> (:Station)

Phase-1 intents handled here are spatial only:

* ``PATH``      — shortest jump path between two systems.
* ``NEIGHBORS`` — systems reachable within N jumps of one system.

Coalition / killmail-driven graph questions (third-party detection,
recurring attackers) are explicitly out of scope until someone projects
those relationships into the graph. Adding them means extending this
file plus a projector in ``graph_universe_sync``; they would not
require a new intent.
"""

from __future__ import annotations

import time
from typing import Any, Protocol

from intel_copilot.contracts import Backend
from intel_copilot.executors.base import Executor, ResultRow, ResultSet
from intel_copilot.log import get
from intel_copilot.plan import EntityType, Intent, PlanError, QueryPlan

log = get(__name__)


# Variable-length path patterns cannot take a bound parameter — Cypher
# parses the upper bound at compile time. So we clamp + inline. Both
# bounds stay small because the graph is ~8k systems and an unbounded
# traversal would let a user DoS Neo4j with a single "neighbors within
# 999 jumps" request.
MAX_PATH_HOPS = 50
MAX_NEIGHBOR_HOPS = 10


class Neo4jSessionLike(Protocol):
    """Minimum shape we need from ``neo4j.Session`` — one method.
    Protocol keeps the SDK off the import path for tests."""

    def run(self, query: str, parameters: dict[str, Any] | None = None) -> Any: ...


class Neo4jDriverLike(Protocol):
    """``neo4j.Driver`` is a ContextManager-producing factory. We only
    need ``.session()`` returning something session-shaped."""

    def session(self, **kwargs: Any) -> Any: ...


class Neo4jExecutor:
    """Plan executor backed by the SDE-projected Neo4j graph.

    ``driver`` must be an ``neo4j.Driver`` or any stub exposing
    ``session()`` that returns an object implementing the ``run``
    method. Tests pass a stub that records the Cypher + parameters; the
    production path uses the vendor driver with the same interface.
    """

    backend = Backend.NEO4J

    def __init__(self, driver: Neo4jDriverLike, *, database: str | None = None) -> None:
        self._driver = driver
        self._database = database

    def execute(self, plan: QueryPlan) -> ResultSet:
        t0 = time.monotonic()
        if plan.intent is Intent.PATH:
            query, params = self._path_query(plan)
        elif plan.intent is Intent.NEIGHBORS:
            query, params = self._neighbors_query(plan)
        else:
            raise PlanError(f"Neo4j executor does not handle intent={plan.intent.value}")

        session_kwargs = {"database": self._database} if self._database else {}
        with self._driver.session(**session_kwargs) as session:
            cursor = session.run(query, params)
            records = list(cursor)

        took_ms = int((time.monotonic() - t0) * 1000)
        rows = tuple(_record_to_row(r, plan) for r in records)
        return ResultSet(
            backend=self.backend,
            plan=plan,
            rows=rows,
            total=len(rows),
            took_ms=took_ms,
            query={"cypher": query, "params": params},
        )

    # ------------------------------------------------------------------ #
    # Query translation
    # ------------------------------------------------------------------ #

    def _path_query(self, plan: QueryPlan) -> tuple[str, dict[str, Any]]:
        assert plan.subject is not None  # validate() already enforced
        dest = next(
            f for f in plan.filters if f.entity_type is EntityType.SYSTEM
        )

        max_hops = _clamp(plan.limit, 1, MAX_PATH_HOPS) or MAX_PATH_HOPS

        # ``shortestPath`` needs its upper bound inline (Cypher limitation);
        # clamped above so user input can't blow up traversal. Source /
        # destination values are bound as parameters — safe regardless of
        # input.
        cypher = (
            "MATCH p = shortestPath(\n"
            "  (src:System)-[:JUMPS_TO*.."+str(max_hops)+"]-(dst:System)\n"
            ")\n"
            "WHERE "+_match_clause("src", plan.subject, "src")+"\n"
            "  AND "+_match_clause("dst", dest, "dst")+"\n"
            "RETURN [n IN nodes(p) | n.name] AS path,\n"
            "       length(p) AS hops,\n"
            "       [n IN nodes(p) | n.security_status] AS sec"
        )
        params = {
            **_param_for("src", plan.subject),
            **_param_for("dst", dest),
        }
        return cypher, params

    def _neighbors_query(self, plan: QueryPlan) -> tuple[str, dict[str, Any]]:
        assert plan.subject is not None
        max_hops = _clamp(plan.limit, 1, MAX_NEIGHBOR_HOPS) or MAX_NEIGHBOR_HOPS

        cypher = (
            "MATCH (src:System)\n"
            "WHERE "+_match_clause("src", plan.subject, "src")+"\n"
            "CALL {\n"
            "  WITH src\n"
            "  MATCH p = shortestPath((src)-[:JUMPS_TO*.."+str(max_hops)+"]-(n:System))\n"
            "  WHERE n <> src\n"
            "  RETURN n, length(p) AS hops\n"
            "}\n"
            "RETURN n.name AS name, n.security_status AS sec, hops\n"
            "ORDER BY hops ASC, name ASC"
        )
        return cypher, _param_for("src", plan.subject)


# ---------------------------------------------------------------------- #
# Helpers
# ---------------------------------------------------------------------- #

def _clamp(value: int, low: int, high: int) -> int:
    return max(low, min(high, value))


def _match_clause(alias: str, ref: Any, param_prefix: str) -> str:
    """Build a WHERE clause that matches a :System by id or by case-
    insensitive name. Returning a plain Cypher fragment rather than a
    parameter map keeps the two call sites (``src`` + ``dst``) symmetric
    without needing a helper class."""
    if ref.value_id is not None:
        return f"{alias}.id = ${param_prefix}_id"
    return f"toLower({alias}.name) = toLower(${param_prefix}_name)"


def _param_for(prefix: str, ref: Any) -> dict[str, Any]:
    if ref.value_id is not None:
        return {f"{prefix}_id": ref.value_id}
    return {f"{prefix}_name": ref.value}


def _record_to_row(record: Any, plan: QueryPlan) -> ResultRow:
    """Translate a Neo4j record into the uniform ResultRow shape."""
    if plan.intent is Intent.PATH:
        path = _record_get(record, "path") or []
        hops = _record_get(record, "hops")
        sec = _record_get(record, "sec") or []
        label = " → ".join(str(n) for n in path) if path else "(no path)"
        return ResultRow(
            label=label,
            value=hops if hops is not None else len(path) - 1,
            meta={"path": list(path), "security": list(sec)},
        )

    # NEIGHBORS
    name = _record_get(record, "name")
    hops = _record_get(record, "hops")
    sec = _record_get(record, "sec")
    return ResultRow(
        label=str(name) if name is not None else "?",
        value=int(hops) if hops is not None else 0,
        meta={"security": sec},
    )


def _record_get(record: Any, key: str) -> Any:
    """Neo4j ``Record`` supports both attribute-style and mapping-style
    access in different driver versions; handle both, plus plain dicts
    used in tests."""
    if isinstance(record, dict):
        return record.get(key)
    if hasattr(record, "get"):
        try:
            return record.get(key)
        except Exception:  # noqa: BLE001
            pass
    if hasattr(record, key):
        return getattr(record, key)
    try:
        return record[key]
    except (KeyError, TypeError):
        return None
