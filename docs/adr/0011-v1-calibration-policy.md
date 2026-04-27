# ADR 0011 — v1 calibration policy

**Status:** accepted (2026-04-27).

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

Without a calibration discipline, those values drift away from
operational meaning as the platform scales and meta shifts.
**Trust scores stay anchored to 0.50 baseline** until analyst
feedback flows in. **Severity tiers misclassify** if not
retuned. **Detectors false-fire or miss** without periodic
revalidation.

This ADR defines how calibration runs in v1.

## Decision

### What gets calibrated

1. **Detector severity thresholds** in
   `python/counter_intel/phase49e_quality_guards.py`:
   - `incident_explosion` (ratio gates + abs floor)
   - `corridor_explosion`
   - `doctrine_mismatch_explosion`
   - `current_parser_drift`
   - `unknown_event_spike`
   - `impossible_fleet_size`
   - `duplicate_narrative_loop`
   - `stale_compute_chain`
   - `neo4j_thread_pressure`
   - `circuit_open`
2. **Operational style classifier** in
   `phase4_coalition._classify_operational_style`. Currently
   biases toward `defensive` when feedback signals are sparse;
   needs recalibration once April composition data thickens.
3. **Trust score weights** in
   `phase4_governance.run_trust_metrics` — currently
   `0.6×useful_rate + 0.3×(1−fp_rate) + 0.1×(1−suppression_rate)`.
   Baseline is 0.50 with no feedback; once 200+ events exist,
   verify the score actually deviates and recalibrate weights
   if surface-tier separation is poor.
4. **Confidence cutoffs** on incident severity classification
   (`_classify_severity`), digest section confidence
   (`_section_confidence`), and narrative confidence in
   `phase4_governance.run_enrich_narrative_sources`.
5. **Freshness TTL ladder** in `intel_ttl.json`. Currently
   one-size-fits-bloc-1; recalibrate per-surface if FC
   feedback says freshness pills mislead during ops.

### How calibration runs

**Step 1 — capture.** Record a baseline snapshot in
`verification/calibration/baseline_<date>.json`. Captures:
- per-bloc daily incident / cluster / alert counts (mean, p95, max)
- doctrine match rate distribution
- corridor classification distribution
- severity distribution per bloc
- alert generation rate by kind / severity
- analyst feedback corpus size (per-surface, per-kind)
- trust score values at snapshot time
- threshold values currently active

**Step 2 — analyse.** Per-detector, compute the empirical
percentile of the metric the detector watches. Threshold
proposals come from:
- p95 of metric over 30 days → warning
- p98 of metric over 30 days → elevated
- p99.5 of metric over 30 days → critical
- abs floor: 80% of empirical mean

**Step 3 — propose.** Diff the proposed thresholds against
current values. Capture in
`verification/calibration/proposal_<date>.md`. Include:
- before / after values
- expected detector fire-rate change
- list of historical events that would have flipped (over /
  under the new threshold)

**Step 4 — adopt.** Patch the detector code + push a calibration
commit. Re-snapshot the baseline immediately after.

### When calibration runs

- **One-off:** any time an analyst reports a false-fire or a
  miss on a quality_event. Walks through Step 1-4 above.
- **Quarterly:** every 90 days, run a full calibration sweep
  even if no analyst complaints. Drift accumulates silently.
- **Major data shifts:** after a new bloc onboards, after a
  big EVE patch (CCP balance pass), after the platform crosses
  a 2× change in ingest volume. Recalibrate before the next
  detector sweep.

### Eligibility for trust-weight recalibration

Tighter than detector recalibration. Requires:

- **≥ 200 analyst feedback events** captured across **≥ 5
  distinct surfaces** (alert / digest / narrative / incident /
  corridor / alliance_profile / threat_surface).
- **At least one surface trust_score below 0.50** — proves the
  scoring is doing real work and not just baseline-stuck.
- **Manual review** of the per-surface useful_rate /
  false_positive_rate / suppression_rate distribution before
  weight changes.

Until that point: trust scores stay at the
`0.6/0.3/0.1` v1 default.

### Authority + audit

- **Recalibration commits** must include a
  `verification/calibration/<date>_<reason>.md` file explaining:
  - what changed
  - why
  - which baseline / proposal informed the change
  - what monitoring follows the change
- **No autonomous calibration.** All threshold changes are
  human-decided, code-reviewed, code-committed.
- **All analyst feedback** flows through
  `intel_feedback_events`, captured at write-time. Recalibration
  reads from this corpus exclusively — not from operator memory.

### v1 baseline snapshot

`verification/calibration/baseline_2026_04_27.json` is the
v1-freeze reference. Captures bloc-1 metrics + current
thresholds + feedback corpus state (1 event — see "future
calibrations need real corpus" caveat). Future recalibrations
diff against this snapshot.

## Consequences

**Positive:**
- Threshold drift becomes detectable, not silent.
- Trust scores eventually become meaningful (post-corpus).
- Analyst feedback feeds back into platform behavior in a
  principled way.
- Recalibration commits are reviewable and reversible.

**Negative:**
- Quarterly calibration is operator overhead. v1 accepts this
  cost; v2 may automate.
- The `_classify_operational_style` "defensive bias" persists
  until April composition data thickens. Documented; not fixed
  in v1.
- Trust score weights stay at 0.6/0.3/0.1 default until corpus
  fills. Documented; not fixed in v1.

## Compliance

This ADR is compatible with:
- ADR 0009 (Phase 4 operational event intelligence)
- ADR 0010 (Phase 6 stylometry — deferred to v2)
- `memory/project_v1_v2_split.md` (v1 = trust infrastructure;
  predictive ML stays out)

V1 closure §14 gate: this ADR + the 2026-04-27 baseline file
satisfy the policy requirement. The actual quarterly
recalibration cadence kicks in 90 days post-freeze.
