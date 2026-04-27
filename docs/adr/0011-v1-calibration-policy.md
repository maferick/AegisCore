# ADR 0011 — v1 calibration governance

**Status:** accepted (2026-04-27, refined 2026-04-27).

## Context

Phases 4.7–4.9 shipped quality-event detectors, alert lifecycle
governance, trust metrics, freshness ladders, and retry/circuit
infrastructure. Most threshold values were:

1. picked from intuition (e.g. `parser_drift > 5%`),
2. tuned against the first observed real-data baseline
   (e.g. `incident_explosion` retuned 2026-04-27 against bloc-1
   178/d mean), or
3. set against documented system limits (e.g.
   `neo4j_thread_pressure` 80% of 16 Bolt slots).

V1 closure is **operational survivability**, not aggressive
tuning. This ADR defines calibration **governance**: who can
change what, when, with what evidence, with what audit trail.
The guiding principle: **a wrong threshold is recoverable;
analyst trust loss from a bad auto-tune is not.**

## Decision

Calibration is a **governed, manual, auditable** process. There
is no autonomous threshold tuning in v1. Six rules below are
binding.

### Rule 1 — what gets calibrated

Six surfaces are calibration-eligible:

1. **Detector severity thresholds** in
   `python/counter_intel/phase49e_quality_guards.py` —
   `incident_explosion`, `corridor_explosion`,
   `doctrine_mismatch_explosion`, `current_parser_drift`,
   `unknown_event_spike`, `impossible_fleet_size`,
   `duplicate_narrative_loop`, `stale_compute_chain`,
   `neo4j_thread_pressure`, `circuit_open`.
2. **Operational style classifier** in
   `phase4_coalition._classify_operational_style`.
3. **Trust score weights** in
   `phase4_governance.run_trust_metrics`.
4. **Confidence cutoffs** on incident severity,
   digest section confidence, narrative confidence.
5. **Freshness TTL ladder** in
   `app/config/intel_ttl.json` ⇄ `python/counter_intel/intel_ttl.json`.
6. **Suppression ratios** in
   `phase4_governance.run_alert_suppression`.

Any other threshold that materially affects analyst-visible
output may be added to this list with a calibration commit; not
without one.

### Rule 2 — baseline corpus structure

Every calibration cycle starts and ends with a baseline
snapshot. Schema in `verification/calibration/SCHEMA.md`. Each
snapshot lives at
`verification/calibration/baseline_<YYYY_MM_DD>.json` and
captures:

- per-bloc daily metric distributions (incidents / clusters /
  alerts / new corridors / force compositions)
- detector fire-rate distribution per surface per severity
- analyst feedback corpus size + breakdown
- trust score values
- threshold values active at snapshot time

The baseline is **frozen at commit time**. Future recalibrations
diff against the prior snapshot, not against current state.

### Rule 3 — promotion / demotion rules for detector signals

Detectors emit `info / warning / elevated / critical` events.
Promotion (raising severity) and demotion (lowering severity)
follow strict rules.

**Promotion rules** — make signal louder:
- A detector may only promote if **≥ 5 distinct analyst
  feedback events** of kind `useful` or `strategic` on the
  surface within the prior 30 days, AND the metric watched by
  the detector is consistently in the upper percentile of the
  baseline distribution.
- Promotion is **never** a one-shot threshold drop.
  Multi-step: warning → elevated requires 2 prior cycles of
  warning fires that analysts marked `useful`.

**Demotion rules** — make signal quieter:
- A detector may demote (raise threshold) when:
  - **≥ 10 distinct analyst feedback events** of kind `noisy`
    or `duplicate` on the surface within the prior 30 days, OR
  - **≥ 30 % suppression rate** on the alerts emitted by the
    detector, OR
  - **a single false-critical event reproducibly explained**
    that pinpoints the threshold as wrong.
- Demotion takes effect on the next calibration commit, not
  retroactively.

**Symmetry guardrail:** promotion and demotion go through the
same review process. There is no fast-path for either.

### Rule 4 — quarterly calibration process

Every 90 days, on a schedule visible in
`verification/calibration/CALENDAR.md`, the following sequence
runs:

1. **Capture (day 0)** — snapshot the platform state into
   `baseline_<date>.json`. Use the existing capture queries in
   `verification/storage/db_storage_audit.md` plus the
   thresholds-active table from `phase49e_quality_guards`.
2. **Analyse (day 0–7)** — compute per-detector empirical
   percentiles. Identify candidates for promotion / demotion
   per Rule 3.
