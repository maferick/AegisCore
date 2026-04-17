"""intel_copilot — natural-language query + reasoning layer over EVE data.

The package is organized around a small, explicit pipeline:

    natural-language question
        │
        ▼
    parser.py        — question → QueryPlan (LLM or rule-based)
        │
        ▼
    router.py        — plan → Backend selection (OpenSearch / SQL / Neo4j)
        │
        ▼
    executors/*.py   — backend-specific translators that return ResultSet
        │
        ▼
    synth.py         — ResultSet → human-readable answer

The plan (see plan.py) is the spine. It is a structured, typed IR that is
independent of both the LLM that emits it and the backend that executes it.
That separation is what prevents the LLM from improvising the truth.

Phase-1 scope: OpenSearch for top-N / count / trend over killmails.
SQL executor is a stub; Neo4j executor is not yet implemented.
"""
