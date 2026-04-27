# ADR 0013 — Hypothesis-confidence framing (amends ADR 0012)

**Status:** accepted (2026-04-27).

**Amends:** ADR 0012 (single-operator AI assist). The previous
"never infer about operators" line was too absolute for the
actual project goal. This ADR replaces the topic-based forbidden
list with a guarantee-based ladder.

**Does not change:** ADR 0011 Rule 6 (humans never the calibrated
entity in calibration governance). That rule is about *tuning
analysts*, not *investigating operators*; the two are different
concerns and 0011 stays intact.

## Context

AegisCore is, by design, a counter-intelligence platform. Long-
term goals include identifying likely hostile infiltrators,
coordinated hostile operators, compromised insiders, and
operationally suspicious entities. Earlier policy (ADR 0012)
read as if those goals were forbidden in v1. They are not. What
must be controlled is *how* the platform forms claims about
operators, not *whether*.

The original concern stands: AI must not autonomously punish,
escalate, or take irreversible action. That is unchanged. The
framing shifts from "topics the platform may not address" to
"guarantees every inference must satisfy".

## Decision

### Guiding principle

> Allow the system to form increasingly strong hypotheses.

Inference about operators, coordination, and possible same-
operator activity is permitted. Inference is hypothesis-shaped:
it has a confidence, evidence, caveats, and a path for the
operator to challenge it. It does not have an action attached
unless the operator attaches one.

### What the system MAY do

- correlate signals across surfaces
- build hypotheses about coordination, infiltration, same-
  operator activity, compromised insiders
- accumulate or shed confidence as new signals arrive
- cluster suspicious operational behavior
- infer possible coordination from co-occurrence patterns
- infer possible same-operator activity from stylometric or
  behavioral overlap
- surface anomalous operational patterns
- rank investigative priority
- generate investigative suggestions
- strengthen or weaken suspicion over time as evidence
  accumulates

### What the system MAY NOT do

- autonomously punish operators (suspend, kick, deny invite,
  remove access)
- autonomously escalate to leadership without operator review
- present hypotheses as fact (every high-suspicion output ships
  with confidence + evidence + caveats; the UI never claims
  "X is a spy")
- hide its reasoning (every inference must surface confidence,
  evidence, source refs, caveats, freshness, and why the
  hypothesis strengthened — black-box scoring is forbidden)
- create irreversible autonomous actions

### Confidence model — the hypothesis ladder

Signals accumulate over time. The platform reasons in
*confidence bands*, not binary verdicts.

| Signal state | Confidence | Surface treatment |
|---|---|---|
| Single weak signal | low | informational only; no investigative priority bump |
| 2+ corroborating signals | medium | elevated investigative priority; analyst review suggested |
| Longitudinal consistency over weeks | high | strong clustering; operator-assisted validation suggested |
| Operator-validated cluster | confirmed | persists as a verified intelligence item per ADR 0011, with the operator as the named validator |

Promotion across bands requires either (a) more signals, (b)
longer time consistency, or (c) operator confirmation. The
platform never promotes itself across the highest band — that
takes a human.

Demotion is symmetric: contradicting signals or analyst override
shed confidence. The audit trail records both directions.

### Evolution path

```
pattern detection
  → hypothesis generation
    → confidence accumulation
      → operator-assisted validation
        → verified intelligence item
```

No stage skips. The platform never goes from "pattern detected"
to "operator validated" without the intermediate accumulation.

### Language

The previous "never accuse / never identify / never infer
identity" framing is replaced. New canonical language:

- "hypothesis"
- "confidence" (low / medium / high / confirmed)
- "supporting evidence"
- "possible coordination"
- "possible same-operator pattern"
- "requires corroboration"
- "operational suspicion"
- "investigative priority"

Avoid:
- "X is a spy" — present as "high-confidence operational suspicion: X"
- "alt of Y" — present as "possible same-operator pattern with Y, medium confidence"
- "guilty" / "confirmed bad actor" — bands are confidence, not verdict

### UI/UX rule (binding)

Every high-suspicion output must expose, in the rendered UI:

1. confidence band
2. evidence list
3. source references (table + IDs + link)
4. caveats (sample size, freshness, contamination notes)
5. freshness state
6. *why* the hypothesis strengthened (the delta from prior render)

The operator must be able to inspect and challenge the system's
reasoning. If a surface cannot satisfy all six, it does not
ship.

### Reversibility rule (binding)

Any AI-influenced action must be reversible by the operator
within one click for at least 24 hours after the action. After
that window, reversal still works but may require a confirm
step. No AI surface ships an action that cannot be undone.

### Allowed safe-AI surfaces (revised, expanded)

Replaces the v1 list in ADR 0012:

- summarization, dedup, narrative generation (unchanged from 0012)
- "what changed?" synthesis (unchanged; §17.1 first ship)
- anomaly ranking
- correlation discovery
- cluster explanation
- hypothesis synthesis (NEW — was ambiguous in 0012)
- investigative suggestions
- temporal pattern analysis
- operational behavior clustering (NEW — was forbidden in 0012)
- confidence estimation
- change detection
- suspicious-network surfacing (NEW — was forbidden in 0012)
- stylometry as a *weak signal* contributing to hypothesis
  confidence (NEW — was outright forbidden in 0012; now allowed
  under the hypothesis ladder + UI/UX rule + reversibility rule)
- operator attribution as a *hypothesis*, never as a verdict
  (NEW — same caveats)

### Still forbidden in v1 (no new caveat lifts these)

- autonomous punitive action against operators
- irreversible escalation
- hidden black-box scoring (must surface confidence + evidence)
- autonomous access decisions

## Roadmap effect

Future AI/intelligence work is no longer blocked because it
contributes toward identifying likely spies/operators. Each
proposal is evaluated on six dimensions:

1. confidence quality (does it surface a real band, not naked claim?)
2. explainability (can the operator see why?)
3. reversibility (can the operator undo within 24h?)
4. governance (audit trail, ADR review, dual sign-off where needed?)
5. operational risk (what's the worst false-positive cost?)
6. automation level (proposes vs. acts)

Stylometry / operator attribution are now allowed-with-guarantees
in v1. They still need:

- privacy/ABAC review for raw-message handling
- analyst-validation gate before any cluster crosses the
  "high confidence" threshold
- dual operator review for any cluster proposed for verified-
  intelligence-item promotion
- audit log entries with actor_kind='ai' on every inference

## Operational impact

- ADR 0012's "forbidden" list is replaced by the guarantee-
  based test above. Re-read 0012 with this ADR layered on.
- ADR 0010 (Phase 6 stylometry) is no longer "deferred"; it
  is "permitted as a weak-signal contributor under the
  hypothesis ladder". A separate spec ADR should follow that
  defines the specific feature shape, not the policy.
- V1_FREEZE.md must be amended to point at this ADR instead
  of 0012's absolute language; the freeze itself (no
  autonomous action, no irreversible escalation) is intact.
- V1_COMPLETION_CHECKLIST §17 widens: surfaces that infer
  about operators with hypothesis framing are now in scope
  for v1, not blocked.

## History

- 2026-04-27: ADR drafted in response to user clarification
  that counter-intel inference is a project goal, not an
  excluded topic. Amends ADR 0012's framing without lifting
  the autonomous-action prohibition.
