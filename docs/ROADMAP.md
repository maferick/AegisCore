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

## Phase 4 — Operational + counter-intel intelligence (shipped, v1)
Burn-down tracked in [`V1_COMPLETION_CHECKLIST.md`](V1_COMPLETION_CHECKLIST.md).

- 4.1–4.4 operational intelligence — timelines, hostile clusters,
  incidents, dscan integration, threat surface, corridors,
  response-time tempo
- 4.5 doctrine + force-composition intelligence
- 4.6 coalition + doctrine behavior intelligence
- 4.7 analyst workflow + intelligence production (digest / alerts /
  narratives / FC / director / search / exports)
- 4.8 intel governance + trust + analyst controls
- 4.9 freshness across all surfaces
- 4.9A compute orchestration (lanes + run log)
- 4.9C retention policy
- 4.9D retry / circuit breaker
- 4.9E platform observability + quality guards
- §11 audit logging
- §13 single-source TTL config
- §14 calibration policy ADR

## V1 freeze + V2 entry
V1 platform is **trustworthy intelligence infrastructure**. V2
adds **adaptive + predictive intelligence** on top of it.

V2 entry criteria + roadmap defined in
[`memory/project_v1_v2_split.md`](../../.claude/projects/-opt-AegisCore/memory/project_v1_v2_split.md)
(operator-side memory). Predictive AI / recommendations /
autonomous scoring escalation / Phase 6 stylometry are deferred
behind the v2 gate. Do not start v2 work until the v1 closure
checklist is fully burned down.
