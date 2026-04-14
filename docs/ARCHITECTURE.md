# Architecture (Phase 1)

## Planes
- **Control Plane (Laravel 12 / PHP 8.4):** UI (Livewire 3 + Filament admin),
  API (`/api/v1/...`), auth (Sanctum), RBAC (`spatie/laravel-permission`),
  queues (Horizon on Redis) for control-plane work only. Runs as `php-fpm`
  behind the `nginx` front door. Source lives in `app/`.
- **Execution Plane (Python):** ingestion / compute / jobs / graph pipelines /
  search indexing. Not in Phase 1; containers will be added under the same
  compose stack.

PHP never runs heavy compute. Python never owns user-facing UI.

## Plane boundary

Laravel and Python communicate via the **outbox pattern** only — see
[`CONTRACTS.md`](CONTRACTS.md). Deciding which plane a new job belongs on
is a review-time rule, documented in
[`AGENTS.md` § Job placement rule](../AGENTS.md#job-placement-rule). The
short version:

```
┌─ Laravel (Control Plane) ──────┐       ┌─ Python (Execution Plane) ─────┐
│ UI / API / auth / settings     │       │ Killmail ingest + enrichment   │
│ Horizon: notifications, audit, │       │ Graph building (Neo4j writes)  │
│   webhooks, UI glue (p95 <2s)  │       │ OpenSearch indexing            │
│ Writes: MariaDB only           │       │ InfluxDB metrics writes        │
│        + `outbox` table        │       │ Reads: outbox (relay) + ext.   │
└────────────────┬───────────────┘       └───────────────▲────────────────┘
                 │                                       │
                 └──────── MariaDB `outbox` table ───────┘
                          (atomic with business tx)
```

## Data ownership

Frozen in [ADR-0003](adr/0003-data-placement-freeze.md); `AGENTS.md`
carries the full per-store role list. Short form:

- **MariaDB** — canonical. Killmails (killmail / victim / attackers /
  items), character / corp / alliance identity + temporal history,
  valuation records with provenance, raw market observations used as
  valuation inputs, `outbox`, and `ref_*` SDE reference tables
  ([ADR-0001](adr/0001-static-reference-data.md)).
- **Redis** — acceleration only, never a system of record. Cache,
  sessions, Laravel/Horizon queue state, hot ESI cache, negative cache,
  single-flight locks, rate-limit helpers. Outage impairs latency, not
  correctness.
- **Neo4j / OpenSearch / InfluxDB** — derived stores, Python writes, no
  canonical ownership. Rebuildable from MariaDB + external sources.
  Neo4j holds the relationship graph for spy investigation; OpenSearch
  holds denormalized killmail search docs + analyst facets; InfluxDB
  holds market series / rollups aggregated from MariaDB raw observations.
  Universe topology is projected from `ref_*` into Neo4j by the
  `graph_universe_sync` consumer; search projections onto OpenSearch are
  deferred to phase 2.

## Network topology (Phase 1)
All services share the `aegiscore` docker network. Nginx is the only service
that exposes public ports (`80`, `443`) in prod. DB ports (`3306`, `7687`) are
bound to `127.0.0.1` only. Other service ports are exposed to the host for dev
convenience and should be removed or firewalled off in prod.

## Human-first standard
Every page should answer:
1. What changed?
2. Why does it matter?
3. What should I do next?

## Pillars
1. `spy-detection`
2. `buyall-doctrines`
3. `killmails-battle-theaters`
4. `users-characters`
