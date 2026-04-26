# Phase 4.9 — intelligence freshness

Verification snapshot 2026-04-27.

## Schema

Migration `2026_04_27_010000_create_phase49_intelligence_freshness.php`
adds three uniform columns to every operator surface:

```sql
freshness_state ENUM('fresh','aging','stale','expired') NOT NULL DEFAULT 'fresh',
source_window_start DATETIME NULL,
source_window_end   DATETIME NULL,
INDEX idx_<table>_fresh (freshness_state)
```

12 surfaces touched:
- daily_operational_digest
- strategic_alerts
- operational_incidents
- operational_hostile_clusters
- operational_corridors
- operational_force_compositions
- system_threat_surface
- alliance_operational_profiles
- coalition_behavior_comparisons
- incident_narratives
- doctrine_evolution_events
- verified_intelligence_items

## TTL ladder

Defined in two places, kept in sync:
- `python/counter_intel/phase4_freshness.SURFACE_TTL`
- `app/app/Services/IntelFreshness::SURFACE_TTL`

| surface             | fresh ≤ | aging ≤ | stale ≤ |
|---------------------|---------|---------|---------|
| digest              | 6h      | 24h     | 72h     |
| alert               | 1h      | 6h      | 24h     |
| incident            | 0.5h    | 6h      | 48h     |
| cluster             | 0.5h    | 6h      | 48h     |
| corridor            | 24h     | 7d      | 30d     |
| force_composition   | 24h     | 7d      | 30d     |
| threat_surface      | 24h     | 7d      | 14d     |
| alliance_profile    | 24h     | 7d      | 30d     |
| coalition           | 24h     | 7d      | 30d     |
| narrative           | 6h      | 24h     | 7d      |
| doctrine_evolution  | 7d      | 30d     | 90d     |
| verified            | 7d      | 30d     | 90d     |

Anything past `stale` is `expired`. NULL timestamp → `expired`.

## Compute

`make ci-phase49-freshness` re-classifies all 12 surfaces in
one pass. First run on bloc 1:

```
daily_operational_digest          fresh:1
strategic_alerts                  aging:14 stale:12 expired:248
operational_incidents             aging:121 stale:583 expired:9184
operational_hostile_clusters      aging:152 stale:696 expired:10507
operational_corridors             fresh:112 aging:321 stale:1242 expired:1469
operational_force_compositions    expired:42
system_threat_surface             fresh:390
alliance_operational_profiles     fresh:642
coalition_behavior_comparisons    fresh:5
incident_narratives               fresh:300
doctrine_evolution_events         fresh:83
verified_intelligence_items       (empty)
```

Pattern matches expectations:
- Threat surface + alliance profiles + narratives all freshly
  computed today → 100% fresh.
- Force compositions all expired (March 1-17 dscan range, >30
  days back).
- Strategic alerts heavily expired (248) because alert TTL is
  24h and most rows came from a 60-day backfill.
- Incidents/clusters dominated by expired because operational
  TTL is 48h.

## PHP helper

`App\Services\IntelFreshness`:

```php
classify(string $surface, ?string $timestamp): string
resolve(string $surface, ?string $timestamp, ?string $persisted): string
pill(string $surface, ?string $timestamp, ?string $persisted, ?string $windowStart, ?string $windowEnd): string
```

Live re-evaluation: `resolve()` picks the more-aged of (live
classification, persisted column). A row classified `fresh` an
hour ago automatically shows `aging` on the next render —
persisted state is the floor, never the ceiling.

Blade component for clean usage:

```blade
<x-intel-freshness surface="incident"
    :timestamp="$incident->end_at"
    :persisted="$incident->freshness_state ?? null"
    :windowStart="$incident->start_at"
    :windowEnd="$incident->end_at" />
```

Renders an inline pill with state, age (e.g. "fresh · 6m ago"),
and optional source window.

## UI surfaces wired

Pills landed on:
- `/portal/intelligence/daily` header (digest)
- `/portal/intelligence/alerts` per-alert card
- `/portal/intelligence/fc` per-incident, per-alert, per-corridor row
- `/portal/intelligence/director` profile window header + per-coalition + per-doctrine-event
- `/portal/operations/heatmap` header (threat_surface)
- `/portal/operations/incidents/{id}` dossier header
- `/portal/intelligence/verified` per-item row
- `/portal/intelligence/trust` per-surface freshness rollup table

## Idempotency

`run_freshness` re-runs are safe — `UPDATE … SET …` with the
same CASE expression. Re-running produces identical state
(modulo time progression — a row that was `fresh` 30 minutes
ago may now be `aging`, which is the correct behavior).

## Caveats

1. The persisted column is only the floor. UI must call
   `IntelFreshness::resolve()` (or the `<x-intel-freshness>`
   component) to surface aging that happened between compute
   runs.
2. The TTL ladder is a single source of truth duplicated in
   PHP + Python. Any change to one side must update the other —
   noted in both files' module docstrings.
3. No autonomous schedule. Freshness recompute remains a
   manual `make ci-phase49-freshness` per the user's directive
   from Phase 4.8.
4. `verified_intelligence_items` had zero rows so the compute
   tally is empty; once analysts pin items the column populates.
