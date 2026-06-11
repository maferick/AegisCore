# ADR-0014 — Advanced subtle-spy correlation signals

**Status:** accepted (scoping commit)
**Date:** 2026-05-01
**Author:** operator + Claude (caveman session)
**Supersedes / amends:** extends ADR-0013 hypothesis-confidence framing

## Context

Existing Phase 1 / Phase 2 signals (asymmetric_pair, community_mismatch, hostile_triangulation, dormancy_reactivation, battle_only_score, cheap_loss, pod_survival, corp_hopping) catch overt patterns. They miss the **subtle-spy / handler-pattern operator** — a character who:

- avoids individually-suspicious behaviour
- blends as a normal line member
- shows up *only* when it matters
- is statistically correlated with hostile operational activity over time

The 2026-05-01 Bakkanta one investigation confirmed the gap: handler-pattern fires in raw anomaly data (75% asymmetric outbound, 100% hostile community) but was gated below sample-size floors. Phase A relaxed those floors but only addresses the simplest two signals.

The deeper gap is **multi-domain correlation across signals**. A spy avoids being individually suspicious but cannot avoid being statistically correlated with the side they help. Detection must shift from "who looks weird" to "who behaves like a signal in the system".

## Decision

Build a 7-signal correlation layer feeding `counter_intel_hypotheses`. Each signal is materialised in its own table, computed by a Python pass, and contributes evidence rows (with confidence + corroboration metadata) to the hypothesis loop.

ADR-0013 binding rules apply unchanged: every signal renders confidence + evidence + source refs + caveats + freshness + why-strengthened. No autonomous action. AI proposes; operator commits.

## Signal catalogue

| # | name | computation | new table |
|---|---|---|---|
| 1 | opposite-side correlation | per-(friendly, hostile) cid pair, count shared theaters / battles, compute friendly→hostile + hostile→friendly ratios + asymmetry score | `ci_opposite_side_correlations` |
| 2 | hostile triangulation cluster | already exists in `ci_hostile_triangulation`; add cluster-cohesion score (Neo4j) | extend `ci_hostile_triangulation` |
| 3 | event-triggered activity | character activity (killmail proxy) within ±30min of `operational_incidents` of severity ≥ strategic | `ci_event_triggered_activity` |
| 4 | participation selectivity | per-character fight-size distribution (large ≥100, medium 10–99, small 2–9, solo 1) from `battle_theaters.participant_count`; large_fleet_ratio | `ci_participation_selectivity` |
| 5 | contribution anomaly | killmail damage_done / battle median; zero-damage ratio; role-aware via `ci_character_features_rolling.dominant_role` | `ci_cohort_behavior_deviation` (combined) |
| 6 | reaction-timing correlation | friendly char activity → `operational_incidents` of severity ≥ tactical within ±30min in same/adjacent system (via `ref_stargates`) | `ci_reaction_timing_correlations` |
| 7 | cohort behavior deviation | z-score per character vs declared-alliance × activity-band × dominant-role cohort; combine fleet_size_z, activity_time_z, doctrine_z (when available) | `ci_cohort_behavior_deviation` |

## Schema adaptations to existing reality

User design references several tables that don't exist in AegisCore. Mapping:

| design ref | reality | impact |
|---|---|---|
| `battle_participants` | `battle_theater_participants` | per-theater not per-battle; coarser grain |
| `character_standings.viewer_bloc_id/resolved_alignment` | `coalition_entity_labels[alliance_id].bloc_id` joined via char→corp→alliance | recompute per-character at signal time |
| `eve_log_events` (login events) | none | use killmail timestamps as activity proxy |
| `killmail_participants_materialized` | `killmail_attackers JOIN killmails` | inline join |
| `character_activity_materialized` | derive from `killmail_attackers` + `killmail_victims` | inline |
| `ci_hypothesis_evidence` | new table created in this ADR | see "Hypothesis integration" |
| `battles` (per-battle event) | `battle_theaters` | theater = aggregated battle; for fine grain compute per-killmail co-occurrence |
| `character_features_rolling.declared_alliance_id/large_fleet_ratio/doctrine_match_rate/timezone_centroid` | partial: `dominant_role`, `avg_gang_size`, `tz_centroid_sin/cos` exist; `large_fleet_ratio` + `doctrine_match_rate` need extension | extend feature pass in Phase B-2 |

## Hypothesis loop integration

