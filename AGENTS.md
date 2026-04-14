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
- **Control Plane (Laravel 12 / PHP 8.4) owns:** settings / UI / API / auth /
  RBAC; donor hub preferences + admin workflows; light synchronous ESI paths
  (SSO token exchange, donations wallet poll) per
  [ADR-0002](docs/adr/0002-eve-sso-and-esi-client.md). No heavy ingest or
  compute.
- **Execution Plane (Python) owns:** ingestion; ESI enrichment + cache client
  behaviour for heavy polling (killmails, rosters, markets at scale) per
  [ADR-0002](docs/adr/0002-eve-sso-and-esi-client.md); projection workers
  (Neo4j, OpenSearch, InfluxDB); recompute and backfill jobs.
- No heavy compute in PHP.

## Plane boundary (policy, not best-effort)

This rule is enforced at code review. Violations block merge.

- Laravel queues and Horizon jobs handle **control-plane work only**:
  notifications, audit, webhook fan-out, auth events, UI glue.
- A Laravel queue job must target **p95 < 2s**. Row-touch budget is
  **<= 100 rows by default**; jobs may run up to **<= 500 rows** only with
  explicit chunking, idempotency, and monitoring. Beyond that, move to Python.
- **Laravel does not call Python workers directly** and does not push to
  Python's queues. Cross-plane triggers use the **outbox pattern** — Laravel
  writes an intent row to MariaDB's `outbox` table inside the same transaction
  as the business change; Python's relay consumes it.
- **Laravel does not write to Neo4j / OpenSearch / InfluxDB.** Those are
  derived stores owned by Python.
- See [`docs/CONTRACTS.md`](docs/CONTRACTS.md) for the outbox schema + semantics.

### Job placement rule

When you write a new job, pick the plane before you pick the class.

**Keep in PHP (Laravel queue) when all of these hold:**
- **Fast:** p95 completes in ~< 2 seconds.
- **Small by default:** touches ~<= 100 DB rows.
- **If 101–500 rows:** use explicit chunking + idempotency + metrics, and keep
  p95 under 2 seconds.
- **UI / control-plane shaped:** emails, notifications, audit logs, webhooks,
  export kickoff, cache invalidation.
- **Not compute-heavy and not long-running.**
- **Does not write derived stores** (Neo4j / OpenSearch / InfluxDB).

**Move to Python when any of these hold:**
- **Slow or variable runtime** (> 2s, can spike).
- **Large batch / data scan** (> 500 rows, backfills, replays).
- **Compute-heavy** (matching, scoring, graph traversal, aggregation).
- **Pipeline / projection work** (outbox consume, indexing, graph/search writes).
- **Needs worker concurrency control or idempotent bulk processing.**
- **Crosses service boundaries heavily** (external APIs with retries,
  rate-limit orchestration).

**PR review heuristic (three questions):**
1. Could this block user-facing responsiveness if delayed or retried?
2. Could data size grow 10× and make this unsafe in a Laravel queue?
3. Is this part of domain-data ingestion or projection?

**Yes to any → prefer Python.** The trigger still originates in Laravel as an
outbox row; the work itself runs in the execution plane.

## Data ownership (hard rule)

Codified in [ADR-0003](docs/adr/0003-data-placement-freeze.md).

- **MariaDB** — canonical source of truth.
  - Killmails: killmail, victim, attackers, items.
  - Character / corp / alliance identity + temporal history.
  - Valuation records with provenance (source, fallback flag, version,
    `time_used_at`).
  - Raw market observations used as valuation inputs.
  - `outbox` table.
  - `ref_*` SDE reference tables — see
    [ADR-0001](docs/adr/0001-static-reference-data.md).
- **Redis** — acceleration only, never a system of record. Roles include
  cache, sessions, Laravel/Horizon queue state, hot ESI cache, negative
  cache, single-flight / request-coalescing locks, short-lived rate-limit
  helpers. A Redis outage impairs latency, never correctness.
- **Neo4j** — derived. Relationship graph for spy investigation;
  co-participation and affiliation edges. Python writes. No canonical
  ownership.
- **OpenSearch** — derived. Denormalized killmail search docs; analyst
  filters / facets / timelines. Python writes. No canonical ownership.
- **InfluxDB** — derived. Market series and rollups aggregated from
  MariaDB raw market observations; optional valuation-trend analytics.
  Python writes. No canonical ownership.

Derived stores must be rebuildable from MariaDB + external sources. This
applies to static reference data (EVE SDE) too — see
[ADR-0001](docs/adr/0001-static-reference-data.md) for the SDE load path
and `ref_*` table convention.

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
- Filament admin panel at `/admin` — shell only; Resources land as pillars
  mature. Access: `make filament-user` seeds an operator account; every
  authenticated user is admin in phase 1 (tightens to a role check on the
  `users` table when spatie/laravel-permission gets wired).
- One E2E reference pipeline (killmails)
- Other 3 pillars are stubs only

## Where to go next
- Running the stack: [`README.md`](README.md)
- Architecture overview: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Roadmap: [`docs/ROADMAP.md`](docs/ROADMAP.md)
- API + Job + Outbox contracts: [`docs/CONTRACTS.md`](docs/CONTRACTS.md)
- Architecture decisions: [`docs/adr/`](docs/adr/)
- Operational notes: [`infra/notes.md`](infra/notes.md)
- Change history: [`CHANGELOG.md`](CHANGELOG.md)
