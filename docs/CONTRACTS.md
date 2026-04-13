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

## Job contract

Each job must define:
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
