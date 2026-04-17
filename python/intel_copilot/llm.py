"""Claude-backed natural-language → QueryPlan bridge.

The broker has always been able to execute plans emitted as JSON (see
``DictPlanParser``). This module is the one piece that turns a free-form
English question into that JSON, using Claude tool-use to force the model
output into the plan shape rather than asking for "respond with JSON"
and hoping. The tool's ``input_schema`` mirrors ``plan.QueryPlan`` and is
the single source of truth the model sees — update it here and Claude
cannot emit a field the broker does not understand.

Design notes:

* ``ClaudeLLM`` talks to the Anthropic Messages API with
  ``tool_choice={"type":"tool","name":"emit_query_plan"}``. No free-form
  prose is accepted — if the model refuses, ``LLMError`` surfaces so the
  caller can degrade to the heuristic parser.
* The system prompt carries the full plan-schema explanation plus a
  handful of worked examples. It never changes per question, so it is
  marked with ``cache_control={"type":"ephemeral"}`` — a warm cache
  saves ~90% of the prompt tokens per call, which matters when the page
  is called from a Livewire chat loop.
* ``LLMPlanParser`` wraps the client and reuses ``DictPlanParser`` for
  validation so every path through the broker enforces the same invariants.
"""

from __future__ import annotations

from typing import Any, Protocol

from intel_copilot.log import get
from intel_copilot.parser import DictPlanParser
from intel_copilot.plan import PLAN_VERSION, PlanError, QueryPlan

log = get(__name__)


# Default model — Sonnet 4.6 is the sweet spot for structured-output
# tool-use on a tight schema. Haiku 4.5 also works for the simpler plan
# shapes; callers can override via ``ClaudeLLM(model=...)`` when they
# want cost over fidelity.
DEFAULT_MODEL = "claude-sonnet-4-6"
DEFAULT_MAX_TOKENS = 1024


_ROLE_ENUM = ["attacker", "victim", "any"]
_ENTITY_TYPE_ENUM = [
    "ship_type", "ship_group", "ship_category",
    "character", "corporation", "alliance",
    "system", "region",
]

#: JSON Schema fragment for a single role-typed entity reference.
#: Shared by ``subject``, ``filters`` and ``group_by`` so the model only
#: has one shape to learn.
_ENTITY_REF_SCHEMA: dict[str, Any] = {
    "type": "object",
    "required": ["role", "entity_type"],
    "properties": {
        "role": {"type": "string", "enum": _ROLE_ENUM},
        "entity_type": {"type": "string", "enum": _ENTITY_TYPE_ENUM},
        "operator": {"type": "string", "enum": ["eq", "in", "ne"], "default": "eq"},
        "value": {
            "description": "Named handle — e.g. 'Catalyst', 'Horde', 'Delve'. Preferred when the user spoke a name.",
        },
        "value_id": {
            "description": "Numeric ID when the caller passed one explicitly.",
        },
    },
}

#: Anthropic tool schema — MUST stay in sync with ``plan.QueryPlan``.
#: Enum values are duplicated rather than derived at runtime because the
#: tool definition is what the model sees; deriving it means a silent
#: skew between "what the broker accepts" and "what the model was told
#: to emit". Keeping it as literal JSON makes every change reviewable.
PLAN_TOOL: dict[str, Any] = {
    "name": "emit_query_plan",
    "description": (
        "Emit a structured query plan that the broker will execute. "
        "Do not guess answers — every numeric or entity-level claim in "
        "the final response must be derived from this plan's result set."
    ),
    "input_schema": {
        "type": "object",
        "required": ["intent"],
        "properties": {
            "intent": {
                "type": "string",
                "enum": ["top_n", "count", "trend", "list", "lookup", "path", "neighbors", "compare"],
                "description": "Question shape. top_n=ranked list; count=single integer; trend=time series; list=raw documents; lookup=single fact; path=shortest jumps between two systems; neighbors=systems within N jumps.",
            },
            "metric": {
                "type": "string",
                "enum": ["count", "sum_isk", "avg_isk"],
                "default": "count",
            },
            "subject": {"anyOf": [{"type": "null"}, _ENTITY_REF_SCHEMA]},
            "filters": {
                "type": "array",
                "items": _ENTITY_REF_SCHEMA,
                "default": [],
            },
            "time_window": {
                "type": "object",
                "properties": {
                    "from": {
                        "type": ["string", "null"],
                        "description": "ISO-8601 or OpenSearch-style relative ('now-30d'). Null = unbounded.",
                    },
                    "to": {
                        "type": ["string", "null"],
                        "default": "now",
                    },
                },
            },
            "group_by": {
                "anyOf": [{"type": "null"}, {
                    "type": "object",
                    "required": ["role", "entity_type"],
                    "properties": {
                        "role": {"type": "string", "enum": _ROLE_ENUM},
                        "entity_type": {"type": "string", "enum": _ENTITY_TYPE_ENUM},
                        "time_interval": {
                            "type": ["string", "null"],
                            "description": "Required for trend: '1h', '1d', '1w', '1M'.",
                        },
                    },
                }],
            },
            "limit": {"type": "integer", "minimum": 1, "maximum": 1000, "default": 10},
        },
    },
}


