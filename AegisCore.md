# SupplyCore Platform Rebuild Master Plan

**Version:** 1.0  
**Date:** March 26, 2026  
**Document Owner:** Platform Engineering  
**Audience:** Engineering, Data Engineering, SRE, Product, Security, QA  
**Status:** Draft for Execution

---

## 1) Executive Intent

This plan describes how to **rebuild SupplyCore from first principles** while preserving business continuity and enforcing the platform’s non-negotiable architecture:

- **PHP Control Plane** for UI, configuration, metadata, and orchestration intent.
- **Python Execution Plane** for all compute, ingestion, analytics, and graph processing.

The rebuild is designed to be:

1. **Correct by construction** (schema and contracts first).
2. **Modular and evolvable** (clear ownership boundaries and interfaces).
3. **Operationally safe** (batching, retries, observability, and rollback discipline).
4. **Parity-driven** (worker/scheduler/manual execution produce identical outcomes).

This is not a redesign proposal in abstract; this is an **execution-ready program** with concrete streams, checkpoints, dependencies, acceptance gates, risk controls, and detailed deliverables.

---

## 2) Core Principles (Hard Constraints)

### 2.1 Runtime Separation

- PHP must never execute background compute workloads.
- Python is the only execution runtime for jobs.
- PHP stores and presents **job metadata only**.
- There must be no duplicated business logic between PHP and Python.

### 2.2 Registry Authority

- `supplycore_authoritative_job_registry()` is the only source of truth for user-manageable jobs.
- No runtime inference from table scans, scheduler state, or filesystem scanning.
- Job keys are immutable once released.

### 2.3 Schema Authority

- `database/schema.sql` is the canonical schema definition.
- No code may assume fields absent from schema.
- Every schema change must pass migration + compatibility validation before runtime adoption.

### 2.4 Execution Discipline

- Every compute job is batch-based and resumable.
- Every job supports retry safety and checkpoint continuity.
- No unbounded full-table processing.

### 2.5 Adapter Purity

- External APIs are accessed only through adapter modules.
- Compute jobs consume normalized adapter outputs.

### 2.6 Observability Completeness

Every job execution emits structured logs containing:

- `job_key`
- batch size
- rows processed
- duration
- outcome
- error details (if failure)

---

## 3) Program Outcomes

By the end of the rebuild:

1. A fresh, documented baseline architecture exists with explicit runtime contracts.
2. All jobs in the authoritative registry execute through Python processors only.
3. All major ingestion and analytics pipelines run incrementally in bounded batches.
4. Scheduler dispatch, worker pool execution, and manual CLI runs are behaviorally identical.
5. Data integrity and graph workloads are measurable, recoverable, and audited.
6. Operational playbooks exist for incidents, backfills, and schema evolution.

---

## 3.1 Product Vision: What the Website Must Do (Control Plane Responsibilities)

The website is the **operator cockpit** for the data platform. It must make the execution engine understandable, configurable, and safe to operate without embedding compute logic in PHP.

### Core Website Responsibilities

1. **Operational Visibility**
   - Show current platform health at a glance (pipeline freshness, job outcomes, lag, and incidents).
   - Present status by data domain (market ingestion, entity enrichment, graph updates, analytics scores).
   - Provide clear “what changed recently” context (last successful run, last failure, rows processed trends).

2. **Configuration and Metadata Management**
   - Manage feature settings grouped by module (ingestion, graph, scoring, alerts, retention).
   - Manage job definitions as metadata only (enable/disable, schedule metadata, batch limits, retry policy classes).
   - Enforce safe defaults and explicit validation on all settings inputs.

3. **Execution Intent and Controls (No Compute)**
   - Allow authorized users to trigger dispatch actions (manual run requests) that route to Python processors.
   - Show dry-run and planned run impacts where possible.
   - Surface dispatch outcomes and links to logs for troubleshooting.

4. **Governance and Auditability**
   - Track who changed settings, when, and why.
   - Track who initiated manual runs or backfills and with which parameters.
   - Preserve immutable audit trails for compliance and incident postmortems.

