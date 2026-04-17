"""Tests for QueryPlan validation + serialization.

Run from ``python/``::

    python -m unittest intel_copilot.test_plan -v
"""

from __future__ import annotations

import unittest

from intel_copilot.plan import (
    EntityRef,
    EntityType,
    GroupBy,
    Intent,
    Metric,
    Operator,
    PlanError,
    QueryPlan,
    Role,
    TimeWindow,
)


class TestPlanValidation(unittest.TestCase):
    def test_top_n_requires_subject(self) -> None:
        plan = QueryPlan(intent=Intent.TOP_N)
        with self.assertRaisesRegex(PlanError, "top_n requires a subject"):
            plan.validate()

    def test_top_n_materializes_group_by_from_subject(self) -> None:
        plan = QueryPlan(
            intent=Intent.TOP_N,
            subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
        )
        plan.validate()
        self.assertIsNotNone(plan.group_by)
        self.assertEqual(plan.group_by.role, Role.ATTACKER)
        self.assertEqual(plan.group_by.entity_type, EntityType.SHIP_TYPE)

    def test_trend_requires_time_interval(self) -> None:
        plan = QueryPlan(intent=Intent.TREND, group_by=GroupBy(role=Role.ANY, entity_type=EntityType.SYSTEM))
        with self.assertRaisesRegex(PlanError, "time_interval"):
            plan.validate()

    def test_count_rejects_non_count_metric(self) -> None:
        plan = QueryPlan(intent=Intent.COUNT, metric=Metric.SUM_ISK)
        with self.assertRaisesRegex(PlanError, "count intent"):
            plan.validate()

    def test_filter_without_value_rejected(self) -> None:
        plan = QueryPlan(
            intent=Intent.COUNT,
            filters=(
                EntityRef(role=Role.VICTIM, entity_type=EntityType.SHIP_GROUP),
            ),
        )
        with self.assertRaisesRegex(PlanError, "missing value"):
            plan.validate()

    def test_limit_bounds(self) -> None:
        for bad in (0, -1, 1001):
            with self.assertRaises(PlanError):
                QueryPlan(intent=Intent.COUNT, limit=bad).validate()

    def test_plan_version_rejected_when_unknown(self) -> None:
        plan = QueryPlan(intent=Intent.COUNT, plan_version="99")
        with self.assertRaisesRegex(PlanError, "plan_version"):
            plan.validate()


class TestPlanSerialization(unittest.TestCase):
    def test_round_trip(self) -> None:
        original = QueryPlan(
            intent=Intent.TOP_N,
            metric=Metric.SUM_ISK,
            subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
            filters=(
                EntityRef(
                    role=Role.VICTIM,
                    entity_type=EntityType.SHIP_GROUP,
                    value="Freighter",
                ),
                EntityRef(
                    role=Role.ATTACKER,
                    entity_type=EntityType.ALLIANCE,
                    operator=Operator.IN,
                    value_id=[99005338, 99010079],
                ),
            ),
            time_window=TimeWindow(from_="now-30d", to="now"),
            limit=5,
        )
        data = original.to_dict()
        self.assertEqual(data["intent"], "top_n")
        self.assertEqual(data["time_window"]["from"], "now-30d")
        self.assertEqual(data["filters"][1]["value_id"], [99005338, 99010079])

        rebuilt = QueryPlan.from_dict(data)
        self.assertEqual(rebuilt.intent, Intent.TOP_N)
        self.assertEqual(rebuilt.metric, Metric.SUM_ISK)
        self.assertEqual(rebuilt.subject.entity_type, EntityType.SHIP_TYPE)
        self.assertEqual(rebuilt.filters[0].value, "Freighter")
        self.assertEqual(rebuilt.time_window.from_, "now-30d")
        self.assertEqual(rebuilt.limit, 5)

    def test_accepts_from_and_from_underscore(self) -> None:
        # LLM may emit either "from" (natural) or "from_" (python-safe).
        for key in ("from", "from_"):
            data = {
                "intent": "count",
                "time_window": {key: "now-1d", "to": "now"},
            }
            plan = QueryPlan.from_dict(data)
            self.assertEqual(plan.time_window.from_, "now-1d")


if __name__ == "__main__":
    unittest.main()