SYSTEM_PROMPT = f"""\
You translate natural-language questions about EVE Online combat data into
structured query plans. You never answer the question yourself — instead
you call the `emit_query_plan` tool exactly once with a plan the broker
will execute.

# Plan shape (v{PLAN_VERSION})

A plan has:

* `intent` — one of:
  - `top_n`     : ranked buckets ("most used ship", "biggest losers")
  - `count`     : single integer ("how many kills")
  - `trend`     : time-bucketed series ("kills per day")
  - `list`      : raw documents ("which kills last hour")
  - `lookup`    : single fact about one canonical entity ("who is 2113167159")
  - `path`      : shortest jump path between two systems
                  (subject=source system, filter[0]=destination system)
  - `neighbors` : systems within N jumps of one system
                  (subject=source system, limit=N jumps, max 10)
  - `compare`   : reserved, not yet supported
* `metric` — what to sum inside buckets: `count` (default), `sum_isk`, `avg_isk`.
* `subject` — what the *answer is about*. For `top_n` this is the dimension
  you are ranking. Has a `role` (attacker / victim / any) + `entity_type`
  (ship_type / ship_group / ship_category / character / corporation /
  alliance / system / region).
* `filters` — constraints that narrow the dataset. Same shape as `subject`.
  Example: victim=freighter, region=Delve.
* `time_window` — `from` / `to`, ISO-8601 or relative ("now-30d").
* `group_by` — what to bucket on. Only needed for `trend` (where
  `time_interval` is required: '1h', '1d', '1w', '1M') or to override the
  implicit subject grouping on `top_n`.
* `limit` — bucket count for `top_n`, row count for `list`. 1–1000.

# Rules

* Always prefer `value` (the name the user spoke) over `value_id`.
* Use `role=victim` for "kills of X" / "X losses"; `role=attacker` for
  "by X" / "who killed with X". Use `role=any` for location (system, region).
* If the user says "last 30 days", emit `time_window={{"from":"now-30d","to":"now"}}`.
* Never invent an entity the user did not mention. If you cannot tell what
  role a filter belongs to, pick the most common interpretation, not both.
* If the question is nonsense or outside the combat-data domain, still call
  the tool with an empty `count` plan (`intent=count`, no subject,
  `time_window={{"from":"now-7d","to":"now"}}`) — the broker will return a
  bare total and the synthesis layer will tell the user it could not parse.

# Examples

Q: "what is the most used ship to kill freighters in the last 30 days?"
→ intent=top_n, subject={{role:attacker, entity_type:ship_type}},
  filters=[{{role:victim, entity_type:ship_group, value:"Freighter"}}],
  time_window={{from:"now-30d", to:"now"}}, limit=10.

Q: "how many kills in Delve yesterday?"
→ intent=count, filters=[{{role:any, entity_type:region, value:"Delve"}}],
  time_window={{from:"now-1d/d", to:"now/d"}}.

Q: "which alliances lost the most isk last week?"
→ intent=top_n, metric=sum_isk,
  subject={{role:victim, entity_type:alliance}},
  time_window={{from:"now-7d", to:"now"}}, limit=10.

Q: "kills per day in Fountain last month"
→ intent=trend, metric=count,
  filters=[{{role:any, entity_type:region, value:"Fountain"}}],
  group_by={{role:any, entity_type:region, time_interval:"1d"}},
  time_window={{from:"now-30d", to:"now"}}.

Q: "who is character id 2113167159"
→ intent=lookup, subject={{role:any, entity_type:character, value_id:2113167159}}.

Q: "shortest path from Jita to Amarr"
→ intent=path,
  subject={{role:any, entity_type:system, value:"Jita"}},
  filters=[{{role:any, entity_type:system, value:"Amarr"}}],
  limit=30.

Q: "systems within 3 jumps of Jita"
→ intent=neighbors,
  subject={{role:any, entity_type:system, value:"Jita"}},
  limit=3.
"""


class LLMError(RuntimeError):
    """Raised when the model refuses to emit a plan or the SDK fails."""


class AnthropicClient(Protocol):
    """Protocol matching the slice of ``anthropic.Anthropic`` we actually use.

    Kept explicit so tests can pass a stub without importing the SDK, and
    so a future vendor swap only has to implement this one method.
    """

    def messages_create(
        self,
        *,
        model: str,
        max_tokens: int,
        system: list[dict[str, Any]] | str,
        tools: list[dict[str, Any]],
        tool_choice: dict[str, Any],
        messages: list[dict[str, Any]],
    ) -> Any: ...


