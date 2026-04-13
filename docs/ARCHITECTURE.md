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
[`CONTRACTS.md`](CONTRACTS.md). The short version:

```
┌─ Laravel (Control Plane) ──────┐       ┌─ Python (Execution Plane) ─────┐
│ UI / API / auth / settings     │       │ Killmail ingest + enrichment   │
│ Horizon: notifications, audit, │       │ Graph building (Neo4j writes)  │
│   webhooks, UI glue (< 2s)     │       │ OpenSearch indexing            │
│ Writes: MariaDB only           │       │ InfluxDB metrics writes        │
│        + `outbox` table        │       │ Reads: outbox (relay) + ext.   │
└────────────────┬───────────────┘       └───────────────▲────────────────┘
                 │                                       │
                 └──────── MariaDB `outbox` table ───────┘
                          (atomic with business tx)
```

## Data ownership
- **MariaDB** — canonical source of truth + `outbox` table.
- **Redis** — ephemeral: cache, sessions, Laravel queues, Horizon state.
  Never a system of record.
- **Neo4j / OpenSearch / InfluxDB** — derived stores. Rebuildable from MariaDB
  + external sources. Python owns writes.

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
