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
- Control Plane: PHP (UI/API/settings/auth/light orchestration)
- Execution Plane: Python (ingestion/compute/graph pipelines)
- No heavy compute in PHP.

## Data ownership (hard rule)
- MariaDB: canonical source of truth
- Neo4j: graph projection only
- OpenSearch: search/aggregation projection only
- InfluxDB: metrics/time-series only

Derived stores must be rebuildable from MariaDB + external sources.

## UX principles
- Intent first, details second
- "What changed / why it matters / what to do next"
- Progressive disclosure
- Clear loading/progress/error states

## Phase 1 scope
- Repo skeleton
- Compose stack
- Health endpoints
- One E2E reference pipeline (killmails)
- Other 3 pillars are stubs only

## Where to go next
- Running the stack: [`README.md`](README.md)
- Architecture overview: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Roadmap: [`docs/ROADMAP.md`](docs/ROADMAP.md)
- API + Job contracts: [`docs/CONTRACTS.md`](docs/CONTRACTS.md)
- Operational notes: [`infra/notes.md`](infra/notes.md)
- Change history: [`CHANGELOG.md`](CHANGELOG.md)
