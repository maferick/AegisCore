"""Natural-language → QueryPlan.

Two parsers live here:

* ``DictPlanParser`` — trivial: takes a ``dict`` (e.g. what an LLM returned
  via structured output) and coerces it into a validated ``QueryPlan``.
  This is the parser the production broker uses. It assumes the LLM
  emitted plan-shaped JSON.

* ``HeuristicPlanParser`` — a tiny rule-based parser that handles a few
  fixed question shapes. It exists so the rest of the stack can be tested
  and demoed without pulling in an LLM dependency. It is **not** meant to
  grow into a general-purpose NLP layer. Anything beyond the fixed shapes
  below should raise rather than guess.
"""

from __future__ import annotations

import re
from typing import Any

from intel_copilot.log import get
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

log = get(__name__)


class DictPlanParser:
    """Coerce an LLM's JSON output into a validated ``QueryPlan``."""

    def parse(self, payload: dict[str, Any]) -> QueryPlan:
        plan = QueryPlan.from_dict(payload)
        plan.validate()
        return plan


# ---------------------------------------------------------------------- #
# Heuristic parser — phrase-level templates, for offline dev + tests.
# ---------------------------------------------------------------------- #

_TIME_RE = re.compile(r"last\s+(\d+)\s*(d|day|days|h|hour|hours|w|week|weeks|m|month|months)", re.I)

_SHIP_GROUP_SYNONYMS = {
    "freighter": "Freighter",
    "freighters": "Freighter",
    "titan": "Titan",
    "titans": "Titan",
    "dreadnought": "Dreadnought",
    "dreadnoughts": "Dreadnought",
    "carrier": "Carrier",
    "carriers": "Carrier",
}


class HeuristicPlanParser:
    """Pattern-match a narrow set of question shapes. Returns ``None`` if no
    template fits — callers should fall back to the LLM parser.
    """

    def parse(self, question: str) -> QueryPlan | None:
        q = question.lower().strip().rstrip("?.")

        plan = self._try_top_attacker_vs_victim(q)
        if plan:
            plan.validate()
            return plan

        plan = self._try_count_kills(q)
        if plan:
            plan.validate()
            return plan

        return None

    # ------------------------------------------------------------------ #

    def _try_top_attacker_vs_victim(self, q: str) -> QueryPlan | None:
        """Match 'most used ship to kill <group>' style."""
        if "most used ship" not in q and "top ship" not in q:
            return None

        victim_group = self._find_ship_group(q)
        if not victim_group:
            return None

        return QueryPlan(
            intent=Intent.TOP_N,
            metric=Metric.COUNT,
            subject=EntityRef(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
            filters=(
                EntityRef(
                    role=Role.VICTIM,
                    entity_type=EntityType.SHIP_GROUP,
                    value=victim_group,
                ),
            ),
            time_window=self._time_window(q),
            group_by=GroupBy(role=Role.ATTACKER, entity_type=EntityType.SHIP_TYPE),
            limit=10,
        )

    def _try_count_kills(self, q: str) -> QueryPlan | None:
        """Match 'how many kills' style — bare total."""
        if "how many" not in q or "kill" not in q:
            return None
        return QueryPlan(
            intent=Intent.COUNT,
            metric=Metric.COUNT,
            time_window=self._time_window(q),
        )

    # ------------------------------------------------------------------ #

    @staticmethod
    def _find_ship_group(q: str) -> str | None:
        for token, canonical in _SHIP_GROUP_SYNONYMS.items():
            if re.search(rf"\b{token}\b", q):
                return canonical
        return None

    @staticmethod
    def _time_window(q: str) -> TimeWindow:
        m = _TIME_RE.search(q)
        if not m:
            return TimeWindow(from_="now-7d", to="now")
        n, unit = int(m.group(1)), m.group(2).lower()
        unit_char = {"d": "d", "day": "d", "days": "d",
                     "h": "h", "hour": "h", "hours": "h",
                     "w": "w", "week": "w", "weeks": "w",
                     "m": "M", "month": "M", "months": "M"}[unit]
        return TimeWindow(from_=f"now-{n}{unit_char}", to="now")