5. **Operator Experience**
   - Keep the UI clean, minimal, and predictable.
   - Make every control explain runtime behavior and risk.
   - Reduce operational ambiguity through strong labeling, guardrails, and contextual help.

### What the Website Must Never Do

- Execute business compute logic.
- Call external APIs (ESI/EveWho/zKill) directly for pipeline processing.
- Circumvent the authoritative job registry.
- Hide scheduler/processor parity differences.

---

## 3.2 Product Inputs and Data Contracts (Detailed)

The platform consumes four primary classes of inputs. Each class requires explicit contracts, normalization, and quality checks.

### A) External Inputs (Adapters Required)

1. **ESI (EVE Swagger Interface)**
   - Purpose: authoritative in-game entity/state data (characters, corporations, alliances, universe references, selected market-support metadata).
   - Adapter responsibilities:
     - OAuth/token handling where applicable.
     - endpoint-specific rate limits.
     - retry policy for transient 4xx/5xx and network failures.
     - response schema normalization and version compatibility handling.
   - Reliability notes:
     - cache immutable/reference responses where safe.
     - separate high-frequency from low-frequency endpoints.

2. **EveWho**
   - Purpose: supplemental entity intelligence and historical relationship context.
   - Adapter responsibilities:
     - backoff and graceful degradation during source instability.
     - strict parsing and schema normalization.
     - provenance markers to distinguish EveWho-sourced attributes.
   - Reliability notes:
     - reconcile conflicts with ESI using deterministic precedence policy.

3. **zKill (zKillboard feed/API)**
   - Purpose: killmail/event stream for activity analytics, risk scoring, and graph updates.
   - Adapter responsibilities:
     - incremental pull strategy (cursor/time-window based).
     - duplicate detection for replayed/out-of-order events.
     - robust handling of partial payloads and delayed updates.
   - Reliability notes:
     - maintain ingestion lag metrics and backlog alarms.

### B) Internal Inputs

- Existing relational tables defined in `database/schema.sql`.
- Prior checkpoints/watermarks for resumable jobs.
- Job registry metadata and scheduling metadata.
- Operator-configured feature settings and thresholds.

### C) User Inputs (Control Plane UI)

- Module toggles (enable/disable pipelines/features).
- Scheduling metadata (cron-like cadence, run windows, blackout windows).
- Batch controls (size limits, max duration caps where exposed).
- Manual run requests (job key, optional bounded scope/window, reason/comment).
- Alert thresholds and notification routing settings.

### D) System Inputs

- Runtime environment configuration (secrets, endpoints, queue names).
- Worker capacity signals (concurrency slots, queue depth).
- Observability infrastructure signals (error rate, latency, lag trends).

### Input Validation Rules

- Every input must have: type constraints, allowed ranges, defaults, and failure behavior.
- High-risk controls require confirmation steps and audit annotation.
- Invalid configuration must fail fast at save-time (not at runtime).
- Backfill/manual scope inputs must be bounded by date/id ceilings.

---

## 3.3 Website Information Architecture and Layout Blueprint

The website should use a **modular, section-based layout** that mirrors system architecture and operator workflows.

### Global Layout

1. **Top Bar**
   - environment badge (prod/staging/dev), current user, quick incident state.
   - global search for jobs, settings, entities.

2. **Left Navigation (from `nav_items()` only)**
   - Dashboard
   - Jobs
   - Pipelines
   - Data Sources (ESI, EveWho, zKill)
   - Graph
   - Analytics
   - Settings
   - Logs / Audits
   - Runbooks / Help

3. **Main Workspace**
   - card-driven, minimal visual hierarchy.
   - sticky action row for context-specific actions.
   - tabbed subsections for dense pages.

4. **Right Context Panel (optional but recommended)**
   - live status widgets.
   - glossary/help text for current page.
   - quick links to relevant runbooks.

### Page-by-Page Layout Requirements

#### Dashboard (Operations Overview)
- KPI cards:
  - pipeline freshness by domain.
  - success/failure counts (24h/7d).
  - queue lag/backlog.
  - graph update freshness.
- timeline panel of notable events (deploys, incidents, schema migrations).
- “attention needed” queue with actionable items.

