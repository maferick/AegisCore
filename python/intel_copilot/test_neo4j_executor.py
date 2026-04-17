"""Unit tests for the Neo4j executor.

No live Neo4j. Tests pass a stub driver that captures the Cypher + bound
parameters, and a stub session that replays a scripted set of record
dicts. Assertions are on query structure (shortest-path pattern, hop
bound inlined, WHERE clauses parameter-bound) and on the ResultRow
projections.
"""

from __future__ import annotations

import unittest
from typing import Any

from intel_copilot.contracts import Backend
from intel_copilot.executors.neo4j import (
    MAX_NEIGHBOR_HOPS,
    MAX_PATH_HOPS,
    Neo4jExecutor,
)
from intel_copilot.plan import (
    EntityRef,
    EntityType,
    Intent,
    PlanError,
    QueryPlan,
    Role,
)


class _StubSession:
    def __init__(self, records: list[dict[str, Any]]) -> None:
        self._records = records
        self.executed: tuple[str, dict[str, Any]] | None = None

    def __enter__(self) -> "_StubSession":
        return self

    def __exit__(self, *_: object) -> None:
        return None

    def run(self, query: str, parameters: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        self.executed = (query, dict(parameters or {}))
        return list(self._records)


class _StubDriver:
    def __init__(self, records: list[dict[str, Any]] | None = None) -> None:
        self.session_obj = _StubSession(records or [])
        self.session_kwargs: dict[str, Any] | None = None

    def session(self, **kwargs: Any) -> _StubSession:
        self.session_kwargs = kwargs
        return self.session_obj


def _path_plan(src: str = "Jita", dst: str = "Amarr", limit: int = 30) -> QueryPlan:
    plan = QueryPlan(
        intent=Intent.PATH,
        subject=EntityRef(role=Role.ANY, entity_type=EntityType.SYSTEM, value=src),
        filters=(EntityRef(role=Role.ANY, entity_type=EntityType.SYSTEM, value=dst),),
        limit=limit,
    )
    plan.validate()
    return plan


def _neighbors_plan(src: str = "Jita", limit: int = 3) -> QueryPlan:
    plan = QueryPlan(
        intent=Intent.NEIGHBORS,
        subject=EntityRef(role=Role.ANY, entity_type=EntityType.SYSTEM, value=src),
        limit=limit,
    )
    plan.validate()
    return plan


class TestPathQuery(unittest.TestCase):
    def test_cypher_uses_shortest_path_with_bounded_hops(self) -> None:
        driver = _StubDriver([{
            "path": ["Jita", "Perimeter", "Urlen"],
            "hops": 2,
            "sec": [0.9, 1.0, 1.0],
        }])
        result = Neo4jExecutor(driver).execute(_path_plan(limit=8))

        query, params = driver.session_obj.executed
        self.assertIn("shortestPath", query)
        self.assertIn(":JUMPS_TO*..8", query)
        self.assertIn("toLower(src.name)", query)
        self.assertIn("toLower(dst.name)", query)
        self.assertEqual(params, {"src_name": "Jita", "dst_name": "Amarr"})
        self.assertEqual(result.backend, Backend.NEO4J)
        self.assertEqual(result.rows[0].label, "Jita → Perimeter → Urlen")
        self.assertEqual(result.rows[0].value, 2)

    def test_limit_is_clamped_to_max(self) -> None:
        driver = _StubDriver([])
        Neo4jExecutor(driver).execute(_path_plan(limit=999))
        query, _ = driver.session_obj.executed
        self.assertIn(f":JUMPS_TO*..{MAX_PATH_HOPS}", query)

    def test_id_lookup_uses_id_field(self) -> None:
        """When a caller passes value_id instead of value, Cypher should
        match on ``n.id`` and parameter-bind the integer."""
        plan = QueryPlan(
            intent=Intent.PATH,
            subject=EntityRef(role=Role.ANY, entity_type=EntityType.SYSTEM, value_id=30000142),
            filters=(EntityRef(role=Role.ANY, entity_type=EntityType.SYSTEM, value_id=30002187),),
            limit=20,
        )
        plan.validate()
        driver = _StubDriver([])
        Neo4jExecutor(driver).execute(plan)
        query, params = driver.session_obj.executed
        self.assertIn("src.id = $src_id", query)
        self.assertIn("dst.id = $dst_id", query)
        self.assertEqual(params, {"src_id": 30000142, "dst_id": 30002187})

    def test_missing_path_returns_empty_rows(self) -> None:
        driver = _StubDriver([])
        result = Neo4jExecutor(driver).execute(_path_plan())
        self.assertEqual(result.rows, ())
        self.assertEqual(result.total, 0)


class TestNeighborsQuery(unittest.TestCase):
    def test_cypher_bounds_hops_and_orders_by_distance(self) -> None:
        driver = _StubDriver([
            {"name": "Perimeter", "sec": 1.0, "hops": 1},
            {"name": "New Caldari", "sec": 1.0, "hops": 1},
            {"name": "Urlen", "sec": 1.0, "hops": 2},
        ])
        result = Neo4jExecutor(driver).execute(_neighbors_plan(limit=2))

        query, params = driver.session_obj.executed
        self.assertIn(":JUMPS_TO*..2", query)
        self.assertIn("ORDER BY hops ASC", query)
        self.assertEqual(params, {"src_name": "Jita"})
        self.assertEqual(len(result.rows), 3)
        self.assertEqual(result.rows[0].label, "Perimeter")
        self.assertEqual(result.rows[0].value, 1)

    def test_limit_clamped_to_neighbor_max(self) -> None:
        driver = _StubDriver([])
        Neo4jExecutor(driver).execute(_neighbors_plan(limit=500))
        query, _ = driver.session_obj.executed
        self.assertIn(f":JUMPS_TO*..{MAX_NEIGHBOR_HOPS}", query)


class TestUnsupportedIntent(unittest.TestCase):
    def test_count_plan_rejected(self) -> None:
        driver = _StubDriver([])
        with self.assertRaises(PlanError):
            Neo4jExecutor(driver).execute(QueryPlan(intent=Intent.COUNT))


class TestSessionPlumbing(unittest.TestCase):
    def test_database_kwarg_forwarded(self) -> None:
        driver = _StubDriver([])
        Neo4jExecutor(driver, database="universe").execute(_neighbors_plan())
        self.assertEqual(driver.session_kwargs, {"database": "universe"})

    def test_no_database_means_default_session_args(self) -> None:
        driver = _StubDriver([])
        Neo4jExecutor(driver).execute(_neighbors_plan())
        self.assertEqual(driver.session_kwargs, {})


if __name__ == "__main__":
    unittest.main()
