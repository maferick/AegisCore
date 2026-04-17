"""Broker / router — plan validation + backend dispatch.

The router is deliberately thin. It (a) validates the plan, (b) looks up the
contract from ``contracts.py``, (c) dispatches to the first registered
executor that can serve the plan's intent. Anything more clever — cost
estimation, cross-backend joins, caching — belongs one layer up.

If no registered executor is available, ``RoutingError`` surfaces with the
list of intents the router currently speaks. That is meant to be a useful
diagnostic, not a silent fallback to "the LLM improvises".
"""

from __future__ import annotations

from intel_copilot.contracts import Backend, RoutingError, supported_backends
from intel_copilot.executors.base import Executor, ResultSet
from intel_copilot.log import get
from intel_copilot.plan import QueryPlan

log = get(__name__)


class Router:
    def __init__(self, executors: dict[Backend, Executor] | None = None) -> None:
        self._executors: dict[Backend, Executor] = dict(executors or {})

    def register(self, executor: Executor) -> None:
        self._executors[executor.backend] = executor

    def backends(self) -> tuple[Backend, ...]:
        return tuple(self._executors.keys())

    def execute(self, plan: QueryPlan) -> ResultSet:
        plan.validate()
        choices = supported_backends(plan)
        if not choices:
            raise RoutingError(
                f"no backend mapping for intent={plan.intent.value}"
            )
        for backend in choices:
            executor = self._executors.get(backend)
            if executor is None:
                continue
            log.info(
                "dispatching plan",
                intent=plan.intent.value,
                backend=backend.value,
            )
            return executor.execute(plan)
        raise RoutingError(
            f"intent={plan.intent.value} needs one of "
            f"{[b.value for b in choices]} but only "
            f"{[b.value for b in self._executors]} are registered"
        )
