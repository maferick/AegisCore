# ADR-0006 — Battle Theater reports

**Status:** Accepted
**Date:** 2026-04-17
**Related:** [ADR-0003](0003-data-placement-freeze.md) (data placement),
[ADR-0004](0004-market-data-ingest.md) (killmail ingest lineage),
[Phase-1 classification](0005-private-market-hub-overlay.md) — shares
`CoalitionBloc` / `CoalitionEntityLabel` / `ViewerContext` as the
authoritative side-assignment primitive.

## Context

One of AegisCore's four pillars is "Killmails & Battle Theaters". The
killmail ingest + enrichment pipeline already lands and decorates
1M+ killmails (ADR-0004). What's missing is the layer that groups
dense killmail activity into coherent *theaters* and reports them back
to a human who wants one question answered: "what happened in this
fight, and who won?"

A prior implementation in the sibling project SupplyCore built an
extensive theater model — battles, theaters, union-find clustering,
alliance summary tables, graph-based side inference, LLM-generated
summaries, per-ship breakdown columns. It worked, but two lessons
stood out:

1. **Proportional ISK attribution corrupted the metrics.** When the
   platform split each killmail's ISK across attackers by damage
   share, side totals stopped balancing, "who won" became
   a philosophical question, and engineers argued about weighting
   instead of shipping reports.
2. **Storing side as a column broke under cross-coalition viewing.**
   A fight tagged "friendly vs opponent" read correctly to the
   config-owner but upside-down to anyone on the other bloc. The
   same kill, two truths; storage couldn't represent both.

## Decision

### 1. Metric contract (locked)

The user spec is adopted verbatim. No proportional splitting.

**Side-level (derived at read time):**

| Metric        | Definition                                            |
|---------------|-------------------------------------------------------|
| ISK Lost      | `SUM(killmails.total_value)` for mails whose **victim** is on this side |
| ISK Killed    | The opposing side's ISK Lost. Not computed independently. One number, two perspectives. |

**Pilot-level (stored per theater participant):**

| Metric       | Definition                                                     |
|--------------|----------------------------------------------------------------|
| Kills        | Count of distinct killmails where pilot appears on the attacker list, regardless of damage done. EWAR with 0 HP dealt gets +1. |
| Final Blows  | Subset of kills. Count of killmails where `attacker.final_blow = 1` for this pilot. |
| Damage Done  | `SUM(attacker.damage_done)` across participated killmails. Raw HP, display only. |
| Damage Taken | `SUM(victim.damage_taken)` where pilot is victim. Raw HP, display only. |
| Deaths       | Count of killmails where pilot is victim.                      |
| ISK Lost     | `SUM(killmails.total_value)` where pilot is victim.            |

Nothing splits; everything aggregates. Side totals reconcile by
construction (Side A's ISK Lost = Side B's ISK Killed because both
derive from the same sum of victim rows).

### 2. Side model is viewer-relative, not stored

Pilots have an alliance and a corporation; alliances map to
`CoalitionBloc` via `CoalitionEntityLabel` (ADR-0005-adjacent
infra already in production). The side assignment for a render is
a pure function of (theater pilots' alliances, viewer's confirmed
bloc, coalition labels):

```
side_of(pilot, viewer) =
    A if pilot.alliance ∈ viewer.bloc
    B if pilot.alliance ∈ dominant_opposing_bloc(theater, viewer)
    C otherwise
```

`dominant_opposing_bloc(theater, viewer)` is the non-viewer bloc with
the highest `ISK_lost` total in that theater. A WinterCo viewer looking
at a fight between Imperium and PanFam sees "Side A: neither of you /
Side B: Imperium" with PanFam collapsed into Side C, because neither
bloc is the viewer's. That's the correct answer for that viewer — the
fight is not theirs, but Imperium carried the most loss.

**Explicitly not stored:** `side_a` / `side_b` columns anywhere. Every
render computes sides from the pilot's alliance_id and the viewer's
bloc. Two viewers see the same theater with different side labels
applied to the same pilots.

Viewers without a confirmed bloc (new user, unresolved inference) fall
back to the two largest blocs in the theater by ISK_lost. They can
swap which is A vs B via a UI control; the swap is ephemeral, not
stored.

### 3. Theater = cluster of killmails in (time, space, participants)

Clustering runs in Python (ADR-0003: heavy compute = execution plane).
Inputs: the killmails table. Output: `battle_theaters` rows + three
child tables. Rules:

- **Time window**: killmails within 45 minutes of each other in the
  same constellation are candidates for the same theater.
- **Spatial edge**: same region + ≥10% participant overlap bridges
  two candidate clusters.
- **Gate-distance edge** (phase 2): same region + ≤5 jumps between
  primary systems + ≥10% overlap also bridges.
- **Minimum size**: a theater needs ≥10 distinct participants. Below
  that, the killmails are left un-theatred (they may still render as
  solo kills elsewhere).
- **Lock horizon**: theaters older than 48h get `locked_at` set and
  a frozen `snapshot_json`. After that, new killmails landing in the
  same window don't mutate them — fresh activity gets a new theater.

Clustering is full-scan over the unlocked window, not incremental. An
unlocked theater may grow, shrink, split, or merge on any pass. The
48h lock gives the operator a stable publication horizon.

### 4. Tables

All owned by Python (it writes), readable by Laravel (it renders).
FKs to `killmails.killmail_id` cascade on delete so a re-ingest can
rebuild without orphans.

