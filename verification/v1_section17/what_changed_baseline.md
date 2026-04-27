# §17.1 — "What Changed?" first-run baseline

**Generated:** 2026-04-27.
**Pipeline:** `phase17-what-changed`.
**Lane:** `intelligence_generation`.
**Bloc:** 1.

First safe-AI surface per ADR 0012 / 0013. Deterministic SQL
delta gatherer + templated synthesizer. LLM rewrite is a
follow-up; this baseline ships rule-based prose so behaviour is
reproducible byte-identically given the same inputs.

## Surfaces wired in v1

| surface                   | source table                       | column         | semantics                          |
|---------------------------|------------------------------------|----------------|------------------------------------|
| operational_incidents     | `operational_incidents`            | `end_at`       | new incidents closed in window     |
| strategic_alerts          | `strategic_alerts`                 | `detected_at`  | new alerts opened in window        |
| operational_corridors     | `operational_corridors`            | `last_seen_at` | corridors active in window         |
| system_threat_surface     | `system_threat_surface`            | `computed_at`  | top systems by threat-score delta  |
| doctrine_evolution_events | `doctrine_evolution_events`        | `computed_at`  | evolution events in window         |

## Window definitions

```
1h:  current = [now-1h, now);   comparison = [now-2h, now-1h)
6h:  current = [now-6h, now);   comparison = [now-12h, now-6h)
24h: current = [now-24h, now);  comparison = [now-48h, now-24h)
7d:  current = [now-7d, now);   comparison = [now-14d, now-7d)
```

## Cadence (host cron)

```
3,18,33,48 * * * *   1h  window
12         * * * *   6h  window
22         */4 * * * 24h window
30         4 * * *   7d  window
```

Lines documented in `scripts/host_cron_freshness_writers.txt`.

## Confidence ladder applied (ADR 0013)

```
0 corroborating surfaces → low    (single signal — informational)
1 corroborating surface  → medium (cross-surface confirmation)
2+ corroborating surfaces → high  (strong multi-surface signal)
confirmed                → never set by AI; requires operator
```

A surface "corroborates" when its delta band is ≥ 1.5x or it
contributes a non-empty top_movers list (threat_surface).

## Severity bands

```
ratio ≥ 4.0  → critical
ratio ≥ 2.5  → elevated
ratio ≥ 1.5  → warning
otherwise    → info
```

Floor: surfaces with both current and comparison count below 5
collapse to `info` regardless of ratio (small-N protection).

## First-run output sample (24h window, bloc 1)

The 2026-04-27 first run produced 5 findings across the 24h
window, confidence=high (corroboration=3):

| summary_type             | severity | confidence | title                                                              |
|--------------------------|----------|------------|--------------------------------------------------------------------|
| alert_volume             | critical | high       | Strategic alerts in 24h: 25 new (was 5, 5.0×)                      |
| corridor_activity        | critical | high       | Corridors active in 24h: 107 (was 23, 4.652×)                      |
| doctrine_evolution       | warning  | high       | Doctrine-evolution events in 24h: 83                               |
| incident_volume          | info     | high       | Operational incidents in 24h: 279 closed (was 254, 1.098×)         |
| threat_surface_movement  | warning  | high       | Threat-surface movement over 24h: top mover H-5GUI (+10.00)        |

The 7d window produced equivalent findings with smaller ratios
(1.42x–1.64x) and `warning`/`info` severity — the longer window
naturally smooths spikes.

## ADR 0013 binding-field coverage

Every persisted row carries the six required fields:

| field             | source                                   | example                                                        |
|-------------------|------------------------------------------|----------------------------------------------------------------|
| confidence band   | `confidence` ENUM column                 | `high`                                                         |
| evidence list     | `evidence_json` LONGTEXT                 | `{"current_count": 25, "comparison_count": 5, "ratio": 5.0}`   |
| source references | `source_refs_json` LONGTEXT              | `[{"table":"strategic_alerts","field":"detected_at",...}]`     |
| caveats           | `caveats_json` LONGTEXT                  | `["corridor counts include both new and recurring..."]`        |
| freshness state   | `freshness_state` ENUM column            | `fresh`                                                        |
| why-strengthened  | `why_strengthened_json` LONGTEXT         | `{"prior_confidence":"medium","count_delta_vs_prior_render":5}`|

