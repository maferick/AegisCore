# V1 Freeze — operational watch mode

**Effective:** 2026-04-27 (reframed same day for single-operator
reality — see ADR 0012).
**Author:** v1 closure pass.
**Lifts when:** v2 entry criteria in
[`docs/adr/0012-single-operator-ai-assist.md`](adr/0012-single-operator-ai-assist.md)
§ "V2 entry criteria — single-operator revision" are observed
live for the documented window.

This document defines what is allowed and what is forbidden
during v1 freeze. Burn-down for the open gates is in
[`V1_COMPLETION_CHECKLIST.md`](V1_COMPLETION_CHECKLIST.md). The
allowed-AI scope was expanded 2026-04-27 by ADR 0012 to include
safe AI assistance (summarization, ranking, dedup, narrative
generation, "what changed?" synthesis, investigation
suggestions, confidence estimation, alert prioritization,
incident grouping). Operator attribution + punitive automation
remain forbidden.

---

## What "freeze" means

V1 is now in **operational watch mode**. The platform is
finished as a v1 capability set. Work is restricted to:

- making the existing capabilities trustworthy,
- making them survivable under real load,
- making them observable enough that humans can act,
- making them governed enough that calibration is auditable.

V1 freeze is **not a hiring freeze, a code freeze, or a
deployment freeze**. It is a **scope freeze**. New
intelligence capabilities are off-limits.

---

## Allowed during freeze

The following are explicitly OK to ship without lifting freeze:

1. **Bug fixes** on shipped capabilities.
2. **Operational hardening** — retry, circuit, freshness,
   audit, retention, observability follow-ups.
3. **Documentation** — RUNBOOK additions, ADRs, post-mortems,
   verification docs.
4. **Performance** — index audits, query plan fixes,
   materialisation refinements that do not change analyst
   output.
5. **Governance refinements** — calibration cycles per ADR 0011,
   threshold tuning with paper trail in
   `calibration_proposals`.
6. **UX polish** on existing surfaces — mobile / keyboard
   audits, accessibility, navigation.
7. **Operator-side scripts** — cron lines, host tooling,
   restore drills.
8. **Data cleanup** — retention sweep refinements, partition
   plans (proposed only — no destructive runs without an ADR
   and dual sign-off).
9. **Test coverage** on shipped pipelines.
10. **Incident response** — anything in
    [`docs/RUNBOOK.md`](RUNBOOK.md).
11. **Safe AI assistance** per ADR 0012 — summarization,
    anomaly ranking, dedup, narrative generation tied to
    traceable rows, cluster / doctrine-evolution explanation,
    operational change detection, "what changed?" synthesis,
    investigation suggestions (queries only), confidence
    estimation, alert prioritization within an existing queue,
    incident grouping suggestions. Operator stays in the loop;
    AI proposes, operator commits. Source citation required;
    audit trail required (`intel_audit_log` actor_kind='ai').

---

## Forbidden during freeze

The following are blocked until v2 entry approved:

1. **New intelligence surfaces outside the safe-AI scope above.**
   No new `/portal/intelligence/...` pages that infer about
   operators-as-humans or take action without operator
   confirmation.
2. **Predictive accusations** — AI claims that X *will* betray,
   X is hostile, X is an alt. Predictive *patterns* about
   operations (escalation likelihood, corridor pressure,
   doctrine trends, fleet-size projections) stay deferred to v2.
3. **Autonomous recommendations with operational action
   attached** — "kick this pilot", "deny fleet invite", "auto-
   suspend on these signals". Recommendation alone is fine
   (see safe-AI scope); attached action is not.
4. **Autonomous mutation of analyst-visible state** — auto-tune
   of thresholds, auto-suppress of alerts on machine-derived
   signals (manual operator suppression is fine), auto-rotate
   of severity classifications without analyst acknowledgement.
5. **Stylometry / typed-text similarity / writing-style
   inference** — ADR 0010 deferred to v2 and gated on a separate
   privacy/ABAC ADR + dual operator review even at v2 entry.
6. **Operator attribution** — AI claims about who runs what
   character, identity inference, alt detection.
7. **Punitive automation** — suspension, access removal,
   watchlist auto-add, or any action against an operator
   without explicit operator confirmation.
8. **Aggressive behavioral profiling** — operator-behavior
   clustering, communication-pattern intelligence beyond what
   the safe-AI scope already covers.
9. **Schema-changing migrations** that add net-new functional
   tables outside what the safe-AI scope requires. Schema
   change for hardening (audit, retry, freshness) is fine.
10. **Adopting new external dependencies** — new ESI scopes, new
    third-party APIs, new SDKs — without a freeze-lift exception
    logged in `calibration_proposals` (kind=`dependency_addition`)
    and dual sign-off.
11. **Any change that puts humans in the calibration loop as the
    calibrated entity.** Per ADR 0011 Rule 6, the platform never
    auto-suppresses analysts, never down-weights their feedback,
    never attributes "low-quality reporter" labels. Do not weaken
    this constraint without explicit ADR amendment.

