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
3. Laravel queue jobs (Horizon) must complete in < 2s and touch < 100 rows.
   Anything bigger belongs in Python.
4. `outbox` rows are written **inside the same MariaDB transaction** as the
   business change — never post-commit.

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
- Rows processed > 30 days ago are archived to cold storage nightly, not
  deleted.

### Event naming

- Pattern: `<aggregate_type>.<verb-past-tense>`
- Examples: `killmail.ingested`, `killmail.status.changed`,
  `character.linked`, `doctrine.published`.
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
