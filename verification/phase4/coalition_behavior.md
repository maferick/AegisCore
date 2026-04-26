# Phase 4.6 — coalition + doctrine behavior intelligence

Verification snapshot 2026-04-26.

## Schema

Migration `2026_04_26_250000_create_phase46_coalition_behavior_tables.php`:

- `alliance_operational_profiles` — per (viewer_bloc, alliance,
  window_end, window_days). Doctrine distribution, response
  latency, fleet metrics, mobility / projection / brawl_range
  votes, operational_style classifier, evidence_json.
- `coalition_behavior_comparisons` — per (viewer_bloc, bloc_id,
  window). Bloc-level roll-up: alliance_count, escalation_rate,
  avg_fleet_size, doctrine_diversity, capital_usage_rate,
  strategic_density.
- `doctrine_evolution_events` — adoption / abandonment / sudden
  increase / decrease / capital_emergence / kite↔brawl shifts
  per alliance per window_end.
- `operator_operational_fingerprints` — per character non-identity
  operational style (rapid_escalator, heavy_logi_anchor,
  conservative_disengager, bait_specialist, corridor_camper,
  fast_responder, generalist).
- `operational_corridors` extended with route_classification
  (staging / reinforcement / escalation_path /
  deployment_migration / transit / unclassified) + per-class
  scores.

## Alliance attribution bridge

- Battle path: `incident.battle_id` →
  `battle_theater_participants.alliance_id`.
- Cluster character path: `cluster.involved_character_ids_json`
  → `characters.alliance_id`.
- Composition rollup uses union of both paths so doctrine
  metrics fill even when our `characters` table doesn't have
  every hostile pilot.

## §4.6A run (window_end=2026-04-26, window_days=90)

```
{"alliances_written": 245, "incidents_seen": 9888}
```

Operational style distribution:

| style            | n   |
|------------------|-----|
| defensive        | 126 |
| undetermined     | 64  |
| heavy_brawl      | 36  |
| corridor_control | 7   |
| harassment       | 5   |
| opportunistic    | 4   |
| fast_response    | 2   |
| capital_heavy    | 1   |

Top alliances by composition_count (where doctrine roll-up
worked):

| alliance               | bloc | inc  | comps | style         | avg_fleet | avg_caps | brawl |
|------------------------|------|------|-------|---------------|-----------|----------|-------|
| Fraternity.            | wc   | 935  | 15    | opportunistic | 170       | 0.27     | mixed |
| The Initiative.        | init | 459  | 13    | heavy_brawl   | 228       | 0.31     | mid   |
| Northern Coalition.    | wc   | 499  | 12    | heavy_brawl   | 217       | 0.17     | mid   |
| Insidious.             | wc   | 412  | 9     | heavy_brawl   | 269       | 0.22     | mid   |
| Solyaris Chtonium      | wc   | 380  | 9     | opportunistic | 253       | 0.22     | mixed |
| Pandemic Legion        | wc   | 324  | 6     | heavy_brawl   | 372       | 0.33     | mid   |
| Goonswarm Federation   | cfc  | 538  | —     | defensive     | —         | —        | —     |
| Test Alliance Please…  | wc   | 497  | 7     | heavy_brawl   | 320       | 0.29     | mid   |

(`defensive` for alliances with no composition rollup is
expected: classifier favours defensive when escalation_rate=0
and disengage_rate=0. Will refine once April composition data
accrues.)

## §4.6B run (window_end=2026-04-26, window_days=90)

```
{"blocs_written": 5}
```

| bloc      | alliances | incidents | avg_fleet | cap_rate | doc_div | strat_dens | mob   |
|-----------|-----------|-----------|-----------|----------|---------|------------|-------|
| WinterCo  | 23        | 5445      | 314.93    | 0.055    | 0.122   | 0.105      | medium |
| Imperium  | 2         | 974       | 237.46    | 0.066    | 0.160   | 0.067      | medium |
| Initiative| 1         | 459       | 228.15    | 0.062    | 0.373   | 0.100      | medium |
| PanFam    | 2         | 330       | 590.00    | 0.023    | 0.110   | 0.081      | medium |
| B2        | 2         | 185       | 558.52    | 0.061    | 0.221   | 0.128      | medium |