#### Jobs Page
- registry-driven table only (job key, owner, tier, execution mode/language, processor, status).
- row expansion for schedule metadata, batch config, checkpoint mode, retry profile.
- actions: request run, disable/enable (permission-gated), view parity evidence.

#### Pipelines Page
- domain-centric view: ingest → normalize → persist → process → publish.
- each stage shows health, throughput, lag, and last checkpoint.
- clear bottleneck visualization.

#### Data Sources Page (ESI / EveWho / zKill)
- adapter health cards (latency, error rate, retries, throttle events).
- endpoint/feed-level status and quotas.
- normalization/contract version visibility.
- source-specific incident banners and fallback guidance.

#### Graph Page
- incremental graph job status and checkpoint position.
- transaction/memory guardrail indicators.
- partial rebuild controls with explicit blast-radius warnings.

#### Analytics Page
- scoring and aggregation job freshness.
- data quality indicators (null rates, drift flags, anomaly markers).
- downstream artifact verification status.

#### Settings Page (Modular)
- grouped sections:
  - ingestion settings
  - adapter settings (ESI/EveWho/zKill)
  - job/scheduling metadata defaults
  - graph controls
  - retention and archival
  - alerting/notifications
- every setting includes description, expected runtime impact, and validation hints.

#### Logs / Audits Page
- structured log explorer (filters by job key, outcome, run id, date window).
- audit events for config and dispatch actions.
- links from failures directly to runbook snippets.

#### Runbooks / Help Page
- operational playbooks with searchable tags.
- failure signature lookup.
- escalation matrix and ownership map.

### UX/Interaction Principles

- Make safe actions easy and dangerous actions explicit.
- Prefer progressive disclosure over crowded pages.
- Every chart/table must answer an operator question.
- Empty/error states must provide concrete next steps.

---

## 3.4 Data Source Strategy: ESI + EveWho + zKill (Why Each Exists)

### ESI (Primary Authority)

**Why:** ESI is closest to official source-of-truth for many entity/state dimensions. It should anchor canonical identity mappings and baseline metadata.  
**Role in system:** identity resolution, reference enrichment, canonical fields for cross-source reconciliation.

### EveWho (Context Enrichment)

**Why:** EveWho provides useful contextual and historical layers not always represented identically through other channels.  
**Role in system:** supplemental enrichment and relationship context, especially for analytical interpretations.

### zKill (Behavioral Event Stream)

**Why:** zKill provides high-signal behavioral telemetry needed for activity analytics, risk models, and graph evolution.  
**Role in system:** event-driven updates, temporal activity scoring, and incremental graph edge evolution.

### Cross-Source Reconciliation Principles

- Define field-level precedence rules (canonical, supplemental, derived).
- Preserve provenance for each attribute.
- Resolve conflicts deterministically and log reconciliation outcomes.
- Track staleness per source to prevent old data overriding fresh authoritative values.

---

## 3.5 General Design Reasoning and Decision Logic

This rebuild intentionally follows a **control-plane / execution-plane split** because:

1. **Operational Safety:** UI mistakes should not execute compute directly.
2. **Scalability:** Python worker pools can scale independently of web traffic.
3. **Reliability:** batch/checkpoint semantics are easier to enforce in a dedicated execution runtime.
4. **Maintainability:** metadata/control concerns and compute concerns evolve at different speeds.
5. **Auditability:** clear dispatch boundaries improve traceability and incident analysis.

Key general thoughts behind the architecture:

- **Correctness first:** data platform trust is hard to rebuild once broken.
- **Determinism over convenience:** parity and reproducibility beat ad-hoc shortcuts.
- **Bounded work units:** batching is mandatory for memory, retries, and recoverability.
- **Explicit contracts:** hidden assumptions become outages at scale.
- **Observable systems win:** if failures are not visible and explainable, they are not truly handled.

## 4) Scope Definition

### 4.1 In Scope

- PHP control-plane hardening (metadata, settings, UX alignment, nav centralization).
- Python job framework standardization (processor registration, contracts, lifecycle hooks).
- Adapter layer normalization (zkill, ESI, EveWho, and future connectors).
- Batch/checkpoint model across ingestion and processing jobs.
- Neo4j incremental graph strategy and memory-safe query patterns.
- Structured logging + operational dashboards + alerting thresholds.
- Database schema reconciliation and migration pipeline.
- Runtime parity and regression test matrix.
- Documentation, runbooks, and release protocol.

