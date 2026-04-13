# Architecture (Phase 1)

## Planes
- **Control Plane (PHP):** UI / API / settings / auth / light orchestration.
- **Execution Plane (Python):** ingestion / compute / jobs / graph pipelines.

PHP never runs heavy compute. Python never owns user-facing UI.

## Data ownership
- **MariaDB** — canonical source of truth.
- **Neo4j / OpenSearch / InfluxDB** — derived stores. Rebuildable from MariaDB
  + external sources.

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
