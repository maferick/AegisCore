"""Tests for the HTTP broker.

Drives handler methods directly — no socket, no real executor. The
``IntelCopilotServer.handle_*`` methods return ``(status, dict)``, which
is cheap to assert against.
"""

from __future__ import annotations

import unittest
from typing import Any
from unittest.mock import MagicMock

from intel_copilot.contracts import Backend, RoutingError
from intel_copilot.executors.base import ResultRow, ResultSet
from intel_copilot.plan import EntityRef, EntityType, Intent, QueryPlan, Role
from intel_copilot.router import Router
from intel_copilot.server import IntelCopilotServer


class _StubExecutor:
    def __init__(self, backend: Backend, rows: tuple[ResultRow, ...] = ()) -> None:
        self.backend = backend
        self._rows = rows
        self.calls: list[QueryPlan] = []

    def execute(self, plan: QueryPlan) -> ResultSet:
        self.calls.append(plan)
        return ResultSet(
            backend=self.backend,
            plan=plan,
            rows=self._rows,
            total=len(self._rows),
            took_ms=1,
            query={"stub": True},
        )


def _stub_server(*, rows: tuple[ResultRow, ...] = (), with_llm: bool = False,
                 api_token: str | None = None) -> tuple[IntelCopilotServer, Any]:
    os_exec = _StubExecutor(Backend.OPENSEARCH, rows=rows)
    router = Router({Backend.OPENSEARCH: os_exec})

    llm_parser = MagicMock()
    llm_parser.parse.return_value = QueryPlan(
        intent=Intent.TOP_N,
        subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
        limit=5,
    )

    factory = (lambda: llm_parser) if with_llm else None
    return IntelCopilotServer(router, llm_planner_factory=factory, api_token=api_token), llm_parser


class TestHealth(unittest.TestCase):
    def test_health_reports_registered_backends(self) -> None:
        server, _ = _stub_server()
        status, body = server.handle_health()
        self.assertEqual(status, 200)
        self.assertTrue(body["ok"])
        self.assertEqual(body["backends"], ["opensearch"])
        self.assertFalse(body["llm"])

    def test_health_flags_llm_when_factory_present(self) -> None:
        server, _ = _stub_server(with_llm=True)
        _, body = server.handle_health()
        self.assertTrue(body["llm"])


class TestAsk(unittest.TestCase):
    def test_heuristic_match_executes_plan(self) -> None:
        rows = (ResultRow(label="Catalyst", value=42),)
        server, _ = _stub_server(rows=rows)

        status, body = server.handle_ask({"question": "how many kills last 7 days"})

        self.assertEqual(status, 200)
        self.assertEqual(body["parser"], "heuristic")
        self.assertEqual(body["plan"]["intent"], "count")
        self.assertEqual(body["result"]["rows"][0]["label"], "Catalyst")
        self.assertEqual(body["result"]["backend"], "opensearch")

    def test_dry_run_skips_execution(self) -> None:
        server, _ = _stub_server()
        status, body = server.handle_ask({"question": "how many kills", "dry_run": True})
        self.assertEqual(status, 200)
        self.assertIsNone(body["result"])

    def test_unmatched_heuristic_without_llm_is_422(self) -> None:
        server, _ = _stub_server()
        status, body = server.handle_ask({"question": "explain capital warfare"})
        self.assertEqual(status, 422)
        self.assertIn("heuristic", body["error"])

    def test_unmatched_heuristic_falls_through_to_llm(self) -> None:
        server, llm = _stub_server(with_llm=True)
        status, body = server.handle_ask(
            {"question": "explain capital warfare", "use_llm": True}
        )
        self.assertEqual(status, 200)
        self.assertEqual(body["parser"], "llm")
        llm.parse.assert_called_once_with("explain capital warfare")

    def test_missing_question_is_400(self) -> None:
        server, _ = _stub_server()
        status, body = server.handle_ask({})
        self.assertEqual(status, 400)
        self.assertIn("question", body["error"])


class TestPlan(unittest.TestCase):
    def test_valid_plan_executes(self) -> None:
        server, _ = _stub_server(rows=(ResultRow(label="Catalyst", value=1),))
        payload = {
            "intent": "top_n",
            "subject": {"role": "attacker", "entity_type": "ship_type"},
            "limit": 5,
        }
        status, body = server.handle_plan(payload)
        self.assertEqual(status, 200)
        self.assertEqual(body["parser"], "dict")
        self.assertEqual(body["plan"]["intent"], "top_n")

    def test_invalid_plan_is_422(self) -> None:
        server, _ = _stub_server()
        status, body = server.handle_plan({"intent": "top_n"})  # no subject
        self.assertEqual(status, 422)
        self.assertIn("subject", body["error"])


class TestRoutingFailures(unittest.TestCase):
    def test_missing_executor_surfaces_as_422(self) -> None:
        """No SQL executor → lookup plans must return a clean 422, not
        a 500, so the Laravel side can show a friendly message."""
        server = IntelCopilotServer(Router({Backend.OPENSEARCH: _StubExecutor(Backend.OPENSEARCH)}))
        status, body = server.handle_plan({
            "intent": "lookup",
            "subject": {"role": "any", "entity_type": "character", "value_id": 123},
        })
        self.assertEqual(status, 422)
        self.assertIn("lookup", body["error"])


if __name__ == "__main__":
    unittest.main()
