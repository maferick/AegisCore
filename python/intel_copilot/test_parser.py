"""Tests for the heuristic + dict plan parsers.

Run from ``python/``::

    python -m unittest intel_copilot.test_parser -v
"""

from __future__ import annotations

import unittest

from intel_copilot.parser import DictPlanParser, HeuristicPlanParser
from intel_copilot.plan import EntityType, Intent, Role


class TestHeuristicParser(unittest.TestCase):
    def test_most_used_ship_to_kill_freighters(self) -> None:
        plan = HeuristicPlanParser().parse(
            "what is the most used ship to kill freighters in the last 30 days?"
        )
        self.assertIsNotNone(plan)
        self.assertEqual(plan.intent, Intent.TOP_N)
        self.assertEqual(plan.subject.role, Role.ATTACKER)
        self.assertEqual(plan.subject.entity_type, EntityType.SHIP_TYPE)
        self.assertEqual(plan.filters[0].entity_type, EntityType.SHIP_GROUP)
        self.assertEqual(plan.filters[0].value, "Freighter")
        self.assertEqual(plan.time_window.from_, "now-30d")

    def test_how_many_kills_builds_count(self) -> None:
        plan = HeuristicPlanParser().parse("how many kills in the last 24 hours")
        self.assertIsNotNone(plan)
        self.assertEqual(plan.intent, Intent.COUNT)
        self.assertEqual(plan.time_window.from_, "now-24h")

    def test_unmatched_question_returns_none(self) -> None:
        self.assertIsNone(HeuristicPlanParser().parse("explain the universe"))


class TestDictParser(unittest.TestCase):
    def test_parses_example_plan_from_the_spec(self) -> None:
        # Taken verbatim from the project brief.
        payload = {
            "intent": "top_n",
            "subject": {"role": "attacker", "entity_type": "ship_type"},
            "filters": [
                {"role": "victim", "entity_type": "ship_group", "value": "Freighter"},
            ],
            "time_window": {"from": "now-30d", "to": "now"},
            "metric": "count",
        }
        plan = DictPlanParser().parse(payload)
        self.assertEqual(plan.intent, Intent.TOP_N)
        self.assertEqual(plan.subject.role, Role.ATTACKER)

    def test_rejects_invalid_payload(self) -> None:
        with self.assertRaises(Exception):
            DictPlanParser().parse({"intent": "top_n"})  # no subject


if __name__ == "__main__":
    unittest.main()
