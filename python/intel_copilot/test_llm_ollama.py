"""Unit tests for the Ollama local-LLM planner.

No live Ollama. A dict-returning stub client replaces the HTTP layer.
Tests verify request shape (grammar-constrained format, temperature)
and parse three response variants the server actually produces:

* clean JSON in message.content (the happy path under grammar mode);
* JSON wrapped in <tool_call>...</tool_call> (Qwen quirk);
* JSON wrapped in a ```json fence (tiny-model over-formatting).
"""

from __future__ import annotations

import json
import unittest
from typing import Any

from intel_copilot.llm import PLAN_TOOL, LLMError, LLMPlanParser
from intel_copilot.llm_ollama import DEFAULT_MODEL, OllamaLLM


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


class _StubHttp:
    def __init__(self, response: dict[str, Any]) -> None:
        self._response = response
        self.last_url: str | None = None
        self.last_payload: dict[str, Any] | None = None
        self.last_timeout: float | None = None

    def post_json(self, url: str, payload: dict[str, Any], *, timeout: float) -> dict[str, Any]:
        self.last_url = url
        self.last_payload = payload
        self.last_timeout = timeout
        return self._response


def _scripted(content: str) -> dict[str, Any]:
    return {
        "message": {"role": "assistant", "content": content},
        "done": True,
    }


class TestOllamaLLM(unittest.TestCase):
    def test_plan_posts_grammar_constrained_request(self) -> None:
        http = _StubHttp(_scripted(json.dumps(_FREIGHTER_TOP_N)))
        llm = OllamaLLM(base_url="http://ollama:11434", http=http)

        result = llm.plan("most used ship to kill freighters in the last 30 days")

        self.assertEqual(result, _FREIGHTER_TOP_N)
        self.assertEqual(http.last_url, "http://ollama:11434/api/chat")
        payload = http.last_payload
        assert payload is not None
        self.assertEqual(payload["model"], DEFAULT_MODEL)
        self.assertFalse(payload["stream"])
        # Grammar constrained to a JSON object. Schema-as-format was
        # tried first but Ollama's validator only accepts a subset of
        # JSON Schema — plain "json" mode is the portable choice.
        self.assertEqual(payload["format"], "json")
        self.assertNotIn("tools", payload)
        # System + user ordering preserved.
        self.assertEqual(payload["messages"][0]["role"], "system")
        self.assertEqual(payload["messages"][1]["role"], "user")

    def test_xml_tool_call_wrapper_is_stripped(self) -> None:
        """Qwen2.5 sometimes still wraps the JSON in <tool_call> tags
        even under grammar mode. Strip and parse."""
        wrapped = f"<tool_call>\n{json.dumps(_FREIGHTER_TOP_N)}\n</tool_call>"
        self.assertEqual(
            OllamaLLM(http=_StubHttp(_scripted(wrapped))).plan("q"),
            _FREIGHTER_TOP_N,
        )

    def test_markdown_fence_is_stripped(self) -> None:
        fenced = f"```json\n{json.dumps(_FREIGHTER_TOP_N)}\n```"
        self.assertEqual(
            OllamaLLM(http=_StubHttp(_scripted(fenced))).plan("q"),
            _FREIGHTER_TOP_N,
        )

    def test_non_json_content_raises(self) -> None:
        with self.assertRaises(LLMError):
            OllamaLLM(http=_StubHttp(_scripted("I refuse."))).plan("q")

    def test_missing_message_raises(self) -> None:
        with self.assertRaises(LLMError):
            OllamaLLM(http=_StubHttp({"done": True})).plan("q")

    def test_empty_content_raises(self) -> None:
        with self.assertRaises(LLMError):
            OllamaLLM(http=_StubHttp(_scripted(""))).plan("q")

    def test_non_dict_json_rejected(self) -> None:
        """Grammar constraint pins shape to object, but belt-and-braces:
        the parser still rejects a list or scalar if it slips through."""
        with self.assertRaises(LLMError):
            OllamaLLM(http=_StubHttp(_scripted("[1,2,3]"))).plan("q")

    def test_temperature_sent_through_options(self) -> None:
        http = _StubHttp(_scripted(json.dumps(_FREIGHTER_TOP_N)))
        OllamaLLM(temperature=0.05, http=http).plan("q")
        self.assertEqual(http.last_payload["options"]["temperature"], 0.05)

    def test_llm_plan_parser_validates_ollama_output(self) -> None:
        http = _StubHttp(_scripted(json.dumps(_FREIGHTER_TOP_N)))
        parser = LLMPlanParser(OllamaLLM(http=http))

        plan = parser.parse("anything")

        self.assertEqual(plan.intent.value, "top_n")
        self.assertEqual(plan.filters[0].value, "Freighter")


if __name__ == "__main__":
    unittest.main()
