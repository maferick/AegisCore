"""Executor protocol and shared result shape."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Protocol

from intel_copilot.contracts import Backend
from intel_copilot.plan import QueryPlan


@dataclass(frozen=True)
class ResultRow:
    """One row of a plan result — a labelled bucket with a metric value.

    Subjects that the executor could not resolve to a name (e.g. OpenSearch
    returned an ID but the index does not denormalize its label) fall back
    to the stringified ID in ``label`` so the synthesis layer always has
    something to render.
    """
    label: str
    value: float | int
    meta: dict[str, Any] = field(default_factory=dict)


@dataclass(frozen=True)
class ResultSet:
    """Everything the synthesis layer needs to produce an answer."""
    backend: Backend
    plan: QueryPlan
    rows: tuple[ResultRow, ...]
    total: int | float | None = None  # scalar total, when applicable
    took_ms: int | None = None
    # ``query`` is the actual backend query that ran — handy for debugging
    # and for the synthesis layer to cite "how we got this answer".
    query: Any = None


class Executor(Protocol):
    """What every backend must implement.

    Implementations are expected to be cheap to construct; connection state
    lives inside the executor so the router can keep a registry of ready-to-
    run instances without re-dialling on every plan.
    """

    backend: Backend

    def execute(self, plan: QueryPlan) -> ResultSet:
        """Translate and run the plan. Must not mutate ``plan``."""
        ...
