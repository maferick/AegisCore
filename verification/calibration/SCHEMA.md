# Calibration baseline corpus schema

Every quarterly cycle produces one `baseline_<YYYY_MM_DD>.json`
in this directory. This document is the schema. ADR 0011
governs the process; this file governs the data structure.

## Top-level shape

```json
{
  "captured_at": "ISO 8601 UTC",
  "snapshot_version": "v1.0 | v1.1 | ...",
  "purpose": "human-readable reason for the snapshot",
  "blocs": [ { ... } ],
  "feedback_corpus_at_baseline": { ... },
  "thresholds_at_baseline": { ... },
  "analyst_feedback_corpus_eligibility": { ... }
}
```

Field rules:

- **`captured_at`** — wall-clock UTC at capture moment. NEVER
  back-dated.
- **`snapshot_version`** — increment on schema change. Bump
  patch on field additions; bump minor on rename / removal.
- **`purpose`** — quick reason. "v1 freeze baseline" /
  "post-uploader-rollout drift check" / "post-meta-shift" /
  "Q2 2026 quarterly".

## Per-bloc structure

```json
{
  "viewer_bloc_id": <int>,
  "incidents": {
    "30d_total": <int>,
    "daily_mean": <float>,
    "daily_min": <int>,
    "daily_max": <int>,
    "p95_daily": <int>,
    "p98_daily": <int>,
    "severity_distribution_30d": {
      "noise": <int>,
      "tactical": <int>,
      "strategic": <int>,
      "escalation": <int>,
      "coalition_level": <int>
    }
  },
  "clusters": {
    "30d_total": <int>,
    "daily_mean": <float>,
    "p95_daily": <int>
  },
  "force_compositions": {
    "total_observed": <int>,
    "doctrine_match_rate": <float>,
    "matched": <int>,
    "unmatched": <int>
  },
  "corridors": {
    "total": <int>,
    "new_per_7d": <int>,
    "prior_7d": <int>,
    "route_classification": {
      "unclassified": <int>,
      "transit": <int>,
      "deployment_migration": <int>,
      "reinforcement": <int>,
      "staging": <int>,
      "escalation_path": <int>
    }
  },
  "alerts_30d": {
    "watch": <int>,
    "elevated": <int>,
    "urgent": <int>,
    "info": <int>
  },
  "trust_scores_at_baseline": {
    "alert": <float>,
    "digest": <float>,
    "narrative": <float>,
    "incident": <float>,
    "corridor": <float>,
    "alliance_profile": <float>,
    "threat_surface": <float>
  }
}
```

## Feedback corpus structure

```json
"feedback_corpus_at_baseline": {
  "total_events": <int>,
  "by_surface": {
    "alert":           <int>,
    "digest":          <int>,
    "narrative":       <int>,
    "incident":        <int>,
    "corridor":        <int>,
    "alliance_profile":<int>,
    "threat_surface":  <int>
  },
  "by_kind": {
    "useful":              <int>,
    "strategic":           <int>,
    "misleading":          <int>,
    "noisy":               <int>,
    "duplicate":           <int>,
    "incorrect_escalation":<int>,
    "incorrect_doctrine":  <int>,
    "incorrect_linkage":   <int>
  },
  "note": "string"
}
```

`note` field captures qualitative observations: "single test
event from 2026-04-27 wiring smoke-test"; "Q2 corpus —
multi-bloc rollout"; etc.

## Thresholds at baseline

```json
"thresholds_at_baseline": {
  "<detector_name>": {
    "<param_name>": <value>,
    ...
    "tuned_against": "human-readable reason"
  },
  ...
}
```

Detector → param mapping:

| detector                        | params                                                    |
|---------------------------------|-----------------------------------------------------------|
| incident_explosion              | ratio_warning, ratio_elevated, ratio_critical, abs_floor  |
| corridor_explosion              | ratio_threshold, abs_floor                                |
| doctrine_mismatch_explosion     | rate_warning, rate_elevated, rate_critical                |
| current_parser_drift            | rate_warning, rate_elevated, rate_critical, min_events_floor |
| unknown_event_spike             | rate_warning, rate_elevated, rate_critical                |
| impossible_fleet_size           | ship_total_threshold                                      |
| duplicate_narrative_loop        | identical_count_warning, _elevated, _critical             |
| stale_compute_chain             | hours_warning, _elevated, _critical                       |
| neo4j_thread_pressure           | warning_pct, critical_pct, bolt_max                       |
| circuit_open                    | failure_threshold, window_seconds, initial_cooldown_s, max_cooldown_s |

## Eligibility section

```json
"analyst_feedback_corpus_eligibility": {
  "min_events_for_recalibration": 200,
  "min_distinct_surfaces": 5,
  "current_event_count": <int>,
  "current_distinct_surfaces": <int>,
  "recalibration_eligible": <bool>
}
```

`recalibration_eligible=true` only when both minimum criteria
are met AND the post-baseline analyst review per Rule 5 of
ADR 0011 confirms the corpus is high-quality (no spam, no
single-source domination). The boolean is operator-set; not
auto-derived.

## Capture procedure

The baseline data points come from canonical SQL queries
documented at the top of `verification/storage/db_storage_audit.md`.
Every capture cycle re-runs the same queries — the schema
above must not introduce a field that the canonical queries
can't fill, or vice versa. Schema and queries are versioned
together via `snapshot_version`.

When in doubt, the **schema** is normative — the queries
update to match the schema, never the other way around.

## Diffing snapshots

Future cycles compare the new baseline to the previous one.
A `verification/calibration/diff_<old>_to_<new>.md` file
captures:

- per-metric drift (absolute + %)
- per-detector fire-rate drift
- feedback corpus growth
- recalibration eligibility status change

Drift thresholds:

- **green:** < 25 % change on any metric. Quarterly cycle is
  noting-only.
- **yellow:** 25 % – 75 % change on any single metric, or
  feedback corpus crosses an eligibility threshold. Quarterly
  cycle produces a proposal.
- **red:** > 75 % change OR detector fire-rate doubles. Calls
  for an off-cycle calibration cycle.

Diffing logic is human-driven for v1; no automation.