### 4.2 Out of Scope (unless separately approved)

- Major product feature expansion unrelated to architecture parity.
- New runtime languages for compute.
- Full historical backfill beyond predefined windows.
- UI redesign not required for settings modularity or architecture clarity.

---

## 5) Delivery Model and Workstreams

The rebuild is split into synchronized workstreams with explicit handoffs.

### WS-0: Program Governance & Baseline Inventory

**Objective:** Establish certainty before changes.

**Deliverables:**
- System inventory spreadsheet (jobs, tables, processors, adapters, schedules).
- Current-vs-target architecture map.
- Risk register with ownership.
- Migration and rollback policy.

**Activities:**
- Catalog all active jobs and map to authoritative registry.
- Identify orphaned job definitions and shadow execution paths.
- Enumerate all SQL writes and verify schema column alignment.
- Produce dependency graph between jobs, tables, and adapters.

**Exit Criteria:**
- 100% job inventory mapped.
- 100% write-path SQL coverage documented.
- Top 20 technical risks assigned with mitigations.

---

### WS-1: Contract-First Platform Foundation

**Objective:** Lock interfaces before implementation migration.

**Deliverables:**
- Job execution contract spec.
- Processor registration spec.
- Checkpoint schema and semantics.
- Structured log schema and event taxonomy.

**Activities:**
- Define required metadata for every job (execution mode/language, processor id, batch config).
- Define canonical checkpoint types (ID cursor, timestamp watermark, composite cursor).
- Standardize error classes: transient, permanent, data-quality, contract violation.
- Define retry policy matrix by error class.

**Exit Criteria:**
- Contract linting tool passes against all registered jobs.
- No ambiguous job metadata fields remain.

---

### WS-2: Database Reconciliation and Migration Safety

**Objective:** Bring runtime behavior into strict schema alignment.

**Deliverables:**
- Updated `database/schema.sql` (if required).
- Migration scripts with forward/backward notes.
- Schema compatibility test suite.
- Query conformance report.

**Activities:**
- Compare live usage to canonical schema and close drift.
- Refactor SQL statements to strict column alignment.
- Validate indexes supporting batch cursor scans.
- Add migration guardrails (preflight checks, post-verify checks).

**Exit Criteria:**
- Zero “unknown column” risk on critical paths.
- All write operations validated against schema in CI.

---

### WS-3: Python Execution Framework Hardening

**Objective:** Standardize all compute paths through Python worker contracts.

**Deliverables:**
- Processor base interfaces and lifecycle template.
- Worker pool registration map.
- Unified manual CLI dispatch path sharing processor code.
- Scheduler dispatch bridge to Python processors (metadata only in PHP).

**Activities:**
- Remove/disable any PHP compute fallback path.
- Enforce processor registration presence for every compute job.
- Add parity harness to compare worker vs scheduler vs CLI outcomes.
- Implement idempotency keys/checks where required.

**Exit Criteria:**
- 100% compute jobs execute via Python processors only.
- Parity test matrix green for all Tier-1/Tier-2 jobs.

---

### WS-4: Adapter Layer Consolidation

**Objective:** Encapsulate external API interaction for reliability and reuse.

**Deliverables:**
- Hardened adapters with retries, backoff, rate controls.
- Normalized payload schemas and versioning policy.
- Adapter test fixtures (success, throttle, partial failure).

**Activities:**
- Move direct API calls out of jobs into adapter modules.
- Implement request budgeting and shared retry utilities.
- Normalize payload contracts and validation.
- Emit adapter-level metrics (latency, retries, failure types).

**Exit Criteria:**
- No direct third-party API calls from job business logic.
- Adapter integration tests pass with deterministic fixtures.

---

### WS-5: Batch Processing and Checkpoint Rollout

**Objective:** Ensure bounded, resumable, retry-safe jobs platform-wide.

**Deliverables:**
- Batch policy standard (sizes, max duration, memory guardrails).
- Checkpoint persistence implementation.
- Resume semantics specification and test suite.

