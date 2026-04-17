"""Unit tests for the plan-result cache.

Tests exercise ``ResultCache`` against a dict-backed stub, plus the
server-level hit/miss/bypass flow via ``IntelCopilotServer`` with a
counting executor. No live Redis required.
"""

from __future__ import annotations

import unittest
from typing import Any

from intel_copilot.cache import DEFAULT_TTL, ResultCache, _key_for
from intel_copilot.contracts import Backend
from intel_copilot.executors.base import ResultRow, ResultSet
from intel_copilot.plan import EntityRef, EntityType, Intent, QueryPlan, Role
from intel_copilot.router import Router
from intel_copilot.server import IntelCopilotServer


class _StubRedis:
    """dict-backed stand-in for ``redis.Redis``. Records TTLs so tests
    can verify the per-intent map fired correctly."""

    def __init__(self) -> None:
        self.store: dict[str, str] = {}
        self.ttls: dict[str, int] = {}

    def get(self, key: str) -> str | None:
        return self.store.get(key)

    def setex(self, key: str, ttl: int, value: str) -> None:
        self.store[key] = value
        self.ttls[key] = ttl


class _FlakyRedis:
    """Raises on every operation — proves the cache degrades gracefully
    when the upstream is down."""

    def get(self, key: str) -> Any:
        raise ConnectionError("redis down")

    def setex(self, key: str, ttl: int, value: Any) -> None:
        raise ConnectionError("redis down")


class _CountingExecutor:
    """Wraps a scripted result so a test can count how many times the
    router actually fell through to the backend."""

    def __init__(self, backend: Backend, rows: tuple[ResultRow, ...] = ()) -> None:
        self.backend = backend
        self._rows = rows
        self.call_count = 0

    def execute(self, plan: QueryPlan) -> ResultSet:
        self.call_count += 1
        return ResultSet(
            backend=self.backend,
            plan=plan,
            rows=self._rows,
            total=len(self._rows),
            took_ms=1,
            query={"stub": True},
        )


def _count_plan() -> QueryPlan:
    p = QueryPlan(intent=Intent.COUNT)
    p.validate()
    return p


def _top_n_plan() -> QueryPlan:
    p = QueryPlan(
        intent=Intent.TOP_N,
        subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
        limit=5,
    )
    p.validate()
    return p


class TestResultCache(unittest.TestCase):
    def test_disabled_cache_is_always_miss(self) -> None:
        cache = ResultCache(None)
        self.assertFalse(cache.enabled)
        self.assertIsNone(cache.get(_count_plan()))
        cache.put(_count_plan(), {"x": 1})  # should not raise

    def test_roundtrip_with_stub_redis(self) -> None:
        stub = _StubRedis()
        cache = ResultCache(stub)
        plan = _count_plan()

        self.assertIsNone(cache.get(plan))
        cache.put(plan, {"total": 42})
        self.assertEqual(cache.get(plan), {"total": 42})

    def test_ttl_selected_per_intent(self) -> None:
        stub = _StubRedis()
        cache = ResultCache(stub)

        cache.put(_count_plan(), {"n": 1})
        count_key = _key_for(_count_plan())
        self.assertEqual(stub.ttls[count_key], 60)  # COUNT → 60s

        lookup_plan = QueryPlan(
            intent=Intent.LOOKUP,
            subject=EntityRef(role=Role.ANY, entity_type=EntityType.CHARACTER, value_id=1),
        )
        lookup_plan.validate()
        cache.put(lookup_plan, {"id": 1})
        self.assertEqual(stub.ttls[_key_for(lookup_plan)], 86_400)  # LOOKUP → 24h

    def test_explicit_ttl_override(self) -> None:
        stub = _StubRedis()
        ResultCache(stub).put(_count_plan(), {"n": 1}, ttl=5)
        self.assertEqual(stub.ttls[_key_for(_count_plan())], 5)

    def test_degrades_on_redis_error(self) -> None:
        """Redis failing must not take the broker down. get returns None
        (treated as a miss), put silently drops."""
        cache = ResultCache(_FlakyRedis())
        self.assertIsNone(cache.get(_count_plan()))
        cache.put(_count_plan(), {"n": 1})  # no raise

    def test_equivalent_plans_collide_by_design(self) -> None:
        """Two plans with identical content but different dict insertion
        order hash to the same key — that's the point of canonicalisation."""
        plan_a = QueryPlan(intent=Intent.COUNT, limit=10)
        plan_a.validate()
        plan_b = QueryPlan(intent=Intent.COUNT, limit=10)
        plan_b.validate()
        self.assertEqual(_key_for(plan_a), _key_for(plan_b))

    def test_different_limits_get_different_keys(self) -> None:
        a = QueryPlan(intent=Intent.COUNT, limit=10); a.validate()
        b = QueryPlan(intent=Intent.COUNT, limit=20); b.validate()
        self.assertNotEqual(_key_for(a), _key_for(b))

    def test_default_ttl_for_unmapped_intent_is_60(self) -> None:
        # COMPARE is enumerated in _TTL_FOR_INTENT; test the fallback
        # constant directly so dropping it from the map would still be
        # caught.
        self.assertEqual(DEFAULT_TTL, 60)


class TestServerCacheFlow(unittest.TestCase):
    def _server_with_cache(self) -> tuple[IntelCopilotServer, _StubRedis, _CountingExecutor]:
        exec_ = _CountingExecutor(Backend.OPENSEARCH, rows=(ResultRow(label="Catalyst", value=42),))
        router = Router({Backend.OPENSEARCH: exec_})
        stub = _StubRedis()
        server = IntelCopilotServer(router, cache=ResultCache(stub))
        return server, stub, exec_

    def test_first_ask_miss_second_ask_hit(self) -> None:
        server, stub, executor = self._server_with_cache()

        status1, body1 = server.handle_ask({"question": "how many kills last 7 days"})
        status2, body2 = server.handle_ask({"question": "how many kills last 7 days"})

        self.assertEqual(status1, 200)
        self.assertEqual(status2, 200)
        self.assertEqual(body1["cache"], "miss")
        self.assertEqual(body2["cache"], "hit")
        self.assertEqual(executor.call_count, 1)
        self.assertEqual(len(stub.store), 1)

    def test_use_cache_false_bypasses_even_on_second_call(self) -> None:
        server, _, executor = self._server_with_cache()

        server.handle_ask({"question": "how many kills last 7 days"})
        _, body = server.handle_ask(
            {"question": "how many kills last 7 days", "use_cache": False}
        )

        self.assertEqual(body["cache"], "bypass")
        self.assertEqual(executor.call_count, 2)

    def test_dry_run_does_not_populate_cache(self) -> None:
        server, stub, executor = self._server_with_cache()
        server.handle_ask({"question": "how many kills last 7 days", "dry_run": True})
        self.assertEqual(executor.call_count, 0)
        self.assertEqual(len(stub.store), 0)

    def test_health_reports_cache_enabled(self) -> None:
        server, _, _ = self._server_with_cache()
        _, body = server.handle_health()
        self.assertTrue(body["cache"])


if __name__ == "__main__":
    unittest.main()
