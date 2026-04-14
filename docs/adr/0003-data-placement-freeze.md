# ADR-0003 — Data placement freeze: per-store ownership across the four pillars

**Status:** Accepted
**Date:** 2026-04-14
**Related:** [ADR-0001](0001-static-reference-data.md) (SDE),
[ADR-0002](0002-eve-sso-and-esi-client.md) (ESI + SSO),
[AGENTS.md § Data ownership](../../AGENTS.md#data-ownership-hard-rule),
[docs/ARCHITECTURE.md](../ARCHITECTURE.md),
[docs/CONTRACTS.md](../CONTRACTS.md)

## Context

The high-level ownership rule in `AGENTS.md` — MariaDB canonical, Redis
ephemeral, Neo4j / OpenSearch / InfluxDB derived — has been load-bearing
since phase 1 (ADR-0001 is the first concrete application). As the four
pillars begin landing their domain models (killmail ingest, valuation,
spy graph, battle theaters), three questions keep recurring at review
time:

1. Where do **valuation records** and the **price points they quote** live?
   InfluxDB looks tempting for time-series market data, but valuation
   requires exact replay ("what price did we use on 2026-03-14 for
   `type_id=587` in The Forge?") and that replay has to survive a derived
   store rebuild.
2. Where does **temporal identity history** (character moved corp, corp
   moved alliance, alliance dissolved) live? Spy detection depends on
   reconstructing who-was-where at killmail time.
3. What exactly does "Redis is ephemeral" mean? It's been read
   inconsistently — some PRs treat it as "no persistence at all", others
   as "queue state is fine". Horizon runs on Redis today and the stack
   depends on that.

The freeze below answers these once so future PRs cite one ADR instead
of re-litigating.

## Decision

### MariaDB — canonical source of truth

- Killmails: killmail, victim, attackers, items.
- Character / corp / alliance identity + **temporal history** (every
  corp / alliance transition we observe, timestamped).
- **Valuation records with provenance**: `source` (e.g. `jita_sell_p05`,
  `esi_fallback`), `fallback` boolean, `version` of the valuation
  algorithm, `time_used_at` pointing at the market observation actually
  quoted.
- **Raw market observations** used as valuation inputs. Canonical here
  so valuations are replayable from MariaDB alone.
- `outbox` (schema in [CONTRACTS.md](../CONTRACTS.md)).
- `ref_*` SDE reference tables (ADR-0001).

### Redis — acceleration only, never a system of record

Redis holds state whose loss would impair latency but never correctness.
Roles (illustrative, not exhaustive):

- Application cache, sessions, Laravel/Horizon queue state.
- Hot ESI cache (conditional-GET by ETag / Last-Modified) — today in
  `EsiClient` per ADR-0002.
- Negative cache (known-404 / known-403 ESI endpoints).
- Single-flight / request-coalescing locks.
- Short-lived rate-limit helpers (per-group token tracking) — today in
  `EsiRateLimiter` per ADR-0002.

A cold Redis must be survivable: queues drain from scratch, caches
re-warm from MariaDB + ESI, sessions log back in. Nothing business-
critical is lost.

### Neo4j — derived, Python writes, no canonical ownership

Relationship graph for spy investigation: co-participation (killmail
co-presence), affiliation edges (character → corp → alliance across
time). Rebuildable from killmails + identity history in MariaDB.

### OpenSearch — derived, Python writes, no canonical ownership

Denormalized killmail search docs; analyst filters, facets, timelines.
Rebuildable from MariaDB killmail tables.

### InfluxDB — derived, Python writes, no canonical ownership

Market series and rollups aggregated from MariaDB raw market
observations; optional valuation-trend analytics. Rebuildable from
MariaDB.

### Execution plane split

- **Laravel (control plane) owns:** settings / UI / API / auth / RBAC;
  donor hub preferences + admin workflows; light synchronous ESI paths
  (SSO token exchange, donations wallet poll — see ADR-0002). No heavy
  ingest or compute.
- **Python (execution plane) owns:** ingestion; ESI enrichment + cache
  client behaviour for heavy polling (killmails, rosters, markets at
  scale — see ADR-0002 § Phase 2); projection workers (Neo4j,
  OpenSearch, InfluxDB); recompute and backfill jobs.

Cross-plane triggering is the outbox pattern, unchanged
([CONTRACTS.md § Plane boundary](../CONTRACTS.md#plane-boundary--laravel--python)).

## Alternatives considered

- **InfluxDB as canonical for market data.** Rejected: breaks the
  "derived is rebuildable from MariaDB + external sources" invariant
  and makes valuation replay dependent on a store that doesn't back up
  like MariaDB does. Series / rollups in Influx are fine; raw
  observations stay in MariaDB.
- **Redis "acceleration only" as an exhaustive whitelist.** Rejected:
  would force Horizon queue state and Laravel sessions off Redis, which
  is a larger migration than this freeze intends. The rule is "never a
  system of record"; the enumerated roles are illustrative.
- **All ESI in Python from day 1.** Rejected: SSO token exchange must
  stay synchronous in the request path Laravel serves. Splitting heavy
  polling only is ADR-0002's existing decision; this freeze does not
  expand that scope.
- **Valuation records without provenance.** Rejected: we have to be
  able to answer "why did we quote 42M ISK on this killmail?" in
  audit-and-dispute contexts. Provenance is cheap to write and
  expensive to reconstruct after the fact.

## Consequences

**Positive.**

- Valuation replay is end-to-end reproducible: the valuation row and
  the market observation it quotes both live in MariaDB, so a single
  backup restores both.
- InfluxDB, Neo4j, OpenSearch can be dropped and rebuilt at any time
  without data loss. Supports the existing "derived is rebuildable"
  invariant under real load.
- Redis outage impairs latency, never correctness. Clear operational
  story for incident response.
- Temporal identity history makes spy-detection reasoning about
  "who-was-where-when" a SQL query rather than a graph reconstruction.

**Negative.**

- Raw market observations in MariaDB will be a sizeable table. Requires
  an archival / partitioning strategy before it hits multi-TB. Not
  solved here; tracked as a phase-2 follow-up alongside the table's
  first migration.
- Two ESI clients coexist until the phase-2 Python ESI poller lands
  (Laravel's `EsiClient` / `EsiRateLimiter` + the future Python
  equivalent). Explicit tradeoff per ADR-0002; this freeze keeps it.
- Temporal identity history tables grow per observation, not per
  transition. Retention policy is deferred to the migration PR.

**Neutral.**

- No schema changes land with this ADR. It's normative, not executable.
  Subsequent migrations that add `valuation_records`,
  `market_observations`, and `character_corp_history` /
  `corp_alliance_history` tables cite ADR-0003 in their description.

## Follow-ups (not part of this ADR)

- Migration + schema for `valuation_records` and `market_observations`
  (pillar: buyall-doctrines and killmails-battle-theaters).
- Migration + schema for temporal identity history (pillar:
  users-characters and spy-detection).
- Retention / archival policy for `market_observations` at scale.
- Phase-2 Python ESI poller per ADR-0002.
