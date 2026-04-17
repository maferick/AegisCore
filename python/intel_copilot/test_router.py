"""Tests for the Router / routing contract.

Run from ``python/``::

    python -m unittest intel_copilot.test_router -v
"""

from __future__ import annotations

import unittest

from intel_copilot.contracts import Backend, RoutingError
from intel_copilot.executors.base import ResultRow, ResultSet
from intel_copilot.plan import (
    EntityRef,
    EntityType,
    Intent,
    Metric,
    QueryPlan,
    Role,
)
from intel_copilot.router import Router


class _StubExecutor:
    """Minimal ``Executor`` for wiring tests."""

    def __init__(self, backend: Backend) -> None:
        self.backend = backend
        self.calls: list[QueryPlan] = []

    def execute(self, plan: QueryPlan) -> ResultSet:
        self.calls.append(plan)
        return ResultSet(
            backend=self.backend,
            plan=plan,
            rows=(ResultRow(label="stub", value=1),),
            total=1,
        )


class TestRouter(unittest.TestCase):
    def test_top_n_routes_to_opensearch(self) -> None:
        os_stub = _StubExecutor(Backend.OPENSEARCH)
        sql_stub = _StubExecutor(Backend.SQL)
        router = Router({Backend.OPENSEARCH: os_stub, Backend.SQL: sql_stub})

        plan = QueryPlan(
            intent=Intent.TOP_N,
            metric=Metric.COUNT,
            subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
        )
        router.execute(plan)
        self.assertEqual(len(os_stub.calls), 1)
        self.assertEqual(len(sql_stub.calls), 0)

    def test_lookup_routes_to_sql(self) -> None:
        os_stub = _StubExecutor(Backend.OPENSEARCH)
        sql_stub = _StubExecutor(Backend.SQL)
        router = Router({Backend.OPENSEARCH: os_stub, Backend.SQL: sql_stub})

        plan = QueryPlan(
            intent=Intent.LOOKUP,
            subject=EntityRef(
                role=Role.ANY,
                entity_type=EntityType.ALLIANCE,
                value="Horde",
            ),
        )
        router.execute(plan)
        self.assertEqual(len(sql_stub.calls), 1)

    def test_raises_when_no_executor_registered_for_intent(self) -> None:
        router = Router({Backend.OPENSEARCH: _StubExecutor(Backend.OPENSEARCH)})
        plan = QueryPlan(
            intent=Intent.LOOKUP,
            subject=EntityRef(
                role=Role.ANY, entity_type=EntityType.ALLIANCE, value="Horde",
            ),
        )
        with self.assertRaises(RoutingError):
            router.execute(plan)

    def test_invalid_plan_rejected_before_dispatch(self) -> None:
        os_stub = _StubExecutor(Backend.OPENSEARCH)
        router = Router({Backend.OPENSEARCH: os_stub})
        plan = QueryPlan(intent=Intent.TOP_N)  # missing subject
        with self.assertRaises(Exception):
            router.execute(plan)
        self.assertEqual(len(os_stub.calls), 0)


if __name__ == "__main__":
    unittest.main()