PanFam has largest avg fleet size (590) — fewer alliances,
heavier per-engagement. WinterCo dominates incident count via
breadth (23 alliances).

Known caveat: `operational_footprint_systems = 390` for every
bloc — current SQL counts global distinct systems, not
per-bloc-attributed. Tracked as follow-up.

## §4.6C run (window_end=2026-03-15, window_days=14, prior=2026-03-01)

```
{"events_written": 83}
```

| event_type        | n  |
|-------------------|----|
| abandonment       | 41 |
| adoption          | 40 |
| sudden_increase   | 1  |
| capital_emergence | 1  |

Cross-alliance meta shift surfaced: Maelstrom·DPS abandonment
(prior_share=1.0 → current_share=0.0) paired with Scythe·DPS
adoption — Goonswarm, Fraternity Auxiliary, Pandemic Legion,
Banderlogs Alliance, Siberian Squads, Legion of xXDEATHXx, No
Visual. all moved off Maelstrom in the same fortnight. Reads
as a coordinated logistics-anchor swap (Maelstrom DPS → Scythe
logi cradle) rather than 7 independent doctrine choices.

April windows currently emit 0 events because force-comp data
is March-bound; doctrine_distribution_json is empty in both
recent profile rows. Will flow through naturally as April dscan
snapshots accrue.

## §4.6D run (operational_corridors classifier)

```
{"corridors_classified": 3144}
```

| route_classification | n    |
|----------------------|------|
| unclassified         | 2011 |
| transit              | 649  |
| deployment_migration | 474  |
| reinforcement        | 8    |
| staging              | 2    |

Top deployment_migration corridor cluster sits on
1VK-6B / R-RSZZ / UL-4ZW / 7G-H7D — the WinterCo home-region
shuttle ring. Span ≥ 14 days, repeat traffic ≥ 3, distinct
characters ≥ 2 → classified migration. Staging and
reinforcement are sparse on the current corridor data; expected
to grow as more recent dscan + escalation data accrues.

## §4.6E run (operator fingerprints, window=90d)

```
{"operators_written": 2615}
```

| primary_style          | n    |
|------------------------|------|
| undetermined           | 1487 |
| corridor_camper        | 571  |
| generalist             | 552  |
| fast_responder         | 3    |
| rapid_escalator        | 2    |

Top by cluster_appearances:

- Bigmomy — 91 clusters / 87 incidents → generalist (high
  confidence)
- charly5 — 78 / 71 → corridor_camper (style 0.34, high)
- WatchDog1 — 38 / 32 → corridor_camper (style 0.65, high)

No identity claims. Style scores describe operational behavior
patterns (re-appearance frequency same-system, logistics
attendance, escalation co-presence, fast-mob composition
co-presence).

## Idempotency

All five passes use `ON DUPLICATE KEY UPDATE` on stable keys:

- 4.6A: `(viewer_bloc_id, alliance_id, window_end, window_days)`
- 4.6B: `(viewer_bloc_id, bloc_id, window_end, window_days)`
- 4.6C: `(viewer_bloc_id, alliance_id, event_type, doctrine_id, window_end)`
- 4.6D: corridor `id` UPDATE
- 4.6E: `(viewer_bloc_id, character_id, window_end, window_days)`

Re-runs on the same args produce identical row counts.

## Known caveats

1. Coalition footprint metric is global, not per-bloc-attributed.
2. April force-composition coverage is sparse → April-window
   alliance profiles are mostly composition-empty, which biases
   the operational_style classifier toward `defensive`.
3. "Defensive" landing as the modal style is partly an
   artifact: when no escalation/disengage signals fire, the
   classifier scores defensive=0.3 (passes 0.25 threshold).
   Worth a calibration pass once April compositions land.
4. Operator fingerprint heuristics (camp, bait, response_tempo)
   are first-pass — designed for triage, not autonomous flagging.
5. No predictive AI. No autonomous recommendations. No "spy
   probability." Outputs are historical/operational
   characterizations only.
