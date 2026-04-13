# AGENTS.md — AegisCore

Entry point for humans and agents working on AegisCore. Start here, then follow
the links at the bottom.

## Mission
Build a modular alliance intelligence platform focused on:
1. Spy Detection
2. Buyall & Doctrines
3. Killmails & Battle Theaters
4. Unified Users/Characters

## Product philosophy (non-negotiable)
Human-first over engineer-first.

Priority:
1. Human decision quality
2. Operational clarity
3. Reliability
4. Performance
5. Engineering elegance

## Runtime model
- **Control Plane (Laravel 12 / PHP 8.4):** UI, API, settings, auth, RBAC,
  light orchestration.
- **Execution Plane (Python):** ingestion, compute, graph pipelines, indexing,
  any job that reads/writes primary domain data.
- No heavy compute in PHP.

## Plane boundary (policy, not best-effort)

This rule is enforced at code review. Violations block merge.

- Laravel queues and Horizon jobs handle **control-plane work only**:
  notifications, audit, webhook fan-out, auth events, UI glue.
- A Laravel queue job must complete in **< 2s** and touch **< 100 rows**. If
  it wouldn't, it belongs in Python.
- **Laravel does not call Python workers directly** and does not push to
  Python's queues. Cross-plane triggers use the **outbox pattern** — Laravel
  writes an intent row to MariaDB's `outbox` table inside the same transaction
  as the business change; Python's relay consumes it.
- **Laravel does not write to Neo4j / OpenSearch / InfluxDB.** Those are
  derived stores owned by Python.
- See [`docs/CONTRACTS.md`](docs/CONTRACTS.md) for the outbox schema + semantics.

## Data ownership (hard rule)
- **MariaDB**: canonical source of truth + `outbox` table.
- **Neo4j**: graph projection only (Python writes).
- **OpenSearch**: search/aggregation projection only (Python writes).
- **InfluxDB**: metrics/time-series only (Python writes).
- **Redis**: ephemeral — cache, sessions, Laravel queues, Horizon state.
  Never the system of record for anything.

Derived stores must be rebuildable from MariaDB + external sources.

## UX principles
- Intent first, details second
- "What changed / why it matters / what to do next"
- Progressive disclosure
- Clear loading/progress/error states

## Phase 1 scope
- Repo skeleton
- Compose stack (MariaDB, Redis, OpenSearch, Dashboards, InfluxDB, Neo4j,
  nginx, php-fpm)
- Health endpoints
- Laravel skeleton + 4-pillar module layout (separate PR)
- One E2E reference pipeline (killmails)
- Other 3 pillars are stubs only

## Where to go next
- Running the stack: [`README.md`](README.md)
- Architecture overview: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Roadmap: [`docs/ROADMAP.md`](docs/ROADMAP.md)
- API + Job + Outbox contracts: [`docs/CONTRACTS.md`](docs/CONTRACTS.md)
- Operational notes: [`infra/notes.md`](infra/notes.md)
- Change history: [`CHANGELOG.md`](CHANGELOG.md)
