# ADR-0001 — SDE static data: MariaDB canonical, Neo4j + OpenSearch as derived projections

**Status:** Accepted
**Date:** 2026-04-13
**Related:** [AGENTS.md § Plane boundary](../../AGENTS.md#plane-boundary),
[docs/CONTRACTS.md](../CONTRACTS.md), PR #13 (job-placement rule)

## Context

Several pillars need EVE's static reference data — universe topology
(regions, constellations, systems, stargates), item taxonomy (types, groups,
categories, market groups), and NPC stations — to function:

- **Killmails & Battle Theaters** resolves `victim_type_id` + `solar_system_id`
  on every killmail and rolls battles up by region/constellation.
- **Buyall & Doctrines** lists types, groups, and market-group trees in the
  doctrine builder and the stock-target UI.
- **Spy Detection** needs system adjacency (who was seen where, how close) and
  type lookups for asset-drop analysis.
- Filament admin resources across all pillars render type / system names from
  FKs.

The upstream source is CCP's **Static Data Export (SDE)** — a tarball CCP
publishes on each expansion (roughly quarterly). ESI's `/universe/*`
endpoints serve the same data with a REST veneer; hitting ESI 40,000 times
to build a type table when CCP ships it as a 500MB tarball is a category
error.

This ADR narrows to **Tier 1 data only**: immutable reference data sourced
from the SDE tarball. Tier 2 (semi-static data like player structures,
alliances, corps — sourced from ESI polling) and Tier 3 (operational data
like killmails and market orders) are out of scope and will be addressed in
future ADRs.

Three access patterns exist, and they don't want the same store:

| Pattern | Example | Best-fit store |
|---|---|---|
| Primary-key resolve | `type_id=587 → Rifter` on a killmail render | MariaDB (+ Redis cache) |
| Graph traversal | "shortest route Jita → 9-4RP2" or "systems within 5 jumps of this battle" | Neo4j |
| Fuzzy / faceted search | "find all medium missile launchers, T2, meta ≤ 8" | OpenSearch |

No single store serves all three well.

## Decision

**MariaDB `ref_*` tables are the canonical store for SDE data.** Neo4j and
OpenSearch are derived projections, rebuildable from MariaDB. The project-wide
data-ownership rule ("MariaDB canonical, derived stores rebuildable") applies
without exception to reference data.

Concretely:

1. **Load path.** An ops-triggered Python tool (`make sde-import`) downloads
   a pinned SDE tarball, parses it, and bulk-loads the `ref_*` tables inside
   a single MariaDB transaction. On success it emits one outbox event:
   `reference.sde_snapshot_loaded`.

2. **Projections.** Two Python consumers of the event:
   - `graph_universe_sync` → Neo4j. Nodes: `:Region`, `:Constellation`,
     `:System`, `:Station` (NPC only for phase 1). Relationships:
     `:IN_REGION`, `:IN_CONSTELLATION`, `:JUMPS_TO`, `:HAS_STATION`.
     Types / groups / market-groups **do not** go in Neo4j — they're not
     graph data.
   - `sde_to_opensearch` → OpenSearch, **deferred to phase 2**. When it
     lands, it will produce two indices — `ref_item_types` and
     `ref_systems` — each a denormalized, search-ready doc (dogma attrs
     flattened, market-group path denormalized). **One index per logical
     entity, not one per ESI endpoint.** Alias-swap pattern for atomic
     reindex.

3. **Source.** Port the SDE importer and graph projection logic from
   SupplyCore (which already handles dataset-variant and stargate-edge
   quirks), then trim AegisCore-specific parts. Do not reimplement
   clean-slate — re-discovering SDE's edge shapes is waste.

4. **Reload strategy.** Truncate + reload inside one MariaDB transaction,
   during a maintenance window. OpenSearch uses alias-swap so queries never
   see a half-built index. SDE reloads are rare (~4/year) and
   operator-initiated, so the maintenance-window constraint is acceptable.

5. **Pinning.** The SDE version loaded is pinned in the repo
   (e.g. `infra/sde/version.txt`). Auto-downloading "latest" is rejected —
   reproducible builds and PR-visible version bumps beat silent CCP
   breakage.

6. **PHP access.** Laravel reads `ref_*` via Eloquent (single-row /
   paginated lookups) and Redis cache (hot id→name resolves). No Laravel
   writes to `ref_*`. No Laravel call to ESI for reference data — if a
   `type_id` is unknown, that's an ops problem (reload the snapshot), not
   a runtime fallback.

### Phase 1 table scope

Loaded in phase 1:

- `ref_regions`
- `ref_constellations`
- `ref_systems`
- `ref_stargates`
- `ref_celestials` (planets / moons / belts)
- `ref_item_categories`
- `ref_item_groups`
- `ref_market_groups`
- `ref_item_types`
- `ref_npc_stations`
- `ref_npc_corporations` (required for NPC-station name construction)
- `ref_station_operations` (required for NPC-station name construction)

Deferred to phase 2 (land with OpenSearch + doctrine/fitting features):

- `ref_dogma_attributes`
- `ref_type_dogma`
- Blueprint / industry / planet-schematic tables
- Localization tables
- Icon / graphic / skin-material tables

### Directory layout (Laravel side)

```
app/app/Reference/
├── Actions/       ← ResolveType, ResolveSystem (cached id→row resolvers)
├── Data/          ← TypeData, SystemData (spatie/laravel-data DTOs)
├── Events/        ← SdeSnapshotLoaded (outbox event)
└── Models/        ← SdeItemType, SdeSolarSystem, SdeStargate, …
```

`app/Reference/` sits **outside** `app/Domains/` and is **explicitly not a
pillar**. Cross-pillar references to `ref_*` tables are allowed — this is the
one place the no-cross-pillar-relations rule is relaxed, because reference
data is cross-cutting by definition. The parallel is `app/Outbox/`, which is
also plane-boundary plumbing outside the Domains structure.

## Alternatives considered

**1. OpenSearch as canonical, with one index per ESI endpoint.**
The initial instinct. Rejected because:
- Loses FK integrity — other pillars want `killmails.victim_type_id` to
  reference a real row, and OpenSearch isn't relational.
- Wrong tool for graph traversals (shortest path, N-hop neighbourhood).
- "One index per endpoint" balloons to ~100 indices with overlapping
  entities; "one index per logical entity" is the correct granularity.
- Conflicts with the project-wide "MariaDB canonical" rule for no reason.

**2. Skip MariaDB — load SDE directly into Neo4j and OpenSearch.**
Rejected because Filament admin wants relational joins (`Type → Group →
Category → MarketGroup`), cross-pillar FKs want referential integrity, and
carving an exception to the canonical-store rule creates exception-shaped
bugs later.

**3. ESI-on-demand with a local cache.**
Rejected because ESI is rate-limited, couples runtime to CCP's uptime, and
hitting 40,000 endpoints to rebuild a local type table is wasteful when the
same data ships as one tarball.

**4. Reimplement the SDE importer clean-slate.**
Rejected. SupplyCore's importer has been beaten on real SDE snapshots and
handles dataset variants, stargate pair forms, missing station names, and
similar edge cases. Clean-slate rewrites re-discover those bugs.

**5. Auto-download latest SDE on every import.**
Rejected. Pinning the version in the repo makes SDE bumps a PR event (with
diffing and review), not a silent dependency drift.

**6. Versioned-table + view-swap reload strategy on the MariaDB side.**
Rejected for phase 1. Zero-downtime reload is overkill for a ~4/year
operator-triggered job. Truncate+reload inside a transaction during a
maintenance window is operationally simpler.

## Consequences

**Positive:**
- Uniform data-ownership model — no per-pillar exceptions to the "MariaDB
  canonical" rule.
- Each store does what it's best at — relational joins in MariaDB, graph
  traversal in Neo4j, fuzzy search (later) in OpenSearch.
- No runtime coupling to ESI for reference data. A CCP outage doesn't
  darken AegisCore.
- Reproducible builds via pinned SDE version.
- Phase-1 MVP gets a smaller surface (no OpenSearch SDE integration) with
  a clear expansion path.

**Negative:**
- Three stores for the same conceptual data. Reload involves orchestrated
  rebuilds.
- Brief write lock on `ref_*` tables during maintenance-window reload;
  readers block for seconds. Acceptable at quarterly cadence.
- Port work from SupplyCore needs careful boundary-carving to avoid
  dragging SupplyCore-specific concerns across.

**Neutral:**
- Operator playbook needed for "CCP published a new SDE" — this is a
  runbook concern, not a code concern.
- Phase-2 OpenSearch integration is a follow-up ADR, not a commitment
  lurking in this one.

## Implementation checklist (for follow-up PRs, not this ADR)

1. `app/app/Reference/` scaffold (Models, Actions, Data, Events directories
   + README documenting the "not a pillar" status).
2. Laravel migrations for the phase-1 `ref_*` table set.
3. `python/sde_importer/` — port from SupplyCore, trim to phase-1 scope.
4. `python/graph_universe_sync/` — port from SupplyCore.
5. `make sde-import` target + pinned `infra/sde/version.txt`.
6. `SdeSnapshotLoaded` event + Python relay consumer skeleton.
7. Operator runbook in `infra/notes.md` § SDE reload.

Each of those is its own PR. This ADR locks the shape; PRs fill it in.