- **`battle_theaters`** — one row per cluster. Rollup columns
  (`total_kills`, `total_isk_lost`, `participant_count`,
  `start_time`, `end_time`) + `primary_system_id`, `region_id`,
  `locked_at`, `snapshot_json`. Snapshot_json is the materialised
  read-side payload used after lock to avoid re-joining 6 tables.
- **`battle_theater_killmails`** — many-to-many (`theater_id`,
  `killmail_id`). Primary key `(theater_id, killmail_id)`; a killmail
  belongs to at most one theater.
- **`battle_theater_systems`** — per-system rollup inside the theater
  (`theater_id`, `solar_system_id`, `kill_count`, `isk_lost`). Cheap
  at query time but worth denorming for the per-system breakdown UI.
- **`battle_theater_participants`** — per-pilot rollup
  (`theater_id`, `character_id`, `corporation_id`, `alliance_id`,
  `kills`, `final_blows`, `damage_done`, `damage_taken`, `deaths`,
  `isk_lost`, `first_seen_at`, `last_seen_at`). This is the source
  of truth for pilot-level display and the input to side aggregation.

**No `battle_theater_alliance_summary` table.** `GROUP BY alliance_id`
on `battle_theater_participants` is a sub-millisecond query and
keeps us honest: whatever the alliance sees IS whatever the pilot
column adds up to. A second materialised view would drift.

### 5. Plane boundary

| Concern                               | Plane          |
|---------------------------------------|----------------|
| Clustering job, rollup writes         | Python         |
| Backfill (reprocess all killmails)    | Python         |
| Locking + snapshot_json generation    | Python         |
| List / detail page render             | Laravel        |
| Side resolution (viewer-relative)     | Laravel (view) |
| Coalition label joins for side render | Laravel (view) |

Laravel never writes `battle_theater*`. Python never renders HTML.
Cross-plane triggers (if needed) go through the outbox, same as the
market + killmail paths.

## Alternatives considered

### Store side_a / side_b columns per killmail

The SupplyCore way. Rejected: the view is viewer-relative. Storing
sides bakes one observer's perspective into the row, which then
either misleads every other observer or needs duplication per
viewer. Compute at render time; cost is negligible.

### Materialise per-alliance rollup

Rejected at first cut. The pilot table is small by row count
(a 200-pilot battle produces 200 rows), `GROUP BY alliance_id` is
index-friendly, and a second table would need to stay in lockstep
with the pilot table or introduce a reconciliation bug class. Add
the rollup later if a specific report proves slow.

### Proportional ISK attribution by damage share

Rejected by the spec. Discussed in the Context. Side totals break;
metric clarity collapses.

### Cluster in Laravel Horizon

Rejected per ADR-0003. Clustering over 48h of killmails can touch
tens of thousands of rows; Horizon queue jobs cap at 500 rows and
2s p95. Python has no such cap and already owns the killmail
pipeline.

## Consequences

### Positive

- **Side totals reconcile by definition.** No philosophical argument
  about "who won"; the spec is arithmetic.
- **Any viewer sees their own truth.** WinterCo, Imperium, and a
  third-party observer all load the same theater row and see side
  labels flip to their own perspective. No data duplication.
- **Minimal tables.** Four new tables, no alliance rollup view, no
  timeline bucket table. The UI renders from the pilot table +
  killmails + SDE.
- **Retryable.** An unlocked theater can be dropped + recreated on
  any clustering pass without data loss. Locked theaters freeze to
  a snapshot and are no longer subject to re-clustering.

### Negative

- **Side is computed per render.** A page that lists many theaters
  has to resolve side per theater per pilot. Mitigated by the
  viewer's bloc being a single row read + a cached label map in
  session memory; the per-theater loop is in-memory join, not DB.
- **Gate-distance clustering is deferred.** Phase 1 uses constellation
  + same-region-overlap only. A fleet battle that hops one gate into
  the next region gets split. Phase 2 reintroduces the gate-distance
  ≤ 5 rule.
- **48h lock horizon is a policy, not a guarantee.** An operator
  re-ingesting old killmails hits the "theater already locked"
  rule and has to explicitly unlock to recompute. Handled via
  `artisan theater:unlock {id}` on the admin side.

### Neutral

- **Timeline charts render from killmails directly.** No
  `battle_theater_timeline` table — one `SELECT killed_at, victim...
  FROM killmails WHERE killmail_id IN (theater's list) ORDER BY
  killed_at` feeds the chart. Same story for the per-system breakdown
  (derived from killmails via join to `ref_solar_systems`).
- **Ship breakdown is per-pilot, on demand.** Expand-a-pilot action
  in the UI runs a one-pilot query against `killmail_attackers` +
  `killmail_items`. Not denormalised — the row rate is small and
  the breakdown is rarely viewed.

## Follow-ups

1. **Phase 1** — migrations + Python `theater_clustering` worker +
   Laravel models + Portal list/detail page. ~~Pending.~~
2. **Phase 2** — gate-distance clustering edge (needs the Neo4j
   universe graph, already synced).
3. **Phase 3** — per-theater LLM summary ("headline + verdict +
   narrative"), stored on `battle_theaters.ai_*` columns. Optional;
   needs a model + token budget decision.
4. **Phase 4** — spy-detection integration: a pilot whose usual bloc
   appears on the "wrong" side in a theater flags as suspicious.
   Uses the same viewer-relative side resolver against the pilot's
   known affiliations over time.
5. Cross-theater pilot profiles (feed of "recent theaters I was in").
6. Filament admin resource for operator actions: unlock, merge two
   theaters, split a theater, manual tag.