3. **Propose (day 7–14)** — draft
   `verification/calibration/proposal_<date>.md`. Include:
   before/after values, evidence count per Rule 3, expected
   fire-rate change.
4. **Review (day 14–21)** — two operators sign off on the
   proposal. Sign-off is a checked box in the proposal file
   plus a separate operator pin via
   `verified_intelligence_items` (kind=`analyst_note`).
5. **Adopt (day 21–30)** — patch the detector code or config.
   Commit message links the proposal + baseline + sign-offs.
6. **Re-snapshot (day 30)** — new
   `baseline_<date>.json` post-adoption.

### Rule 5 — feedback-to-threshold workflow

Analyst feedback flows through `intel_feedback_events` (Phase
4.8D). The feedback-to-threshold path is:

```
intel_feedback_events ── (90d, ≥10 noisy on surface) ──▶ demotion candidate
intel_feedback_events ── (90d, ≥5 useful + 30d follow-on) ──▶ promotion candidate
```

The path is **never closed automatically**. Each candidate
becomes a row in `calibration_proposals` (Rule 6) and only
graduates via the quarterly cycle (Rule 4).

**Trust score weights** are gated tighter than detector
thresholds:

- **≥ 200 feedback events** captured across **≥ 5 surfaces**
- **≥ 1 surface trust_score below 0.50** (proves the formula
  separates surfaces in production, not just at baseline)
- **manual review** of useful_rate / fp_rate / suppression_rate
  per surface

Until those three preconditions all hold, trust weights stay
at the v1 default `0.6×useful + 0.3×(1-fp) + 0.1×(1-suppression)`.

### Rule 6 — guardrail: no automatic punitive action

This rule is the most important. The platform must **never**:

- auto-suppress an analyst's feedback because it diverges from
  the consensus,
- auto-downgrade an analyst's verified intelligence item,
- auto-archive an alert because too many analysts marked it
  noisy (operator-driven manual archive is fine),
- auto-recalibrate any threshold based on feedback alone,
- attribute a "low-quality reporter" rating to any analyst.

Calibration adjusts the platform's own voice. It does not
adjust the platform's response to any human. **People are
never the variable being tuned.**

The technical embodiment of this rule:

- `intel_feedback_events` is append-only.
- `verified_intelligence_items.delete()` requires the
  creator's user_id OR an admin user_id (audit-logged).
- No code path reduces an analyst's effective trust based on
  their feedback content. Reliability scoring exists (Phase
  4.3 `intel_reliability_profiles`) and is a separate signal
  on raw intel reports — it does **not** feed the calibration
  loop.
- Any future code change that touches the analyst-facing
  feedback / verified-item / audit pipelines must be reviewed
  for compliance with this rule. Reviewers reject changes that
  put humans in the calibration loop as the calibrated entity.

## Storage / audit support

`compute_run_log` already records every calibration recompute
that runs through `ComputeLog`. Calibration proposals are
captured in a new lightweight table `calibration_proposals`
(see migration `2026_04_27_080000_create_calibration_proposals.php`):

```
calibration_proposals
  id                BIGINT
  proposal_date     DATE
  surface           VARCHAR(60)        -- name of detector / classifier
  field             VARCHAR(120)       -- specific threshold being moved
  prior_value       VARCHAR(120)       -- before
  proposed_value    VARCHAR(120)       -- after
  evidence_json     TEXT               -- feedback counts, percentiles
  status            ENUM('proposed','reviewed','adopted','rejected','superseded')
  reviewer_user_ids TEXT               -- JSON array of user IDs
  baseline_ref      VARCHAR(120)       -- baseline_<date>.json filename
  rationale         TEXT
  decided_at        DATETIME NULL
  created_at        DATETIME
```

The table is the immutable record of every calibration
decision. Drops are append-only (status='superseded' on
replacement, never DELETE).

## V1 baseline

`verification/calibration/baseline_2026_04_27.json` is the
v1-freeze reference. Future quarterly cycles diff against it.

## Consequences

**Positive:**
- Calibration drift is detectable, not silent.
- Analyst trust is structurally protected — Rule 6 is
  load-bearing.
- Every threshold change has a paper trail.

**Negative:**
- Quarterly cadence is operator overhead.
- v1 cannot react to a sudden platform-meta shift faster than
  the 30-day cycle. Acceptable: operational survivability is
  v1's goal, not operational reactiveness.

**Compliance:** consistent with ADR 0009 (Phase 4), ADR 0010
(stylometry deferred), `memory/project_v1_v2_split.md`
(predictive ML stays out of v1).
