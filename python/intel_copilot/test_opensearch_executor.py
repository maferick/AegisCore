"""Tests for the OpenSearchExecutor query translator.

These tests exercise the plan → OpenSearch DSL mapping without talking to
a real cluster. A stub client captures the body and returns a canned
response shape, so we verify:

  - the produced DSL matches what the index mapping expects
  - response parsing produces the right ``ResultRow`` sequence
  - unsupported role/entity combinations raise ``PlanError``

Run from ``python/``::

    python -m unittest intel_copilot.test_opensearch_executor -v
"""

from __future__ import annotations

import unittest

from intel_copilot.executors.opensearch import OpenSearchExecutor
from intel_copilot.plan import (
    EntityRef,
    EntityType,
    GroupBy,
    Intent,
    Metric,
    PlanError,
    QueryPlan,
    Role,
    TimeWindow,
)


class _StubClient:
    def __init__(self, response: dict) -> None:
        self.response = response
        self.calls: list[tuple[str, dict]] = []

    def search(self, index: str, body: dict) -> dict:
        self.calls.append((index, body))
        return self.response


def _make_executor(response: dict | None = None) -> tuple[OpenSearchExecutor, _StubClient]:
    client = _StubClient(response or {"hits": {"total": {"value": 0}}})
    return OpenSearchExecutor(client, index="killmails"), client


class TestQueryTranslation(unittest.TestCase):
    def test_top_n_attacker_ship_killing_freighters(self) -> None:
        """The canonical MVP question — produces terms agg with the right
        filter on victim_ship_group_name."""
        plan = QueryPlan(
            intent=Intent.TOP_N,
            metric=Metric.COUNT,
            subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
            filters=(
                EntityRef(
                    role=Role.VICTIM,
                    entity_type=EntityType.SHIP_GROUP,
                    value="Freighter",
                ),
            ),
            time_window=TimeWindow(from_="now-30d", to="now"),
            limit=3,
        )
        plan.validate()
        ex, _ = _make_executor()
        body = ex.build_query(plan)

        self.assertEqual(body["size"], 0)
        self.assertIn("aggs", body)
        self.assertEqual(body["aggs"]["buckets"]["terms"]["field"], "final_blow_ship_type_name")
        self.assertEqual(body["aggs"]["buckets"]["terms"]["size"], 3)

        must = body["query"]["bool"]["must"]
        # One range clause + one filter term clause.
        self.assertEqual(len(must), 2)
        self.assertEqual(must[0]["range"]["killed_at"]["gte"], "now-30d")
        self.assertEqual(must[1]["term"]["victim_ship_group_name"], "Freighter")

    def test_top_n_sum_isk_orders_by_metric(self) -> None:
        plan = QueryPlan(
            intent=Intent.TOP_N,
            metric=Metric.SUM_ISK,
            subject=EntityRef(role=Role.VICTIM, entity_type=EntityType.ALLIANCE),
            limit=5,
        )
        plan.validate()
        ex, _ = _make_executor()
        body = ex.build_query(plan)
        terms = body["aggs"]["buckets"]["terms"]
        self.assertEqual(terms["order"], {"sum_isk": "desc"})
        self.assertIn("sum_isk", body["aggs"]["buckets"]["aggs"])

    def test_attacker_alliance_id_filter_uses_denormalized_array(self) -> None:
        plan = QueryPlan(
            intent=Intent.COUNT,
            filters=(
                EntityRef(
                    role=Role.ATTACKER,
                    entity_type=EntityType.ALLIANCE,
                    value_id=[99005338],
                ),
            ),
        )
        plan.validate()
        ex, _ = _make_executor()
        body = ex.build_query(plan)
        must = body["query"]["bool"]["must"]
        self.assertEqual(must[0]["terms"], {"attacker_alliance_ids": [99005338]})

    def test_trend_builds_date_histogram(self) -> None:
        plan = QueryPlan(
            intent=Intent.TREND,
            metric=Metric.COUNT,
            group_by=GroupBy(role=Role.ANY, entity_type=EntityType.SYSTEM, time_interval="1d"),
            time_window=TimeWindow(from_="now-7d", to="now"),
        )
        plan.validate()
        ex, _ = _make_executor()
        body = ex.build_query(plan)
        self.assertEqual(
            body["aggs"]["buckets"]["date_histogram"]["fixed_interval"], "1d"
        )

    def test_unsupported_role_raises(self) -> None:
        plan = QueryPlan(
            intent=Intent.COUNT,
            filters=(
                EntityRef(role=Role.ATTACKER, entity_type=EntityType.REGION, value="The Forge"),
            ),
        )
        plan.validate()
        ex, _ = _make_executor()
        with self.assertRaises(PlanError):
            ex.build_query(plan)

    def test_empty_plan_uses_match_all(self) -> None:
        plan = QueryPlan(intent=Intent.COUNT, time_window=TimeWindow(from_=None, to="now"))
        plan.validate()
        ex, _ = _make_executor()
        body = ex.build_query(plan)
        self.assertEqual(body["query"], {"match_all": {}})


class TestResponseParsing(unittest.TestCase):
    def test_top_n_parses_buckets(self) -> None:
        ex, client = _make_executor({
            "hits": {"total": {"value": 3030}},
            "aggregations": {
                "buckets": {
                    "buckets": [
                        {"key": "Catalyst", "doc_count": 1432},
                        {"key": "Tornado", "doc_count": 987},
                        {"key": "Talos", "doc_count": 611},
                    ]
                }
            },
        })
        plan = QueryPlan(
            intent=Intent.TOP_N,
            subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
            limit=3,
        )
        plan.validate()
        result = ex.execute(plan)
        self.assertEqual(len(result.rows), 3)
        self.assertEqual(result.rows[0].label, "Catalyst")
        self.assertEqual(result.rows[0].value, 1432)
        self.assertEqual(result.total, 3030)

    def test_count_returns_only_total(self) -> None:
        ex, _ = _make_executor({"hits": {"total": {"value": 500}}})
        plan = QueryPlan(intent=Intent.COUNT)
        plan.validate()
        result = ex.execute(plan)
        self.assertEqual(result.total, 500)
        self.assertEqual(result.rows, ())

    def test_trend_uses_key_as_string_when_present(self) -> None:
        ex, _ = _make_executor({
            "hits": {"total": {"value": 10}},
            "aggregations": {
                "buckets": {
                    "buckets": [
                        {"key": 0, "key_as_string": "2026-04-10", "doc_count": 5},
                        {"key": 86400000, "key_as_string": "2026-04-11", "doc_count": 5},
                    ]
                }
            },
        })
        plan = QueryPlan(
            intent=Intent.TREND,
            group_by=GroupBy(role=Role.ANY, entity_type=EntityType.SYSTEM, time_interval="1d"),
        )
        plan.validate()
        result = ex.execute(plan)
        self.assertEqual(result.rows[0].label, "2026-04-10")
        self.assertEqual(result.rows[1].value, 5)


if __name__ == "__main__":
    unittest.main()
