"""Routing contract — which backend owns which plan shape.

This file is intentionally small. It is the single source of truth for the
question-to-backend mapping that AGENTS.md § plane boundary asks for, so
reviewers have one table to check against.

The rule of thumb, in one sentence: if the answer is an aggregation over
denormalized killmail events, route to OpenSearch; if the answer requires a
temporally-correct join against canonical tables, route to SQL; if the
answer requires multi-hop relationship traversal, route to Neo4j.
"""

from __future__ import annotations

from enum import Enum

from intel_copilot.plan import Intent, QueryPlan


class Backend(str, Enum):
    OPENSEARCH = "opensearch"
    SQL = "sql"
    NEO4J = "neo4j"  # Deferred — declared so the router can refuse it cleanly.


#: Intent → preferred backend. Order matters only when multiple backends
#: could serve the plan; the first supported one wins. For the MVP every
#: intent has exactly one supported backend.
INTENT_ROUTING: dict[Intent, tuple[Backend, ...]] = {
    Intent.TOP_N: (Backend.OPENSEARCH,),
    Intent.COUNT: (Backend.OPENSEARCH,),
    Intent.TREND: (Backend.OPENSEARCH,),
    Intent.LIST:  (Backend.OPENSEARCH,),
    # Reserved for follow-up phases:
    Intent.COMPARE: (Backend.OPENSEARCH,),
    Intent.LOOKUP:  (Backend.SQL,),
}


def supported_backends(plan: QueryPlan) -> tuple[Backend, ...]:
    """Return the backends that *could* answer this plan, best-first.

    The router takes the first one whose executor is registered. Plans with
    no supported backend surface as ``RoutingError``.
    """
    return INTENT_ROUTING.get(plan.intent, ())


class RoutingError(RuntimeError):
    """Raised when no registered executor can serve a valid plan."""