---

## Watch-mode targets

Every observation below has a documented gate. Freeze lifts when
the gates are met (the gates are the v2 entry criteria).

| target                                                  | source                                  | gate                                                   |
|---------------------------------------------------------|-----------------------------------------|--------------------------------------------------------|
| Platform-health stable for sustained period             | `/portal/intelligence/platform-health`  | 14 days no critical events that turned out false       |
| Retention/partitioning operational                      | `docs/RETENTION.md` + audit             | retention cron firing daily for 7 days; first sweep <60s |
| Ingest stable under real uploader load                  | platform-health pulse strip + run log   | 5+ uploaders × 3+ TZ × 7 days uninterrupted ingest     |
| Freshness + compute observability trusted               | trust dashboard                         | trust scores diverge from 0.50 baseline on 1+ surface |
| Alert governance exercised with real analyst feedback   | `intel_feedback_events`                 | ≥ 200 events, ≥ 5 surfaces, ≥ 1 surface < 0.50 trust   |
| Materialization/index audits complete                   | `verification/storage/db_storage_audit.md` | Stage-2 EXPLAIN+drop completed (24 GB reclaim)      |
| No critical backlog/starvation issues                   | `compute_lane_metrics`                  | 7 days no Neo4j thread starvation or lane=starved      |
| Sufficient telemetry corpus                             | dscan / doctrine / uploader counts      | 60 days dscan, 200+ matched compositions               |

---

## Daily operator routine during freeze

The cron lines installed 2026-04-27 cover routine observability:

- 04:15 UTC — retention sweep
- 06h cycle — lane metrics, quality guards, freshness
- 08:30 UTC — SDE auto-update
- 5min cycle — battle pipeline (flock-guarded)
- 6h cycle — backups

Every morning the operator on duty reviews:

1. `/portal/intelligence/platform-health` — any open quality
   events? Any open circuits? Any starved lanes?
2. `/portal/intelligence/alerts` — any urgent / elevated open
   for > 4 hours? Triage per RUNBOOK.
3. `/portal/intelligence/trust` — feedback corpus growth?
4. `backups/mariadb/` — last successful full-size backup < 24h?

If anything is red, work the RUNBOOK recipe. If nothing in the
RUNBOOK applies, append a new recipe BEFORE moving on.

---

## Calibration cycles during freeze

ADR 0011 governs calibration. Quarterly cadence applies. Each
cycle:

1. snapshot `verification/calibration/baseline_<date>.json`
   (schema in `verification/calibration/SCHEMA.md`)
2. analyse vs prior baseline
3. propose changes via `calibration_proposals` rows
4. review (two operators)
5. adopt — code change + commit
6. re-snapshot

No off-cycle calibration except per ADR 0011 "major data shifts"
clause. New uploader cohort, new EVE patch, 2× ingest volume
shift — these qualify. Single-incident triage does **not** qualify.

---

## Exit criteria — when freeze lifts

V1 freeze ends when ALL of the following are observed live for
their documented window. Reframed 2026-04-27 by ADR 0012 to
match single-operator reality.

1. **Platform-health stable** — 14 consecutive days with zero
   open critical quality events that survive 24h after detection
   (i.e. zero confirmed-real critical events).
2. **Retention proven** — 7 consecutive daily retention sweeps,
   each completing < 60 s, no errors, no rows past TTL on
   spot-check.
3. **Operator usage** — operator has worked the platform across
   ≥ 5 distinct operational events with notes captured (replaces
   the original uploader-diversity gate; single-operator can't
   manufacture 5 uploaders).
4. **Operator feedback** — `intel_feedback_events` ≥ 50 spanning
   ≥ 3 surfaces (relaxed from 200/5 — single-operator volume
   can't reach the original gate within reasonable time).
5. **Storage hardening Stage 2 done** — three redundant indexes
   dropped (24 GB reclaim).
6. **Storage Stage 3 ADR signed off** — `docs/ADR-market-orders-partitioning.md`
   reviewed, two operator sign-offs in
   `verified_intelligence_items`, dry-run completed in test
   schema.
7. **No outstanding critical RUNBOOK items.**
8. **Safe-AI surface validated** — at least one safe-AI surface
   per ADR 0012 demonstrates measurable operator-time savings
   on a documented analyst workflow. This is the new gate that
   replaces the third-party calibration corpus the original
   v1 plan assumed.

When all 8 are observed, the operator drafting the v2 entry
note opens `docs/adr/0013-v1-freeze-exit.md` capturing the
evidence and proposing v2 work order. Predictive / behavioral
work does not start until that ADR is reviewed.

---

## What this document is not

This document is **not** the burn-down. The burn-down is
[`V1_COMPLETION_CHECKLIST.md`](V1_COMPLETION_CHECKLIST.md).
That file lists every gate by section. This file is the
**posture**: what we do and don't ship while watching the
gates close.

If a checklist item is closed but a posture rule conflicts,
the posture rule wins. Posture is the safer default.
