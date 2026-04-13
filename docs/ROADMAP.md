# Roadmap

## Phase 1 — Infra bootstrap
- Repo skeleton + contracts
- Compose stack up (MariaDB, OpenSearch, Dashboards, InfluxDB, Neo4j, Nginx)
- Health endpoints
- One E2E reference pipeline (killmails)
- Other 3 pillars: stubs only

## Phase 2 — Data plane
- EVE universe import + categorization
- Killmail ingest → MariaDB + OpenSearch + Neo4j + InfluxDB
- Basic theater intelligence

## Phase 3 — Scale + UX
- SLA queue separation
- Incremental graph updates
- Persona-specific UX
