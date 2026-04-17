"""Unit tests for the SQL executor.

The suite uses a PEP-249-shaped stub connection — no live MariaDB required.
Each test asserts the SQL text + bound params emitted for a given plan so
that schema regressions surface at the builder level, not at integration
time.
"""

from __future__ import annotations

import unittest
from typing import Any

from intel_copilot.contracts import Backend
from intel_copilot.executors.sql import SQLExecutor
from intel_copilot.plan import (
    EntityRef,
    EntityType,
    Intent,
    PlanError,
    QueryPlan,
    Role,
)


class _StubCursor:
    def __init__(self, rows: list[dict[str, Any]]) -> None:
        self._rows = rows
        self.executed: tuple[str, tuple[Any, ...]] | None = None

    def __enter__(self) -> "_StubCursor":
        return self

    def __exit__(self, *_: object) -> None:
        return None

    def execute(self, sql: str, params: tuple[Any, ...]) -> None:
        self.executed = (sql, tuple(params))

    def fetchall(self) -> list[dict[str, Any]]:
        return list(self._rows)


class _StubConnection:
    def __init__(self, rows: list[dict[str, Any]] | None = None) -> None:
        self.cursor_obj = _StubCursor(rows or [])

    def cursor(self) -> _StubCursor:
        return self.cursor_obj


def _lookup_plan(
    entity_type: EntityType,
    *,
    value: str | list[str] | None = None,
    value_id: int | list[int] | None = None,
    limit: int = 5,
) -> QueryPlan:
    plan = QueryPlan(
        intent=Intent.LOOKUP,
        subject=EntityRef(role=Role.ANY, entity_type=entity_type, value=value, value_id=value_id),
        limit=limit,
    )
    plan.validate()
    return plan


class TestSQLBuilder(unittest.TestCase):
    """The SQL text + params must target esi_entity_names and bind
    ``category`` alongside the id/name predicate. The previous iteration
    pointed at nonexistent ``corporations``/``alliances`` tables; that
    regression is what this test guards against."""

    def test_lookup_character_by_id_targets_esi_entity_names(self) -> None:
        conn = _StubConnection(rows=[{"id": 2113167159, "name": "hArD stRuKts", "category": "character"}])
        executor = SQLExecutor(conn)

        plan = _lookup_plan(EntityType.CHARACTER, value_id=2113167159)
        result = executor.execute(plan)

        sql, params = conn.cursor_obj.executed
        self.assertIn("FROM `esi_entity_names`", sql)
        self.assertIn("`category` = %s", sql)
        self.assertIn("`entity_id` IN (%s)", sql)
        self.assertEqual(params, ("character", 2113167159, 5))
        self.assertEqual(result.backend, Backend.SQL)
        self.assertEqual(len(result.rows), 1)
        self.assertEqual(result.rows[0].label, "hArD stRuKts")

    def test_lookup_alliance_by_name(self) -> None:
        conn = _StubConnection(rows=[{"id": 99003581, "name": "Fraternity.", "category": "alliance"}])
        executor = SQLExecutor(conn)

        plan = _lookup_plan(EntityType.ALLIANCE, value="Fraternity.")
        executor.execute(plan)

        sql, params = conn.cursor_obj.executed
        self.assertIn("`name` IN (%s)", sql)
        self.assertEqual(params, ("alliance", "Fraternity.", 5))

    def test_lookup_corporation_id_list(self) -> None:
        """Multiple IDs expand into a parameterised IN-list."""
        conn = _StubConnection(rows=[])
        executor = SQLExecutor(conn)

        plan = _lookup_plan(EntityType.CORPORATION, value_id=[111, 222, 333])
        executor.execute(plan)

        sql, params = conn.cursor_obj.executed
        self.assertIn("`entity_id` IN (%s,%s,%s)", sql)
        self.assertEqual(params, ("corporation", 111, 222, 333, 5))

    def test_unsupported_entity_type_rejected(self) -> None:
        executor = SQLExecutor(_StubConnection())
        plan = QueryPlan(
            intent=Intent.LOOKUP,
            subject=EntityRef(role=Role.ANY, entity_type=EntityType.SYSTEM, value="Jita"),
            limit=1,
        )
        plan.validate()
        with self.assertRaises(PlanError):
            executor.execute(plan)

    def test_non_lookup_intent_rejected(self) -> None:
        """The SQL executor is not a general-purpose runner — it must
        refuse anything except ``lookup`` in phase 1 so misrouting
        surfaces loudly rather than silently."""
        executor = SQLExecutor(_StubConnection())
        plan = QueryPlan(
            intent=Intent.COUNT,
            subject=None,
        )
        plan.validate()
        with self.assertRaises(PlanError):
            executor.execute(plan)


class TestSQLResponseParsing(unittest.TestCase):
    def test_dict_cursor_rows_become_result_rows(self) -> None:
        rows = [
            {"id": 99003581, "name": "Fraternity.", "category": "alliance"},
            {"id": 99011978, "name": "Minmatar Fleet Alliance", "category": "alliance"},
        ]
        executor = SQLExecutor(_StubConnection(rows))
        plan = _lookup_plan(EntityType.ALLIANCE, value=["Fraternity.", "Minmatar Fleet Alliance"])

        result = executor.execute(plan)

        self.assertEqual(result.total, 2)
        self.assertEqual([r.label for r in result.rows], ["Fraternity.", "Minmatar Fleet Alliance"])
        self.assertEqual(result.rows[0].meta["category"], "alliance")


if __name__ == "__main__":
    unittest.main()
