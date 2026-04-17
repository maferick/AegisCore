"""Ollama-backed local LLM planner.

Drop-in replacement for ``ClaudeLLM`` that runs a quantised open-weight
model on CPU or GPU through the Ollama HTTP server. Same plan shape in,
same dict out — the rest of the broker (``LLMPlanParser``, validation,
executors) cannot tell which provider emitted the plan.

Why a local option
------------------

* Zero per-request cost once the model is pulled.
* No data leaves the stack — the question text never hits a third-party
  API, which matters for operator-only dashboards that may include IDs
  or coalition-internal language.
* Recovers gracefully when the Anthropic quota is out or the API is
  degraded. The broker config picks whichever provider is actually
  reachable.

Model choice
------------

Default is ``qwen2.5:1.5b-instruct`` — small enough to fit on modest
hosts (~1.5 GB RAM) while still reliable for tool-calling. Override
via ``OLLAMA_MODEL`` for a larger / more capable model
(``llama3.2:3b`` is a good next rung up).

Structured output
-----------------

Ollama implements the OpenAI-style tools / tool_choice protocol for the
models that support it (Qwen2.5, Llama 3.2, Mistral Small). We send the
same ``emit_query_plan`` tool definition the Claude planner uses, minus
``cache_control`` (local provider doesn't bill tokens). If the model
returns prose instead of a tool call, ``LLMError`` surfaces so the caller
can fall back or fail loudly — never guess a plan.
"""

from __future__ import annotations

import json
from typing import Any, Protocol

from intel_copilot.llm import LLMError, PLAN_TOOL
from intel_copilot.log import get

log = get(__name__)


DEFAULT_URL = "http://ollama:11434"
DEFAULT_MODEL = "qwen2.5:1.5b-instruct"
# Keep sampling tight — tool-call adherence breaks down quickly as
# temperature rises on small models. 0.1 is "deterministic enough for
# structured output" without being 0.0 which some models dislike.
DEFAULT_TEMPERATURE = 0.1
DEFAULT_TIMEOUT_SECONDS = 60


# Appended to the system prompt when running against Ollama. The
# grammar-constrained decoder already guarantees JSON-shaped output,
# but giving the model one last nudge keeps the content of the JSON
# aligned with the plan contract (rather than a plausible-looking
# but wrong plan).
_JSON_MODE_SUFFIX = (
    "\n\nRespond with ONLY the JSON plan object — no <tool_call> tags, "
    "no prose, no markdown fences. The server parses your response as "
    "JSON directly."
)


class HttpClientLike(Protocol):
    """Minimum shape of an HTTP client. Tests stub this; production
    path uses ``urllib.request`` via :class:`_UrlLibHttpClient` so we
    don't add ``requests`` to the dependency set — Ollama is a single
    POST, ``urllib`` is enough."""

    def post_json(self, url: str, payload: dict[str, Any], *, timeout: float) -> dict[str, Any]:
        ...


