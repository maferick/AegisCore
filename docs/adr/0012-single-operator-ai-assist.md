# ADR 0012 — Single-operator AI assist policy

**Status:** accepted (2026-04-27).

**Supersedes (in v1 scope):** ADR 0010 (phase 6 stylometry) is deferred
to v2 entry. This ADR overrides any v1 wiring of operator-attribution
or behavioral-inference surfaces.

## Context

ADR 0011 + `docs/V1_FREEZE.md` defined v1 as trust infrastructure
with a closure gate that assumed:

- ≥5 active uploaders across ≥3 timezones,
- ≥200 analyst feedback events spanning ≥5 surfaces,
- a calibration loop driven by real analyst-team usage.

Reality on 2026-04-27:

- one operator,
- no alliance rollout,
- no broad uploader deployment,
- no analyst team to generate feedback corpus.

Several gates are unreachable in single-operator mode without
help. Two failure modes we want to avoid:

1. Freeze the platform on its current shape forever because
   the gates assume conditions that don't exist yet.
2. Lift the freeze prematurely and let predictive / punitive
   features ship before they've proven operational value.

Resolution: redefine v1 as *single-operator force-multiplier
infrastructure*. AI assistance that reduces operator workload
on already-trusted data is allowed. AI inference that claims
things about humans, or takes action without the operator in
the loop, stays frozen.

## Decision

### Allowed in v1 — "safe AI assistance"

Surfaces that summarize, rank, explain, dedupe, or synthesize
*already-trusted* operational data. The operator remains the
decision-maker; AI is a force multiplier, not a judge.

| Capability | Why it's safe |
|---|---|
| Summarization of digests / incidents / narratives | Compresses; doesn't infer about people. |
| Anomaly ranking | Reorders an already-visible queue. No autonomous action. |
| Deduplication of overlapping signals | Combines rows the operator can already see. |
| Narrative generation tied to traceable source rows | Renderer must cite the rows the narrative came from. |
| Cluster / doctrine-evolution explanation | Explains existing classifications; doesn't add new ones. |
| Operational change detection between windows | Diffs windows the operator chose. |
| "What changed?" synthesis | Same as above — bounded comparison. |
| Investigation suggestions | Suggests *queries*, never *actions*. Operator runs the query. |
| Confidence estimation against observed truth | Produces a number; doesn't gate behavior. |
| Alert prioritization within an analyst's queue | Reorders; doesn't dismiss. |
| Incident grouping suggestions | Suggests merges; operator clicks to merge. |

### Forbidden in v1 — deferred to v2

Surfaces that infer about operators-as-humans, or that take
action without explicit operator confirmation.

- Stylometry / typed-text similarity / writing-style inference
  (ADR 0010 deferred — even in v2 this requires a separate
  privacy/ABAC ADR + dual operator review before re-activation;
  it is dual-use, not just "v2-eligible").
- Operator attribution (AI claims about who runs what character).
- Punitive automation: suspension, access removal, watchlist
  auto-add, or any action against an operator.
- Autonomous escalation: severity raise without human
  acknowledgement.
- Predictive accusations: AI saying X *will* betray, X is
  hostile, X is an alt.
- Aggressive behavioral profiling.
- Autonomous recommendations with operational action attached
  (e.g., "kick this pilot", "deny fleet invite"). Recommendation
  is fine; attached action is not.

### The test — "is this AI work allowed during freeze?"

A capability is allowed in v1 if and only if **all four** are true:

1. It does not claim something about an operator's identity,
   intent, or trustworthiness as a person.
2. It cannot take action without explicit human confirmation.
3. It is explainable from the rows it cites.
4. It summarizes / ranks / explains / synthesizes already-
   trusted data, rather than producing new inference about
   humans.

Default to forbidden when unsure. Ask the operator to authorize
explicit exceptions; record the exception in this ADR's history.

## Hard constraints (apply to every safe-AI surface)

1. **Source citation required.** Every AI-generated narrative,
   summary, or ranking must link back to the rows it derives
   from. No unsupported claims.
2. **No autonomous mutation of analyst-visible state.** AI may
   propose; the operator commits. The Livewire / Filament
   handler is the action boundary.
3. **No operator-as-subject.** Outputs describe operational
   events, doctrines, systems, fleets. They do not describe
   people as people.
4. **Confidence rendering.** Every AI surface includes a
   confidence band (low / medium / high) plus a one-line
   caveat. No naked claims.
5. **Audit trail.** AI-generated artifacts that influence
   analyst decisions go into `intel_audit_log` with
   `actor_kind='ai'` so review can reconstruct what the AI
   said vs what the operator decided.

## V2 entry criteria — single-operator revision

Replaces the v1 closure criteria in `docs/V1_FREEZE.md`. The
freeze does not lift until **all eight** are observed:

1. 14d zero confirmed-real critical events on platform-health.
2. 7d retention sweeps clean < 60s each.
3. Operator has used the platform across ≥5 distinct
   operational events with notes captured (replaces the
   uploader-diversity gate).
4. ≥50 feedback events / ≥3 surfaces (relaxed from 200/5 —
   single-operator volume; still has to demonstrate the
   calibration loop is exercised).
5. Storage Stage-2 indexes dropped (24 GB reclaim).
6. Storage Stage-3 ADR signed off (market_orders partitioning).
7. No outstanding critical RUNBOOK items.
8. ≥1 safe-AI surface demonstrates measurable operator-time
   savings on a documented analyst workflow.

When all eight met: draft `docs/adr/0013-v1-freeze-exit.md`
with evidence, propose v2 work order. Predictive / behavioral
work does not start until that ADR is reviewed.

## Open questions

- Which safe-AI surface ships first? Suggest "what changed?"
  synthesis as the cheapest win — pure window diff over
  existing surfaces; no new schema; immediate operator value.
- Where does the AI runtime live? `intel_copilot` container
  exists per ADR 0007 — extend it rather than introducing a
  new runtime.
- Confidence calibration corpus: same eight gate questions
  apply (operator must use the system across operational
  events first; AI cannot self-calibrate).

## History

- 2026-04-27: ADR drafted in response to single-operator reality
  reframe. Supersedes ADR 0010 (Phase 6 stylometry) for v1 scope.