`counter_intel_hypotheses` already exists with `evidence_summary_json`, `source_signal_refs_json`, `corroboration_count`, `suspicion_score`, `confidence`. New evidence table `ci_hypothesis_evidence` joins individual signal fires to the hypothesis row, enabling per-domain corroboration counting and decay.

Schema (this ADR):

```sql
ci_hypothesis_evidence(
  id, hypothesis_id, signal_table, signal_row_id,
  signal_type, scoring_contribution, confidence_at_observation,
  domain ENUM('graph','operational','timing','behavioural','cohort','presence','correlation'),
  observed_at, decay_factor, evidence_payload_json,
  created_at, updated_at,
  KEY (hypothesis_id), KEY (signal_table, signal_row_id)
)
```

`hypothesis_type` enum extended with new values:
- `opposite_side_correlation`
- `asymmetric_handler_pattern`
- `event_triggered_activity_pattern`
- `participation_selectivity_pattern`
- `contribution_anomaly_pattern`
- `reaction_timing_correlation_pattern`
- `cohort_behavior_deviation_pattern`

(existing values preserved.)

## Scoring contribution (Phase B-6 will adopt; this ADR records intent)

Per signal base ranges:

| signal | base score | scaling factor |
|---|---|---|
| opposite_side_correlation | 10–30 | shared_battles × asymmetry_score |
| asymmetric_handler_pattern | 20–40 | asymmetry_score |
| hostile_triangulation_cluster | 20–45 | cluster cohesion |
| event_triggered_activity | 10–30 | inverse(outside_activity_count) |
| participation_selectivity | 10–25 | large_fleet_ratio above 0.85 |
| contribution_anomaly | 5–20 | role-aware; never strong alone |
| reaction_timing_correlation | 20–50 | recurrence × proximity |
| cohort_behavior_deviation | 5–20 | combined z-score; supporting only |

Corroboration multipliers:

| pair | multiplier |
|---|---|
| graph + operational | 1.3 |
| graph + timing | 1.4 |
| timing + opposite-side | 1.5 |
| triangulation + reaction-timing | 1.6 |
| ≥3 independent domains | 1.8 |

Decay:

| age | factor |
|---|---|
| ≤30d | 1.0 |
| 30–60d | 0.75 |
| 60–90d | 0.50 |
| >90d | 0.0 (informational only) |

Repeated fresh fires reset the most-recent observation timestamp; do not stack-multiply.

ADR-0011 paper trail required for any threshold tuning post-launch.

## Calibration governance

Each signal launches with a `calibration_proposals` row (status=`adopted`, baseline_ref=`adr-0014-phase-b-N`). Threshold changes after launch require a new proposal row with prior_value/proposed_value + evidence_json.

## V1 freeze compliance

ADR-0013 reframed forbidden topics into forbidden-actions. Correlation discovery, hypothesis synthesis, suspicious-network surfacing are explicitly allowed (V1_FREEZE.md line 66-82) with the six binding UI/UX fields. This ADR satisfies all six on every new render.

No autonomous action attached to any new signal. Operator-commit only.

## Phasing

| commit | scope |
|---|---|
| **B-0 (this commit)** | this ADR + migration for all 5 new tables + evidence table + hypothesis_type enum extension + Python skeleton + make target |
| B-1 | signal 1 opposite-side correlation full impl + cron entry + verification |
| B-2 | signal 4 participation selectivity (cheapest after #1) + extend features with `large_fleet_ratio` |
| B-3 | signal 5 contribution anomaly |
| B-4 | signal 3 event-triggered + signal 6 reaction-timing (share activity proxy) |
| B-5 | signal 7 cohort deviation + extend features with required cohort fields |
| B-6 | hypothesis loop integration (evidence promotion + corroboration multipliers + decay) |
| B-7 | UI surface on CI Command page |

Each phase ships with verification artifacts (`verification/adr-0014/phase-b-N/`).

## Out of scope

- New ESI scopes (login events) — would unlock real activity timestamps; deferred until ESI scope review
- Stylometry-as-signal — already in ADR-0010, separate workstream
- Autonomous escalation — forbidden per ADR-0013, never in scope

## Open questions

- Reaction-timing correlation needs activity proxy. Killmails miss "watcher" alts who don't fight. Acceptable for v1; revisit if alt-detection gap material.
- Cohort baselines need ≥30 cohort members to be reliable. Single-operator bloc may not have enough internal characters per (alliance × activity-band × role) cell. Phase B-5 will fall back to global baseline when cohort sparse.
