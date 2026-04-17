# ADR-0007 — Intel Copilot: tool-augmented RAG broker over heterogeneous stores

**Status:** Accepted
**Date:** 2026-04-17
**Related:** [ADR-0003](0003-data-placement-freeze.md) (data placement),
[ADR-0004](0004-market-data-ingest.md) (killmail ingest),
[ADR-0006](0006-battle-theater-reports.md) (theater reports)

## Context

One of AegisCore's surfaces will be a natural-language copilot: analysts
ask questions in English ("most used ship to kill freighters in the last
30 days", "which alliances third-party Horde vs Goons fights") and get
answers grounded in real data.

The naive way to do this is "classic RAG": chunk every killmail / battle
report / SQL export into embeddings and let the LLM fish the answer out of
retrieved text. That model works well for prose (manuals, wikis) but
breaks for our domain:

- the truth lives in **structured** stores (MariaDB canonical, OpenSearch
  denormalized events, Neo4j relationships, InfluxDB series — see
  ADR-0003);
- answers require **temporal correctness** (who was in alliance X on
  date D);
- answers require **aggregation math** (top-N, sum ISK, date histograms)
  the LLM will improvise incorrectly if asked to "figure it out from
  text";
- answers must be **reproducible** — two users asking the same question
  must get the same number.

Vector-only retrieval delivers none of those guarantees.

## Decision

Adopt a **tool-augmented RAG** architecture for the copilot, not classic
document RAG. The spine is a small, typed **query plan IR** that separates
interpretation from execution:

```
  user question
      │
      ▼
  parser    ── natural-language → QueryPlan (LLM or heuristic)
      │
      ▼
  router    ── QueryPlan → Backend (per routing contract)
      │
      ▼
  executor  ── translates plan → backend-native query (OpenSearch / SQL / Neo4j)
      │
      ▼
  synth     ── ResultSet → human-readable answer (template + optional LLM rewrite)
```

The plan is the only thing that crosses the boundary between "LLM-emitted
intent" and "deterministic execution". The LLM never touches a database
directly, never writes SQL, never guesses field names. Executors never
see free-form text.

Package lives at `python/intel_copilot/`. It is a new Python-plane service
under the existing execution-plane umbrella (AGENTS.md § plane boundary);
Laravel will talk to it later via the same outbox + HTTP pattern the other
Python services use — Laravel is not in this ADR's scope.

### Routing contract (locked surface for phase 1)

Codified in `python/intel_copilot/contracts.py`. One row per intent; adding
an intent means updating this table and registering an executor.

| Intent   | Backend    | Example question |
|----------|------------|-------------------|
| top_n    | OpenSearch | "most used ship to kill freighters" |
| count    | OpenSearch | "how many kills in the last 24 h" |
| trend    | OpenSearch | "kills per day in Delve" |
| list     | OpenSearch | "show me the latest 20 capital kills" |
| lookup   | SQL        | "who is alliance Horde" |
| compare  | OpenSearch | *(reserved for phase 2)* |

Neo4j intents (shortest-path, recurrent-third-party, coalition overlap)
are **declared but not routed** in phase 1 — `Backend.NEO4J` exists in the
enum so the router surfaces a clean `RoutingError` rather than a silent
OpenSearch fallback. Wiring comes in a later ADR once we know the
specific Cypher shapes.

### Plan schema invariants (also locked)

- `plan_version` is mandatory; brokers refuse unknown versions.
- `subject` is required for `top_n` / `lookup`.
- `group_by.time_interval` is required for `trend`.
- `metric=count` is the only metric legal for intent `count`; other
  metrics demand an aggregating intent (`top_n` / `trend`).
- `limit ∈ (0, 1000]`.
- Every `filter` must carry a value or value_id.

Validation runs in the router *before* dispatch. Executors may reject on
top of that, but they never have to re-check plan shape.

## Alternatives considered

1. **Classic document RAG** — embed battle reports + SQL snapshots, let
   the LLM answer from retrieved text. Rejected: stale math, no temporal
   correctness, non-reproducible.
2. **LLM-writes-SQL ("text-to-SQL")** — let the LLM emit SQL / OpenSearch
   DSL directly. Rejected: query injection risk, inconsistent field names,
   reviewers can't audit surface, no cross-backend routing.
3. **Single backend (OpenSearch only)** — put everything in the
   denormalized index. Rejected: temporal correctness on
   character/corp/alliance history already demands SQL (ADR-0003), and
   relationship questions will demand Neo4j.
4. **Pydantic / JSON-Schema plans** — nicer ergonomics, but the
   dependency footprint is not worth it for six dataclasses; we stay on
   stdlib (matches the rest of `python/`).

## Consequences

**Positive**

- LLM is explicitly *not* authoritative on facts. It interprets and
  phrases; the backend calculates.
- Every answer is reproducible: same plan + same data = same result.
- Routing surface is small and reviewable (one table in `contracts.py`).
- Unit tests exercise the full pipeline (parser → router → executor)
  without an LLM, without an OpenSearch cluster, without a MariaDB.

**Negative**

- The plan IR is narrower than natural language. Questions that do not
  fit a template (yet) surface as `RoutingError` instead of a best-effort
  answer. That is intentional — a wrong answer is worse than "I don't
  know" — but it means the copilot will say "no" more often than a pure-
  LLM system early on.
- Adding a new question shape is a three-file change: plan IR
  (`Intent` / `EntityType`), routing contract, executor. No auto-growth.

**Neutral**

- Neo4j executor deferred; the routing enum already carries it so the
  later wiring is additive.
- LLM integration is deferred; phase 1 ships a heuristic parser + a
  `DictPlanParser` that accepts whatever a future LLM call returns as
  JSON. That lets Laravel integrate against the broker's `plan --json`
  entrypoint before we commit to an LLM provider.

## Non-goals for phase 1

- No training / fine-tuning.
- No vector index — classic RAG is out.
- No cross-plane HTTP contract with Laravel yet — the outbox pattern
  (ADR-0003) is the long-term story; phase 1 is CLI-only.
- No answer caching; re-running a question re-executes the plan.
