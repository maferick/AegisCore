# Contracts

Stable interfaces for AegisCore. Changes here are breaking changes — version
and announce them.

## API contract

- Versioned under `/api/v1/...`
- Success envelope:
  ```json
  { "data": ..., "meta": { } }
  ```
- Error envelope:
  ```json
  { "error": { "code": "...", "message": "...", "details": { } }, "meta": { } }
  ```
- `meta` always present; carries pagination, timing, trace id.
- DTO layer: **`spatie/laravel-data`**. API Resources alone are not allowed —
  they leak Eloquent shape into the wire contract over time.

## Plane boundary — Laravel ↔ Python

Laravel (control plane) and Python (execution plane) communicate via the
**outbox pattern**, never by direct calls, shared queues, or cross-plane writes
to derived stores. The rule is enforced at code review.

### Hard rules

1. Laravel writes to MariaDB only. Python owns writes to Neo4j, OpenSearch,
   and InfluxDB.
2. Laravel does not enqueue work directly into Python. Laravel writes an
   `outbox` row; Python consumes it.
3. Laravel queue jobs (Horizon) must target p95 < 2s. Row-touch budget is
   <= 100 rows by default; 101–500 rows are allowed only with explicit
   chunking, idempotency, and monitoring. Anything > 500 rows belongs in
   Python.
4. `outbox` rows are written **inside the same MariaDB transaction** as the
   business change — never post-commit.

### Job placement — PR review checklist

Ask three questions of every new job:

1. Could this block user-facing responsiveness if delayed or retried?
2. Could data size grow 10× and make this unsafe in a Laravel queue?
3. Is this part of domain-data ingestion or projection?

