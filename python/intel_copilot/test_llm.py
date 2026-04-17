"""Unit tests for the Claude-backed plan parser.

No live Anthropic API calls. Tests pass a stub client that returns
pre-scripted Messages-API-shaped payloads, exercising the full path from
``ClaudeLLM.plan`` through ``_extract_tool_input`` into ``LLMPlanParser``
+ ``DictPlanParser`` validation.
"""

from __future__ import annotations

import unittest
from typing import Any

from intel_copilot.llm import (
    PLAN_TOOL,
    SYSTEM_PROMPT,
    ClaudeLLM,
    LLMError,
    LLMPlanParser,
    _extract_tool_input,
)
from intel_copilot.plan import EntityType, Intent, Metric, PlanError, Role


# Canonical MVP plan JSON for "most used ship to kill freighters in last 30 days".
_FREIGHTER_TOP_N = {
    "intent": "top_n",
    "metric": "count",
    "subject": {"role": "attacker", "entity_type": "ship_type"},
    "filters": [
        {"role": "victim", "entity_type": "ship_group", "value": "Freighter"},
    ],
    "time_window": {"from": "now-30d", "to": "now"},
    "limit": 10,
}


def _scripted_response(tool_input: dict[str, Any]) -> dict[str, Any]:
    """Messages-API-shaped response carrying a single tool_use block."""
    return {
        "content": [
            {"type": "tool_use", "name": PLAN_TOOL["name"], "input": tool_input},
        ],
        "stop_reason": "tool_use",
    }


class _StubClient:
    """Stand-in for ``anthropic.Anthropic`` — records the call + returns
    whatever the test scripts."""

    def __init__(self, response: Any) -> None:
        self._response = response
        self.last_call: dict[str, Any] | None = None

    # The real SDK exposes ``client.messages.create``. Mirror that.
    class _Messages:
        def __init__(self, parent: "_StubClient") -> None:
            self._parent = parent

        def create(self, **kwargs: Any) -> Any:
            self._parent.last_call = kwargs
            return self._parent._response

    @property
    def messages(self) -> "_StubClient._Messages":
        return _StubClient._Messages(self)


class TestClaudeLLM(unittest.TestCase):
    def test_plan_extracts_tool_input(self) -> None:
        client = _StubClient(_scripted_response(_FREIGHTER_TOP_N))
        llm = ClaudeLLM(client)

        plan = llm.plan("most used ship to kill freighters in the last 30 days")

        self.assertEqual(plan, _FREIGHTER_TOP_N)

    def test_plan_forces_tool_use(self) -> None:
        """The request must pin ``tool_choice`` to the plan tool — any other
        config would let the model answer in prose, which the rest of the
        broker does not parse."""
        client = _StubClient(_scripted_response(_FREIGHTER_TOP_N))
        llm = ClaudeLLM(client)
        llm.plan("anything")

        call = client.last_call
        assert call is not None
        self.assertEqual(call["tool_choice"], {"type": "tool", "name": "emit_query_plan"})
        self.assertEqual(call["tools"], [PLAN_TOOL])

    def test_system_prompt_is_cached(self) -> None:
        """System prompt is ~1 kB and identical across calls. Ephemeral
        cache_control keeps token spend sane under a Livewire chat loop."""
        client = _StubClient(_scripted_response(_FREIGHTER_TOP_N))
        llm = ClaudeLLM(client)
        llm.plan("anything")

        system = client.last_call["system"]
        self.assertIsInstance(system, list)
        self.assertEqual(system[0]["text"], SYSTEM_PROMPT)
        self.assertEqual(system[0]["cache_control"], {"type": "ephemeral"})

    def test_empty_response_raises(self) -> None:
        client = _StubClient({"content": []})
        with self.assertRaises(LLMError):
            ClaudeLLM(client).plan("anything")

    def test_missing_tool_use_block_raises(self) -> None:
        """Model returning only prose is a refusal — we surface it loudly
        rather than passing empty JSON through validation."""
        client = _StubClient({"content": [{"type": "text", "text": "I can't."}]})
        with self.assertRaises(LLMError):
            ClaudeLLM(client).plan("anything")


class TestLLMPlanParser(unittest.TestCase):
    def test_happy_path_validates_to_queryplan(self) -> None:
        client = _StubClient(_scripted_response(_FREIGHTER_TOP_N))
        parser = LLMPlanParser(ClaudeLLM(client))

        plan = parser.parse("most used ship to kill freighters in the last 30 days")

        self.assertIs(plan.intent, Intent.TOP_N)
        self.assertIs(plan.metric, Metric.COUNT)
        self.assertIsNotNone(plan.subject)
        self.assertIs(plan.subject.role, Role.ATTACKER)
        self.assertIs(plan.subject.entity_type, EntityType.SHIP_TYPE)
        self.assertEqual(len(plan.filters), 1)
        self.assertIs(plan.filters[0].role, Role.VICTIM)
        self.assertIs(plan.filters[0].entity_type, EntityType.SHIP_GROUP)

    def test_invalid_plan_surfaces_as_plan_error(self) -> None:
        """LLM emitting a broken plan is not an LLMError — it's the same
        PlanError the DictPlanParser would raise for handwritten JSON."""
        bad = {"intent": "top_n"}  # missing subject
        parser = LLMPlanParser(ClaudeLLM(_StubClient(_scripted_response(bad))))

        with self.assertRaises(PlanError):
            parser.parse("no subject please")


class TestExtractToolInput(unittest.TestCase):
    def test_accepts_sdk_object_shape(self) -> None:
        """Real SDK returns objects, not dicts. Duck-typing covers both."""

        class _Block:
            type = "tool_use"
            input = dict(_FREIGHTER_TOP_N)

        class _Resp:
            content = [_Block()]

        self.assertEqual(_extract_tool_input(_Resp()), _FREIGHTER_TOP_N)

    def test_non_dict_tool_input_rejected(self) -> None:
        resp = {"content": [{"type": "tool_use", "input": "not a dict"}]}
        with self.assertRaises(LLMError):
            _extract_tool_input(resp)


if __name__ == "__main__":
    unittest.main()