class ClaudeLLM:
    """Wraps the Anthropic SDK into a plain ``plan(question) -> dict`` call.

    The SDK client is injected so tests can pass a stub and so real callers
    can reuse an already-configured client (shared retry/backoff, custom
    base_url, etc.). ``from_env`` is the production-path constructor.
    """

    def __init__(
        self,
        client: Any,
        *,
        model: str = DEFAULT_MODEL,
        max_tokens: int = DEFAULT_MAX_TOKENS,
        system_prompt: str = SYSTEM_PROMPT,
    ) -> None:
        self._client = client
        self._model = model
        self._max_tokens = max_tokens
        self._system_prompt = system_prompt

    @classmethod
    def from_env(cls, **overrides: Any) -> "ClaudeLLM":
        """Build a client from ``ANTHROPIC_API_KEY``.

        The SDK import is local so that every non-LLM code path — tests,
        CLI ``--dry-run``, heuristic-only usage — stays dependency-free.

        ``INTEL_COPILOT_MODEL`` overrides the default model name when the
        caller didn't pass one explicitly — handy for staging Sonnet /
        Haiku against each other without code changes.
        """
        import os

        try:
            import anthropic  # type: ignore[import-not-found]
        except ImportError as exc:  # pragma: no cover — exercised at runtime
            raise LLMError(
                "anthropic SDK not installed; pip install anthropic or "
                "add it to requirements-intel-copilot.txt"
            ) from exc

        api_key = os.environ.get("ANTHROPIC_API_KEY")
        if not api_key:
            raise LLMError("ANTHROPIC_API_KEY not set")

        overrides.setdefault("model", os.environ.get("INTEL_COPILOT_MODEL") or DEFAULT_MODEL)
        client = anthropic.Anthropic(api_key=api_key)
        return cls(client, **overrides)

    def plan(self, question: str) -> dict[str, Any]:
        """Ask Claude to emit a plan for this question.

        Returns the raw plan dict (not a ``QueryPlan`` — pair with
        ``DictPlanParser`` for validation). Raises ``LLMError`` if the
        model output is not a tool_use block or the tool block is empty.
        """
        system_blocks = [
            {
                "type": "text",
                "text": self._system_prompt,
                # Ephemeral cache: system prompt is ~1.2 kB of schema +
                # examples that never changes per question. Cache hits
                # drop the per-call input cost by ~10×.
                "cache_control": {"type": "ephemeral"},
            }
        ]

        response = self._create_message(
            model=self._model,
            max_tokens=self._max_tokens,
            system=system_blocks,
            tools=[PLAN_TOOL],
            tool_choice={"type": "tool", "name": PLAN_TOOL["name"]},
            messages=[{"role": "user", "content": question}],
        )

        return _extract_tool_input(response)

    # ------------------------------------------------------------------ #
    # Thin SDK-shaped adapter — split out so tests can monkeypatch one
    # entry point without reimplementing the whole Anthropic client.
    # ------------------------------------------------------------------ #

    def _create_message(self, **kwargs: Any) -> Any:
        messages = getattr(self._client, "messages", None)
        if messages is not None and hasattr(messages, "create"):
            return messages.create(**kwargs)
        # Fallback for the flat Protocol shape used in tests.
        return self._client.messages_create(**kwargs)


class _PlanEmittingLLM(Protocol):
    """Minimum shape ``LLMPlanParser`` needs from any provider. Both
    ``ClaudeLLM`` and ``OllamaLLM`` satisfy it; any future provider
    (vLLM, mlc, etc.) only has to implement ``plan``."""

    def plan(self, question: str) -> dict[str, Any]: ...


class LLMPlanParser:
    """Same surface as ``DictPlanParser``, backed by an LLM.

    Callers get a validated ``QueryPlan`` — everything past this point
    in the broker sees the same type whether the plan came from an LLM,
    from a handwritten JSON payload, or from the heuristic parser.
    """

    def __init__(self, llm: _PlanEmittingLLM, validator: DictPlanParser | None = None) -> None:
        self._llm = llm
        self._validator = validator or DictPlanParser()

    def parse(self, question: str) -> QueryPlan:
        raw = self._llm.plan(question)
        try:
            return self._validator.parse(raw)
        except PlanError:
            log.warning("llm emitted invalid plan", raw=raw)
            raise


# ---------------------------------------------------------------------- #
# Response parsing — the Messages API returns a list of content blocks;
# tool_use comes back as {"type":"tool_use","name":...,"input":{...}}.
# ---------------------------------------------------------------------- #

def _extract_tool_input(response: Any) -> dict[str, Any]:
    """Pull the tool_use.input off a Messages response.

    The SDK returns objects (not dicts) in production, but tests commonly
    pass dicts through. Handle both by duck-typing on ``.content`` /
    ``["content"]`` and on ``.type`` / ``["type"]``.
    """
    content = getattr(response, "content", None)
    if content is None and isinstance(response, dict):
        content = response.get("content")
    if not content:
        raise LLMError("empty response from model")

    for block in content:
        block_type = getattr(block, "type", None) or (block.get("type") if isinstance(block, dict) else None)
        if block_type != "tool_use":
            continue
        input_ = getattr(block, "input", None)
        if input_ is None and isinstance(block, dict):
            input_ = block.get("input")
        if not isinstance(input_, dict):
            raise LLMError(f"tool_use block has non-dict input: {input_!r}")
        return input_

    raise LLMError(
        "model did not emit a tool_use block — tool_choice forcing may be "
        "broken, or the model refused the task"
    )
