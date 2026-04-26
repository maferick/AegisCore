# Phase 4.5 — force composition + doctrine matching

Verification snapshot 2026-04-26.

## Schema

Migration `2026_04_26_240000_create_phase45_force_composition_tables.php`:

- `operational_force_compositions` — per (cluster, dscan snapshot)
  with role totals (logistics, tackle, dps, capital, super, bomber,
  ewar, command), doctrine match (id + name + match_pct +
  confidence), projection / mobility / brawl_range enums, plus
  ship_breakdown_json + evidence_json.
- `operational_force_transitions` — sequential dscan deltas inside
  one incident: tackle_to_capital, subcap_to_capital, kite_to_brawl,
  brawl_to_kite, bomber_reinforcement, logistics_spike,
  doctrine_swap, escalation, de_escalation, unknown.
- `system_threat_surface` extended with capital_score,
  logistics_score, doctrine_threat_score,
  escalation_propensity_score, mobility_profile.

## Compute (4.5A + 4.5C)

`make ci-phase45-force-compositions VIEWER_BLOC=1`:

```
{"clusters": 273, "compositions_written": 42}
```

Top compositions on bloc 1:

- **957-ship Maelstrom DPS** — 107 logi, 80 tackle, 1 capital,
  projection=strategic, mobility=slow, brawl_range=long.
- **788-ship Maelstrom DPS** — long-range projection.
- Multiple 100–200 ship Ferox / Scythe fleets.

`make ci-phase45-force-transitions VIEWER_BLOC=1`:

```
{"compositions": 42, "transitions_written": 4}
```

- 1× tackle_to_capital
- 1× escalation
- 2× unknown (ship_delta < 30 cluster-internal noise)

## Threat surface integration (4.5E)

`make ci-phase4-threat-surface VIEWER_BLOC=1 CI_ARGS="--window-days 90"`:

```
{"viewer_bloc_id": 1, "window_end": "2026-04-26", "systems": 390}
```

(Default 30-day window misses Phase 4.5 dscan snapshot range
2026-03-01 → 2026-03-17. 90-day window required for cross-walk
until newer compositions accrue.)

Tier breakdown (90d):

| tier       | n    |
|------------|------|
| safe       | 282  |
| watch      | 85   |
| contested  | 18   |
| hot        | 3    |
| strategic  | 2    |

Top systems with new force-derived components:

| system  | threat | tier      | cap | logi | doctrine | escalation_prop | mobility |
|---------|--------|-----------|-----|------|----------|-----------------|----------|
| H-5GUI  | 10.00  | strategic | 2.0 | 0.0  | 2.0      | 2.0             | slow     |
| 4-HWWF  |  7.92  | strategic | 0.0 | 0.0  | 0.0      | 0.0             | NULL     |
| 7-K5EL  |  6.78  | hot       | 0.0 | 1.0  | 1.0      | 0.0             | fast     |
| YMJG-4  |  6.14  | hot       | 1.0 | 0.0  | 4.0      | 2.0             | medium   |
| 8TPX-N  |  4.86  | hot       | 0.0 | 1.0  | 1.0      | 0.0             | medium   |
| C2X-M5  |  3.61  | contested | 0.0 | 0.5  | 4.0      | 0.0             | medium   |

Tier shifts vs pre-4.5E baseline (30d):

- 7-K5EL: strategic → hot (force comp doesn't dominate; cluster
  weight remained higher in shorter window).
- YMJG-4: contested → hot (4 doctrine hits + 1 capital + escalation
  propensity 2 = +2.46 threat_score).
- 8 systems newly promoted contested→watch on logi/doctrine
  signals.

mobility_profile distribution (90d): 361 NULL · 20 medium · 6 fast ·
3 slow. NULL is expected — only systems with at least one composition
in the window get a vote.

## Component weights

```python
weights = {
    "cluster_score": 1.0,
    "escalation_score": 1.5,
    "battle_linkage_score": 1.0,
    "density_score": 0.05,
    "reliability_score": 0.05,
    "corridor_score": 0.05,
    "dscan_score": 0.4,
    "capital_score": 1.5,
    "logistics_score": 0.3,
    "doctrine_threat_score": 0.5,
    "escalation_propensity_score": 1.0,
}
```

Rationale:
- capital_score 1.5: each capital = 1pt, super = 4pt, multiplier 1.5
  pushes a single capital + 1 super to ~7.5 raw alone.
- escalation_propensity 1.0: an incident with caps OR supers
  contributes 2 raw (×1.0 weight).
- logistics_score 0.3: high-volume signal (count×0.5), small weight
  to prevent shield-doctrine fleets from outweighing capital fleets.
- doctrine_threat 0.5: 1pt per matched doctrine, capped by
  observation count via the existing doctrine_confidence used in
  Jaccard scoring.

## Idempotency

Re-running ci-phase4-threat-surface with same args → systems=390,
identical tier counts. `ON DUPLICATE KEY UPDATE` keys
(viewer_bloc_id, solar_system_id, window_end_date, window_days).

## Dossier (4.5D)

`/portal/operations/incidents/{id}` now renders:

- "Force composition · N snapshot(s)" panel: peak ship total, peak
  logi/tackle, caps/supers, projection, mobility, brawl_range, top
  doctrines tag-cloud.
- Collapsible per-snapshot breakdown.
- "Force transitions · N" panel when present (color-coded by
  transition_type — caps escalation = red, kite/brawl flip =
  yellow, de-escalation = green).

Sample candidate incidents for hand-audit:
- #29850 UMI-KK escalation (2 compositions)
- #31132 UER-TH strategic (2 compositions)

## Known caveats

1. mobility_profile enum doesn't include `warp_capable`, while
   force_compositions.mobility ENUM does. Compute currently never
   emits `warp_capable` so this is dormant; if it does, persist
   needs a mapping (warp_capable → fast).
2. Force composition window is dscan-bound. Phase 4.4 dscan
   enrichment back-filled mostly March data; April snapshots will
   accrue as the EVE Log uploader continues feeding clusters.
3. `_estimate_brawl_range` is a hull-name heuristic — replace with
   turret/launcher analysis once we ingest fitting telemetry.