class OllamaLLM:
    """Query an Ollama server and pull a tool-call-shaped plan out of the
    response. Interface identical to ``ClaudeLLM`` so the ``LLMPlanParser``
    wraps either provider without caring which."""

    def __init__(
        self,
        *,
        base_url: str = DEFAULT_URL,
        model: str = DEFAULT_MODEL,
        temperature: float = DEFAULT_TEMPERATURE,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        http: HttpClientLike | None = None,
        system_prompt: str | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._model = model
        self._temperature = temperature
        self._timeout = timeout_seconds
        self._http = http or _UrlLibHttpClient()
        # Import here to avoid a cycle: llm.py imports nothing from this
        # module, but the shared SYSTEM_PROMPT is useful to both.
        if system_prompt is None:
            from intel_copilot.llm import SYSTEM_PROMPT as _SYSTEM_PROMPT

            system_prompt = _SYSTEM_PROMPT
        self._system_prompt = system_prompt

    @classmethod
    def from_env(cls, **overrides: Any) -> "OllamaLLM":
        import os

        defaults: dict[str, Any] = {
            "base_url": os.environ.get("OLLAMA_URL", DEFAULT_URL),
            "model": os.environ.get("OLLAMA_MODEL", DEFAULT_MODEL),
        }
        defaults.update(overrides)
        return cls(**defaults)

    def plan(self, question: str) -> dict[str, Any]:
        # ``format: "json"`` tells Ollama to grammar-constrain the
        # output to any valid JSON object. The richer "format: {schema}"
        # variant is stricter but Ollama's validator only accepts a
        # subset of JSON Schema (no anyOf / $ref / description etc.),
        # which is more maintenance burden than reward on small models
        # — the system prompt + validator on this side already catch
        # shape errors. Using the simple mode keeps the planner portable
        # across any model that ships with Ollama.
        body = {
            "model": self._model,
            "stream": False,
            "format": "json",
            "options": {"temperature": self._temperature},
            "messages": [
                {"role": "system", "content": self._system_prompt + _JSON_MODE_SUFFIX},
                {"role": "user", "content": question},
            ],
        }

        resp = self._http.post_json(
            f"{self._base_url}/api/chat",
            body,
            timeout=self._timeout,
        )

        return _extract_json_content(resp)


# ---------------------------------------------------------------------- #
# Response parsing — Ollama's schema for tool calls on /api/chat:
#
#   {"message": {"role":"assistant",
#                "tool_calls":[{"function":{"name":"...","arguments":{...}}}]}}
#
# When the model refuses / returns prose, tool_calls is absent and
# ``message.content`` carries the text. Surface that as LLMError so the
# caller can fall back (heuristic / Claude) rather than executing a
# guessed plan.
# ---------------------------------------------------------------------- #

def _extract_json_content(resp: dict[str, Any]) -> dict[str, Any]:
    """Parse the plan JSON out of an Ollama grammar-constrained response.

    The server guarantees ``message.content`` is a JSON document matching
    the schema we passed as ``format``. We still check shape + strip any
    wrapper tokens a lenient model might emit despite the constraint
    (e.g. a single ``<tool_call>`` wrapper that some Qwen variants
    refuse to drop).
    """
    message = resp.get("message")
    if not isinstance(message, dict):
        raise LLMError(f"ollama response missing 'message': {resp!r}")

    content = (message.get("content") or "").strip()
    if not content:
        raise LLMError(f"ollama returned empty content: {resp!r}")

    # Strip stray <tool_call> wrapper that grammar-constrained mode
    # sometimes still emits on Qwen2.5.
    import re
    match = re.search(r"<tool_call>\s*(\{.*\})\s*</tool_call>", content, re.DOTALL)
    if match:
        content = match.group(1)

    # Some models insert markdown fences around JSON despite being told
    # not to. Strip ```json ... ``` conservatively.
    if content.startswith("```"):
        content = re.sub(r"^```(?:json)?\s*", "", content)
        content = re.sub(r"\s*```\s*$", "", content)

    try:
        parsed = json.loads(content)
    except json.JSONDecodeError as exc:
        raise LLMError(f"ollama returned non-JSON content: {content[:200]!r}") from exc
    if not isinstance(parsed, dict):
        raise LLMError(f"ollama plan JSON was not an object: {parsed!r}")
    return parsed


def _extract_tool_call(resp: dict[str, Any]) -> dict[str, Any]:
    """Pull the plan dict out of an Ollama chat response.

    Three shapes in the wild, tried in order:

    1. ``message.tool_calls`` — native OpenAI-style, returned when the
       model respects Ollama's tool protocol end-to-end.
    2. ``<tool_call>{...}</tool_call>`` XML tags embedded in
       ``message.content`` — Qwen2.5 (and some other models) are trained
       on this delimiter and Ollama doesn't always convert it back.
    3. A bare JSON object in ``message.content`` — last-ditch fallback
       for models that were nagged into "respond with JSON only".

    Anything else raises ``LLMError`` so the broker can fall back or
    fail loudly instead of executing a guessed plan.
    """
    message = resp.get("message")
    if not isinstance(message, dict):
        raise LLMError(f"ollama response missing 'message': {resp!r}")

    # (1) Native tool_calls
    tool_calls = message.get("tool_calls")
    if tool_calls:
        for call in tool_calls:
            fn = call.get("function") if isinstance(call, dict) else None
            if not isinstance(fn, dict):
                continue
            if fn.get("name") not in (PLAN_TOOL["name"], None):
                continue
            args = fn.get("arguments")
            if isinstance(args, str):
                try:
                    args = json.loads(args)
                except json.JSONDecodeError as exc:
                    raise LLMError(f"ollama emitted non-JSON arguments: {args!r}") from exc
            if isinstance(args, dict):
                return args

    content = (message.get("content") or "").strip()

    # (2) <tool_call> XML embedded in content
    import re
    match = re.search(r"<tool_call>\s*(\{.*?\})\s*</tool_call>", content, re.DOTALL)
    if match:
        try:
            payload = json.loads(match.group(1))
        except json.JSONDecodeError as exc:
            raise LLMError(
                f"ollama <tool_call> block had invalid JSON: {match.group(1)!r}"
            ) from exc
        if isinstance(payload, dict):
            # Some models nest the plan under {"name":"emit_query_plan",
            # "arguments":{...}}; unwrap when present.
            if payload.get("name") == PLAN_TOOL["name"] and isinstance(payload.get("arguments"), dict):
                return payload["arguments"]
            return payload

    # (3) Bare JSON object in content
    if content.startswith("{") and content.endswith("}"):
        try:
            bare = json.loads(content)
        except json.JSONDecodeError:
            bare = None
        if isinstance(bare, dict):
            return bare

    raise LLMError(
        "ollama model returned prose, not a tool call"
        + (f": {content[:200]!r}" if content else "")
    )


# ---------------------------------------------------------------------- #
# Small stdlib-only HTTP client. Keeps this module dependency-free so
# the broker image stays slim when the local-LLM path isn't used.
# ---------------------------------------------------------------------- #

class _UrlLibHttpClient:
    """POST JSON, receive JSON. Enough for a single chat-completion call."""

    def post_json(self, url: str, payload: dict[str, Any], *, timeout: float) -> dict[str, Any]:
        import urllib.error
        import urllib.request

        raw = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            url,
            data=raw,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                body = resp.read()
        except urllib.error.HTTPError as exc:  # pragma: no cover — IO path
            detail = exc.read().decode("utf-8", errors="replace")[:500]
            raise LLMError(f"ollama HTTP {exc.code}: {detail}") from exc
        except (urllib.error.URLError, TimeoutError) as exc:  # pragma: no cover
            raise LLMError(f"ollama unreachable at {url}: {exc}") from exc

        try:
            return json.loads(body)
        except json.JSONDecodeError as exc:  # pragma: no cover — server bug
            raise LLMError(f"ollama returned non-JSON body: {body[:200]!r}") from exc