**Yes to any → prefer Python.** The trigger still originates in Laravel as an
outbox row; the work itself runs in the execution plane. Full placement
criteria (what stays in PHP, what moves to Python) live in
[`AGENTS.md`](../AGENTS.md#job-placement-rule).

### Why outbox

- **Atomicity.** Business change + trigger commit together, or not at all.
- **Replay.** Any unprocessed or failed row can be retried without rebuilding
  app state.
- **Audit.** Every cross-plane trigger has a timestamped record.
- **No ambiguity.** "Did my enqueue happen?" is answered by a SELECT.

### Schema (indicative; finalized in the Laravel migration)

```sql
CREATE TABLE outbox (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  event_id        CHAR(26)     NOT NULL UNIQUE,     -- ULID
  event_type      VARCHAR(128) NOT NULL,            -- e.g. killmail.status.changed
  aggregate_type  VARCHAR(64)  NOT NULL,            -- e.g. killmail
  aggregate_id    VARCHAR(64)  NOT NULL,            -- primary key of the aggregate
  payload         JSON         NOT NULL,
  created_at      TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  processed_at    TIMESTAMP(6) NULL,
  attempts        INT UNSIGNED NOT NULL DEFAULT 0,
  last_error      TEXT         NULL,
  INDEX idx_unprocessed (processed_at, id)
);
```

### Consumer semantics (Python relay)

- Claim work with `SELECT … FOR UPDATE SKIP LOCKED LIMIT :batch` — parallel
  relays never duplicate.
- On success, set `processed_at = NOW(6)`.
- On failure, increment `attempts`, store `last_error`, leave `processed_at`
  NULL. Backoff is the relay's responsibility; failed rows never block newer
  ones.
- Rows are subject to retention via Phase 4.9C
  (`python/counter_intel/phase49c_retention.RETENTION`):
  processed rows older than 7 days are deleted, unprocessed rows are
  never auto-deleted. See [`docs/RETENTION.md`](RETENTION.md) for the
  full TTL ladder. The 30-day cold-storage path documented in
  earlier drafts of this file is **not implemented** — v1 keeps the
  short window plus restorable backups instead. Restore procedure:
  [`docs/RUNBOOK.md`](RUNBOOK.md) § Retention.

### Event naming

- Pattern: `<aggregate_type>.<verb-past-tense>`
- Examples: `killmail.ingested`, `killmail.status.changed`,
  `character.linked`, `doctrine.published`,
  `reference.sde_snapshot_loaded` (see
  [ADR-0001](adr/0001-static-reference-data.md)).
- `payload` carries the minimum Python needs — IDs + changed state, not full
  row copies.

### Transport (Phase 1 → later)

- **Phase 1:** Python polls `outbox` directly. Zero extra infra.
- **Later:** if polling becomes hot, swap the transport to Redis streams or
  MariaDB CDC without changing the write contract — Laravel still only writes
  to `outbox`.

## Job contract

Applies to every long-running job in either plane.

- `job_key` — stable, snake_case, immutable once released.
- **owning pillar** — one of `spy-detection`, `buyall-doctrines`,
  `killmails-battle-theaters`, `users-characters`.
- **batch / checkpoint strategy** — how it resumes after interruption.
- **timeout / retry policy** — explicit, per job.
- **structured logs** — every run emits fields:
  `job_key, batch_size, rows_processed, duration_ms, outcome, error`.

## Health contract

Every long-running service must expose:
- `GET /health` — liveness (fast, no downstream calls).
- `GET /health/ready` — readiness (checks downstream deps, returns details).

## V1 operational contracts

Phase 4.9 onwards adds platform-observability contracts that cross
both planes:

- **Compute traceability** — every Python compute pipeline writes
  one `compute_run_log` row per invocation (Phase 4.9A). Lane
  assignment is one of: ingest / parser / graph / operational /
  doctrine / intelligence_generation / governance / maintenance.
  See [`python/counter_intel/phase49a_orchestration.py`](../python/counter_intel/phase49a_orchestration.py).
- **Retry / circuit breaker** — pipelines that touch contention-prone
  resources opt into `retry()` from
  [`phase49d_retry`](../python/counter_intel/phase49d_retry.py).
  Circuit-open state is surfaced on
  `/portal/intelligence/platform-health` as
  `system_quality_events.detector='circuit_open'`.
- **Freshness contract** — every operator surface row carries
  `freshness_state` ∈ `fresh / aging / stale / expired`, plus
  `source_window_start` + `source_window_end`. Renderers must call
  `App\Services\IntelFreshness::resolve()` (or the
  `<x-intel-freshness>` blade component) to surface live aging
  between compute runs. TTL ladder lives in
  [`config/intel_ttl.json`](../app/config/intel_ttl.json) (mirror of
  Python source).
- **Audit contract** — every analyst-mutable surface
  (alert lifecycle, verified items, exports, feedback) writes one
  row to `intel_audit_log` via
  `App\Services\IntelAuditLog::record()`. Append-only.
- **Quality events** — [`phase49e_quality_guards`](../python/counter_intel/phase49e_quality_guards.py)
  emits findings into `system_quality_events` with severity
  `info / warning / elevated / critical` and ack/resolve workflow.

## V1 closure references

- [`docs/V1_FREEZE.md`](V1_FREEZE.md) — operational watch-mode
  posture: allowed work, forbidden work, exit criteria.
- [`docs/V1_COMPLETION_CHECKLIST.md`](V1_COMPLETION_CHECKLIST.md)
  — burn-down across 16 sections; contracts close against the gates
  in each section.
- [`docs/RUNBOOK.md`](RUNBOOK.md) — recipe per quality_event, lane
  state, and known incident.
- [`docs/RETENTION.md`](RETENTION.md) — TTL ladder + sweep cron.
- [`docs/ADR-market-orders-partitioning.md`](ADR-market-orders-partitioning.md)
  — proposed (v2 execution; do not run in v1).
- [`docs/adr/0011-v1-calibration-policy.md`](adr/0011-v1-calibration-policy.md)
  — calibration policy; baseline at
  `verification/calibration/baseline_2026_04_27.json`.
- [`verification/storage/db_storage_audit.md`](../verification/storage/db_storage_audit.md)
  — DB efficiency audit.