**Activities:**
- Convert monolithic jobs to chunked execution loops.
- Implement checkpoint writes after successful batch boundaries.
- Add dead-letter/error queue for permanent failures where relevant.
- Validate re-run behavior under duplicate delivery.

**Exit Criteria:**
- Every compute job defines explicit batch size and checkpoint mode.
- Mid-run failure resume tested for critical jobs.

---

### WS-6: Neo4j Incremental Graph Architecture

**Objective:** Make graph workloads incremental, memory-aware, and operationally safe.

**Deliverables:**
- Graph job refactor plan and query library.
- Incremental graph update protocol.
- Partial rebuild runbook.

**Activities:**
- Replace deprecated/inefficient query patterns.
- Add bounded transaction batch controls.
- Introduce fan-out limits and traversal guards.
- Add graph consistency probes.

**Exit Criteria:**
- Graph jobs avoid full-traversal per run.
- Peak memory and transaction metrics remain within set SLO thresholds.

---

### WS-7: Control Plane (PHP) Alignment and UX Integrity

**Objective:** Keep PHP strictly focused on metadata/UI while improving operability.

**Deliverables:**
- Settings modules by feature area.
- Registry-driven job management UI.
- Navigation fully centralized via `nav_items()`.

**Activities:**
- Remove any hidden business logic from page controllers.
- Refactor DB access through central DB layer.
- Ensure settings descriptions map to real runtime behavior.
- Add feature enable/disable clarity and dependency warnings.

**Exit Criteria:**
- No heavy logic in `public/` pages.
- Job UI reflects authoritative registry only.

---

### WS-8: Observability, SLOs, and Incident Readiness

**Objective:** Make failure modes visible, diagnosable, and actionable.

**Deliverables:**
- Structured log standard adoption across jobs.
- Metrics dashboards (throughput, lag, failure ratio, retry rate).
- Alert policy and incident triage runbooks.

**Activities:**
- Add consistent logging envelope to all processors.
- Define SLOs by job tier (freshness, success rate, completion latency).
- Implement on-call signal quality improvements (dedupe/correlation).
- Validate incident drills with staged failures.

**Exit Criteria:**
- Each critical pipeline has alerting + runbook link.
- MTTR baseline established and improved during rollout.

---

### WS-9: Validation, Cutover, and Stabilization

**Objective:** Move safely from old state to rebuilt state without regressions.

**Deliverables:**
- Parallel run plan and reconciliation reports.
- Cutover checklist and rollback packet.
- Post-cutover stabilization log.

**Activities:**
- Shadow runs: old vs new outputs compared by deterministic checks.
- Progressive rollout by job tier and data domain.
- Controlled freeze window and release gates.
- Post-cutover audits and backlog triage.

**Exit Criteria:**
- Critical job parity at target thresholds.
- No Sev-1 architectural regressions in stabilization window.

---

## 6) Detailed Phase Timeline (Illustrative)

> Exact dates should be finalized by team capacity planning; below is sequence and expected duration.

### Phase A — Discovery & Contracting (Weeks 1–2)
- WS-0 and WS-1 complete.
- Architecture and execution contracts frozen.

### Phase B — Foundations (Weeks 3–5)
- WS-2 and WS-3 begin with shared checkpoints.
- Initial parity harness operational.

### Phase C — Pipeline Migration (Weeks 6–10)
- WS-4, WS-5, WS-6 in staggered sequence by pipeline criticality.
- High-volume ingestion and graph jobs prioritized.

### Phase D — Control Plane + Observability (Weeks 8–11)
- WS-7 and WS-8 complete while migrations finalize.

### Phase E — Cutover + Stabilization (Weeks 12–14)
- WS-9 execution.
- Hypercare and defect burn-down.

---

## 7) Job Tiering Strategy (for Prioritization)

### Tier 1 (Critical)
- Directly impacts core analytics correctness or UI trust.
- Must receive first parity checks and first-class alerting.

### Tier 2 (Important)
- Operationally valuable but not immediately customer-visible.
- Migrate after Tier 1 frameworks are proven.

### Tier 3 (Ancillary/Internal)
- Helper/support workflows.
- Keep out user-manageable registry views unless intentionally exposed.