The Filament page `/portal/intelligence/what-changed` renders
each card with these six fields visible (caveats and
why-strengthened collapsed in `<details>` so the card itself
stays compact). Render contract is asserted by
`tests/Feature/WhatChangedRenderContractTest.php`.

## Audit trail (ADR 0013)

Every persisted finding writes a paired row to `intel_audit_log`
with `actor_kind='ai'` and `surface='ai_change_summary'`:

```
SELECT COUNT(*) FROM intel_audit_log
 WHERE actor_kind = 'ai' AND surface = 'ai_change_summary';
```

After the first run this returns 10 (5 findings × 2 windows
exercised). Operator-side reads of the page are not yet wired
to write audit rows — that's a polish item, not first-ship
critical.

## Caveats observed in first-run output

These caveats land verbatim on the rendered cards:

- "low absolute volume — single events drive the band"
  (incident_volume when current_count < 5)
- "no comparison baseline (zero prior incidents) — ratio undefined"
  (incident_volume when comparison_count == 0)
- "low absolute count — single alert can drive the band"
  (alert_volume when current_count < 3)
- "interpret with care — could indicate uploader gap rather
  than real silence" (corridor_silence)
- "corridor counts include both new and recurring; check
  route_classification for novelty" (corridor_activity)
- "threat-surface compares snapshots, not deltas in raw
  activity — score weights apply" (threat_surface_movement)
- "small absolute deltas (<0.05) suppressed"
  (threat_surface_movement)
- "doctrine-evolution surfaces alliance composition shifts;
  needs operator validation" (doctrine_evolution)

## Idempotency

Unique key on `(viewer_bloc_id, window_type, summary_type)` —
each (bloc, window, surface) tuple is a single "latest snapshot"
row. Re-runs UPSERT into it. Re-running the 24h pipeline twice
in a row produces:

```
1st run: 5 rows inserted, 5 audit rows inserted
2nd run: 0 rows inserted, 5 rows updated, 5 audit rows inserted
```

The audit log accumulates one row per generation regardless of
upsert state — this is intentional (each generation is its own
event in the audit trail, even when the rendered prose is
unchanged).

## Operational gate (§17.1 specific)

Burn-down item: pipeline runs daily for ≥7 days, ≥10 cards
surfaced, operator reports ≥1 finding actionable per day on
average. Track in this document as runs accumulate.

## Open polish items (not blockers for §17.1 ship)

1. **LLM rewrite of templated prose** — current narratives are
   bullet-list style. Plug `intel_copilot` LLM call to rewrite
   each finding's `summary` field while keeping `evidence_json`
   and `source_refs_json` deterministic. Render `ai_model` shows
   `rule_based_v1` until the LLM path lands.
2. **Operator-side audit on view** — record `actor_kind='user'`
   `intel_audit_log` rows when the operator opens a finding's
   evidence. Closes the audit loop "what AI said vs what
   operator did".
3. **History view** — current schema keeps only the latest
   snapshot per (bloc, window, summary_type). To enable trend
   analysis ("how many findings did the system surface per day
   over the last month?"), either uncompress the unique key or
   add a `phase17_change_summary_history` archive table. Pick
   approach when first-week volume is known.
4. **More surfaces** — first ship covers 5 surfaces. Operational
   clusters, coalition_behavior_comparisons, daily_digest
   sections are obvious next adds. Each one is a
   `_delta_*` + `_synth_*` pair following the same pattern.
5. **False-positive caveats per ADR 0013** — once the operator
   reviews 50+ findings, mine the caveat language from feedback
   to expand the per-summary_type caveat lists.
