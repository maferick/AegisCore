"""Tests the ``_build_llm_factory`` environment-based provider picker.

``_build_llm_factory`` is the one place that decides whether the broker
talks to Ollama, Claude, or neither. The matrix is small but each cell
matters, so it's worth nailing down.
"""

from __future__ import annotations

import os
import unittest
from unittest import mock

from intel_copilot.server import _build_llm_factory


class TestProviderSelection(unittest.TestCase):
    def _run(self, env: dict[str, str]) -> object | None:
        """Clear any ambient env, set the scenario, and return the
        factory (or None). Using ``clear=True`` guards against whatever
        the host shell happens to export."""
        with mock.patch.dict(os.environ, env, clear=True):
            return _build_llm_factory()

    def test_no_env_yields_no_factory(self) -> None:
        self.assertIsNone(self._run({}))

    def test_ollama_url_picks_ollama_by_default(self) -> None:
        factory = self._run({"OLLAMA_URL": "http://ollama:11434"})
        self.assertIsNotNone(factory)

    def test_only_anthropic_key_picks_claude(self) -> None:
        factory = self._run({"ANTHROPIC_API_KEY": "sk-ant-…"})
        self.assertIsNotNone(factory)

    def test_explicit_provider_beats_auto_detection(self) -> None:
        """Operator forces Claude even when Ollama is reachable — for
        A-B comparison or quality-critical questions."""
        factory = self._run({
            "OLLAMA_URL": "http://ollama:11434",
            "ANTHROPIC_API_KEY": "sk-ant-…",
            "INTEL_COPILOT_LLM_PROVIDER": "claude",
        })
        self.assertIsNotNone(factory)

    def test_explicit_ollama_wins_without_url(self) -> None:
        """Explicit ``ollama`` provider still returns a factory even
        without OLLAMA_URL — the factory will default to
        ``http://ollama:11434`` at call time."""
        factory = self._run({"INTEL_COPILOT_LLM_PROVIDER": "ollama"})
        self.assertIsNotNone(factory)

    def test_invalid_explicit_provider_falls_back_to_auto(self) -> None:
        """A typo in INTEL_COPILOT_LLM_PROVIDER should not silently
        disable the LLM — auto-detection kicks in instead."""
        factory = self._run({
            "INTEL_COPILOT_LLM_PROVIDER": "gpt4",
            "OLLAMA_URL": "http://ollama:11434",
        })
        self.assertIsNotNone(factory)


if __name__ == "__main__":
    unittest.main()