**Rule:** Tiering determines rollout order, not quality bar. All tiers must satisfy the same contract rules.

---

## 8) Runtime Parity Framework (Mandatory)

For each job, parity must be verified across:

1. Worker pool execution.
2. Scheduler-dispatched execution.
3. Manual CLI execution.

### 8.1 Parity Assertions

- Same input slice produces same output rows (or equivalent deterministic transformations).
- Same checkpoint progression semantics.
- Same failure classification and retry behavior.
- Same logging envelope fields.

### 8.2 Parity Evidence Package

Each migrated job must produce:

- Input fixture or window definition.
- Output diff report.
- Timing profile.
- Log sample triplet (worker/scheduler/CLI).
- Signoff from job owner.

---

## 9) Data Integrity and Quality Controls

### 9.1 Ingestion Controls

- Required field validation at adapter boundary.
- Schema normalization before persistence.
- Duplicate suppression and deterministic upserts.

### 9.2 Processing Controls

- Batch-level row count reconciliation.
- Null/invalid rate thresholds.
- Anomaly flags for sudden distribution shifts.

### 9.3 Graph Controls

- Node/edge count trend monitoring.
- Degree distribution checks for explosive anomalies.
- Incremental rebuild verification samples.

---

## 10) Security and Compliance Controls

- Secrets managed through environment-safe mechanisms (no hardcoded credentials).
- API tokens scoped minimally per adapter.
- Audit logs for admin-triggered manual runs.
- CSRF protections preserved in all PHP control paths.
- Input validation and output encoding standards enforced.

---

## 11) Testing Strategy (Multi-Layer)

### 11.1 Unit Tests
- Python job logic and adapters.
- PHP metadata helpers and registry integrity checks.

### 11.2 Integration Tests
- DB writes against schema-aligned test database.
- Adapter contract tests with mocked external responses.
- Processor lifecycle tests (start, batch, checkpoint, retry).

### 11.3 End-to-End Tests
- Selected pipeline slices from ingest to surfaced UI artifact.
- Scheduler-to-worker dispatch path.

### 11.4 Non-Functional Tests
- Throughput and backpressure behavior.
- Memory bounds in graph transactions.
- Failure injection and recovery timing.

### 11.5 Release Gate Minimums

A migration cannot cut over unless:

- Contract lints pass.
- Parity checks pass.
- Batch resume tests pass.
- Observability hooks present.
- Rollback steps documented and rehearsed.

---

## 12) Operational Runbooks (Required)

Each critical job gets a runbook including:

1. Purpose and dependencies.
2. Input/output tables and schemas.
3. Batch and checkpoint parameters.
4. Common failure signatures.
5. Retry/rollback instructions.
6. Validation queries and expected ranges.
7. Escalation contacts.

Platform-level runbooks required:

- Global incident triage.
- Schema migration rollback.
- External API outage handling.
- Graph rebuild partial recovery.

---

## 13) Risk Register Template (to populate during WS-0)

Each risk entry must include:

- **Risk ID**
- **Description**
- **Likelihood** (Low/Med/High)
- **Impact** (Low/Med/High)
- **Owner**
- **Mitigation**
- **Detection Signal**
- **Contingency Plan**

Seed risks:

1. Hidden PHP execution path bypasses Python processors.
2. Schema drift causes silent data truncation or write failures.
3. Checkpoint corruption leads to skipped/duplicated batches.
4. API rate limits cause cascading backlog growth.
5. Graph query fan-out triggers memory pressure.
6. Parity harness false negatives from non-deterministic ordering.

---

## 14) RACI Matrix (High-Level)

- **Platform Lead:** Accountable for architecture compliance.
- **Data Engineering Lead:** Responsible for Python pipeline migration.
- **Backend Lead (PHP):** Responsible for control-plane boundaries and UI metadata correctness.
- **SRE/Operations:** Responsible for observability, alerting, incident readiness.
- **QA Lead:** Responsible for parity and regression validation quality.
- **Product Owner:** Consulted for rollout sequencing and impact windows.

---

## 15) Documentation Package Checklist

Before declaring rebuild complete:

- [ ] Architecture overview diagram (runtime boundary explicit).
- [ ] Job registry reference with ownership and tier.
- [ ] Schema and migration guide.
- [ ] Adapter contract docs.
- [ ] Batch/checkpoint standards.
- [ ] Logging and metrics taxonomy.
- [ ] Operational runbooks.
- [ ] Cutover retrospective and lessons learned.

---

## 16) “Definition of Done” (Program-Level)

The rebuild is complete only when all are true:

1. Every compute job is Python-only, batched, checkpointed, and retry-safe.
2. Registry is authoritative and fully aligned with visible job management.
3. Database interactions are schema-accurate with tested migrations.
4. External integrations are adapter-mediated.
5. Neo4j workloads are incremental and memory bounded.
6. Structured observability is universal across jobs.
7. Worker/scheduler/CLI parity is proven for all managed jobs.
8. Documentation and runbooks are complete and reviewed.

---

## 17) Implementation Backlog Skeleton (Starter)

> Use this as initial issue decomposition for execution tracking.

### Epic A — Contract & Inventory
- A1: Build complete job inventory and registry alignment sheet.
- A2: Create execution contract linter.
- A3: Define checkpoint schema and serialization format.

### Epic B — Schema Integrity
- B1: Audit all SQL writes against schema.
- B2: Author migration backlog and apply staging sequence.
- B3: Add schema conformance CI checks.

### Epic C — Execution Engine
- C1: Standardize processor interface and registration.
- C2: Remove PHP fallback execution paths.
- C3: Build parity harness and reporting artifacts.

### Epic D — External Data Adapters
- D1: Refactor direct API calls into adapters.
- D2: Add retry/rate-limit utilities.
- D3: Normalize payload schemas with version tags.

### Epic E — Batch & Checkpoint
- E1: Migrate Tier-1 jobs to batch/checkpoint model.
- E2: Validate resume and duplicate-safety behavior.
- E3: Add batch telemetry dashboards.

### Epic F — Graph Reliability
- F1: Replace deprecated Neo4j query patterns.
- F2: Add incremental graph update checkpoints.
- F3: Add graph consistency health checks.

### Epic G — Control Plane & UX
- G1: Modularize settings sections by feature.
- G2: Ensure `nav_items()` centralization.
- G3: Remove heavy logic from page controllers.

### Epic H — Cutover
- H1: Run shadow comparisons by job tier.
- H2: Execute phased production cutover.
- H3: Complete hypercare and close residual defects.

---

## 18) Communication and Reporting Cadence

- **Daily:** Workstream standups with blockers and risk escalations.
- **Twice Weekly:** Architecture compliance review.
- **Weekly:** Program dashboard (parity %, migration %, incident stats, risk trend).
- **Milestone End:** Formal go/no-go with evidence packets.

---

## 19) Appendices

### Appendix A — Required Structured Log Envelope (example)

```json
{
  "timestamp": "ISO-8601",
  "job_key": "string",
  "run_id": "uuid",
  "execution_mode": "python",
  "batch_size": 1000,
  "rows_processed": 987,
  "duration_ms": 1234,
  "outcome": "success|failure",
  "error_type": null,
  "error_message": null,
  "checkpoint_before": "...",
  "checkpoint_after": "..."
}
```

### Appendix B — Batch Job Skeleton (conceptual)

1. Load checkpoint.
2. Fetch bounded batch.
3. Validate and process records.
4. Persist outputs atomically.
5. Persist new checkpoint.
6. Emit structured metrics/logs.
7. Exit or continue until window exhausted.

### Appendix C — Cutover Gate Template

- Contract lint: pass/fail
- Parity report: pass/fail
- Resume test: pass/fail
- Observability validation: pass/fail
- Rollback rehearsal: pass/fail
- Stakeholder approval: approved/rejected

---

## 20) Final Note

This plan intentionally favors architecture integrity, deterministic behavior, and operational resilience over rapid but fragile delivery. If trade-offs appear, decisions must be made in favor of:

1. contract correctness,
2. schema authority,
3. runtime parity,
4. safe incremental execution,
5. long-term maintainability.

That is the standard for a production-grade data platform.
