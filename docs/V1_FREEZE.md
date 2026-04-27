# V1 Freeze — operational watch mode

**Effective:** 2026-04-27.
**Author:** v1 closure pass.
**Lifts when:** v2 entry criteria in
[`memory/project_v1_v2_split.md`](../../.claude/projects/-opt-AegisCore/memory/project_v1_v2_split.md)
are observed live for the documented window.

This document defines what is allowed and what is forbidden
during v1 freeze. Burn-down for the open gates is in
[`V1_COMPLETION_CHECKLIST.md`](V1_COMPLETION_CHECKLIST.md).

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

---

## Forbidden during freeze

The following are blocked until v2 entry approved:

1. **New intelligence surfaces** of any kind. No new
   `/portal/intelligence/...` pages. No new operator surfaces.
2. **Predictive features** — escalation likelihood, corridor
   pressure forecasting, doctrine trend prediction, fleet-size
   projections.
3. **Recommendation engines** — FC advisories, route advisories,
   "you should engage / disengage" prompts.
4. **Autonomous action** — auto-tune of thresholds, auto-suppress
   of alerts on machine-derived signals (manual operator
   suppression is fine), auto-rotate of severity classifications
   without analyst review.
5. **Phase 6 stylometry compute** — see ADR 0010, deferred.
6. **Advanced operator-behavior clustering / communication
   pattern intelligence.**
7. **New trust-weight formulas** without the 200/5/below-baseline
   eligibility per ADR 0011 Rule 5.
8. **Schema-changing migrations** that add net-new functional
   tables. Schema change for hardening (audit, retry, freshness)
   is fine. Schema change to add a new analyst surface is not.
9. **Adopting new external dependencies** — new ESI scopes, new
   third-party APIs, new SDKs — without a freeze-lift exception
   logged in `calibration_proposals` (kind=`dependency_addition`)
   and dual sign-off.
10. **Any change that puts humans in the calibration loop as the
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
their documented window:

1. **Platform-health stable** — 14 consecutive days with zero
   open critical quality events that survive 24h after detection
   (i.e. zero confirmed-real critical events).
2. **Retention proven** — 7 consecutive daily retention sweeps,
   each completing < 60 s, no errors, no rows past TTL on
   spot-check.
3. **Telemetry corpus** — uploader diversity (≥ 5 active, ≥ 3
   timezones, ≥ 7 days), dscan coverage (≥ 60 days), doctrine
   coverage (≥ 200 matched compositions across ≥ 5 alliances).
4. **Analyst feedback** — `intel_feedback_events` ≥ 200 spanning
   ≥ 5 surfaces, ≥ 1 surface trust_score below 0.50.
5. **Storage hardening Stage 2 done** — three redundant indexes
   dropped (24 GB reclaim).
6. **Storage Stage 3 ADR signed off** — `docs/ADR-market-orders-partitioning.md`
   reviewed, two operator sign-offs in
   `verified_intelligence_items`, dry-run completed in test
   schema.
7. **No outstanding critical RUNBOOK items.**

When all 7 are observed, the operator drafting the v2 entry
note opens a new ADR (`0012-v1-freeze-exit.md`) capturing the
evidence and proposing v2.1 work. No v2 work starts until that
ADR is reviewed.

---

## What this document is not

This document is **not** the burn-down. The burn-down is
[`V1_COMPLETION_CHECKLIST.md`](V1_COMPLETION_CHECKLIST.md).
That file lists every gate by section. This file is the
**posture**: what we do and don't ship while watching the
gates close.

If a checklist item is closed but a posture rule conflicts,
the posture rule wins. Posture is the safer default.
