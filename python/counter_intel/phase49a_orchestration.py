"""Phase 4.9A — compute orchestration.

Two pieces:

  ComputeLog        context-manager wrapper that writes one row to
                     compute_run_log on entry (status=running) and
                     finalises it on exit (status=succeeded|failed,
                     compute_finished_at, duration_ms, row counts).

  run_lane_metrics   recomputes compute_lane_metrics from
                     compute_run_log: pending, running, succeeded_24h,
                     failed_24h, avg/p95 duration, oldest_pending_seconds,
                     throughput_per_hour, derived lane_state.

Lanes (8): ingest, parser, graph, operational, doctrine,
intelligence_generation, governance, maintenance.
"""

from __future__ import annotations

import json
import time
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase49a_orchestration")


LANES = (
    "ingest", "parser", "graph", "operational", "doctrine",
    "intelligence_generation", "governance", "maintenance",
)


class ComputeLog:
    """Context manager that writes one compute_run_log row.

    Usage:

        with ComputeLog(conn, lane='governance', pipeline='phase48-trust-metrics',
                        viewer_bloc_id=1, args={'window_days': 30}) as r:
            r.set_source_rows(1234)
            ...do work...
            r.set_generated_rows(56)
            r.set_stats({'tier_high': 2})

    On `__exit__` without exception → status='succeeded'.
    On `__exit__` with exception → status='failed', error_message set.
    Either way, compute_finished_at + duration_ms get written.

    The wrapper opens its own transaction-free connection cursor pair
    via the supplied conn. It commits the start row immediately (so a
    long-running pipeline can be observed mid-flight); commits the
    finish row on exit.
    """

    def __init__(
        self,
        conn: pymysql.connections.Connection,
        *,
        lane: str,
        pipeline: str,
        viewer_bloc_id: int | None = None,
        compute_version: str = "v1",
        args: dict | None = None,
    ) -> None:
        if lane not in LANES:
            raise ValueError(f"unknown lane: {lane}")
        self._conn = conn
        self.lane = lane
        self.pipeline = pipeline
        self.viewer_bloc_id = viewer_bloc_id
        self.compute_version = compute_version
        self.args = args or {}
        self.run_id: int | None = None
        self._t_start: float = 0.0
        self.source_row_count: int | None = None
        self.generated_row_count: int | None = None
        self.stats: dict = {}

    def __enter__(self) -> "ComputeLog":
        self._t_start = time.time()
        with self._conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO compute_run_log
                  (lane, pipeline, viewer_bloc_id, compute_version,
                   status, compute_started_at, args_json)
                VALUES (%s, %s, %s, %s, 'running', %s, %s)
                """,
                (
                    self.lane, self.pipeline, self.viewer_bloc_id,
                    self.compute_version,
                    datetime.now(timezone.utc),
                    json.dumps(self.args, default=str),
                ),
            )
            self.run_id = cur.lastrowid
        self._conn.commit()
        return self

    def __exit__(self, exc_type, exc, tb) -> bool:
        duration_ms = int((time.time() - self._t_start) * 1000)

        # CircuitOpenError is a clean skip — record as 'aborted' with
        # circuit_state='open' so the dashboard distinguishes it from
        # an actual run failure.
        try:
            from counter_intel.phase49d_retry import CircuitOpenError
        except Exception:
            CircuitOpenError = ()  # type: ignore[assignment]

        if exc is None:
            status = "succeeded"
            err = None
        elif CircuitOpenError and isinstance(exc, CircuitOpenError):
            status = "aborted"
            err = "circuit open — skipped"
        else:
            status = "failed"
            err = (str(exc) or exc.__class__.__name__)[:500]

        # Pull retry/circuit fields from the stats dict so callers
        # using the retry helper get them surfaced to compute_run_log
        # without having to think about it.
        retry_count = int(self.stats.get("retry_count") or 0)
        retry_reason = str(self.stats.get("retry_reason") or "none")
        circuit_state = str(self.stats.get("circuit_state") or "closed")
        # Sanitise to enum values.
        if retry_reason not in {"transient", "contention", "rate_limit",
                                 "permanent", "malformed_input", "none"}:
            retry_reason = "none"
        if circuit_state not in {"closed", "open", "half_open"}:
            # closed_failed (terminal-after-retries) collapses to closed.
            circuit_state = "closed"

        with self._conn.cursor() as cur:
            cur.execute(
                """
                UPDATE compute_run_log
                   SET status = %s,
                       compute_finished_at = %s,
                       compute_duration_ms = %s,
                       source_row_count = %s,
                       generated_row_count = %s,
                       error_message = %s,
                       retry_count = %s,
                       retry_reason = %s,
                       circuit_state = %s,
                       stats_json = %s
                 WHERE id = %s
                """,
                (
                    status, datetime.now(timezone.utc), duration_ms,
                    self.source_row_count, self.generated_row_count, err,
                    retry_count, retry_reason, circuit_state,
                    json.dumps(self.stats, default=str),
                    self.run_id,
                ),
            )
        self._conn.commit()
        return False  # do not suppress

    def set_source_rows(self, n: int | None) -> None:
        self.source_row_count = int(n) if n is not None else None

    def set_generated_rows(self, n: int | None) -> None:
        self.generated_row_count = int(n) if n is not None else None

    def set_stats(self, stats: dict) -> None:
        self.stats.update(stats or {})


@contextmanager
def maybe_log(conn, *, lane: str, pipeline: str, **kwargs):
    """Convenience wrapper. Equivalent to:

        with ComputeLog(conn, lane=lane, pipeline=pipeline, **kwargs) as r:
            yield r
    """
    with ComputeLog(conn, lane=lane, pipeline=pipeline, **kwargs) as r:
        yield r


# =====================================================================
# Lane metrics rollup
# =====================================================================

def run_lane_metrics(conn: pymysql.connections.Connection, cfg: Config) -> dict:
    log.info("phase4.9A lane metrics starting", {})
    now = datetime.now(timezone.utc)
    cutoff_24h = now - timedelta(hours=24)

    written = 0
    out: dict[str, dict] = {}
    for lane in LANES:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  SUM(status = 'running') AS running,
                  SUM(status = 'succeeded' AND compute_started_at >= %s) AS succ_24h,
                  SUM(status = 'failed'    AND compute_started_at >= %s) AS fail_24h,
                  AVG(CASE WHEN status='succeeded' AND compute_started_at >= %s
                            THEN compute_duration_ms END) AS avg_dur,
                  MIN(CASE WHEN status='running' THEN compute_started_at END) AS oldest_running
                  FROM compute_run_log WHERE lane = %s
                """,
                (cutoff_24h, cutoff_24h, cutoff_24h, lane),
            )
            row = cur.fetchone() or {}

        running = int(row.get("running") or 0)
        succ_24h = int(row.get("succ_24h") or 0)
        fail_24h = int(row.get("fail_24h") or 0)
        avg_dur = int(row.get("avg_dur") or 0) if row.get("avg_dur") else None

        # p95 via separate ordered scan over last 24h successes.
        p95 = None
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT compute_duration_ms FROM compute_run_log
                 WHERE lane = %s AND status = 'succeeded'
                   AND compute_started_at >= %s
                   AND compute_duration_ms IS NOT NULL
                 ORDER BY compute_duration_ms ASC
                """,
                (lane, cutoff_24h),
            )
            durs = [int(r["compute_duration_ms"]) for r in cur.fetchall()]
        if durs:
            idx = max(0, int(round(0.95 * (len(durs) - 1))))
            p95 = durs[idx]

        oldest_pending_seconds = None
        oldest_running = row.get("oldest_running")
        if oldest_running is not None:
            oldest_pending_seconds = int((now - oldest_running.replace(tzinfo=timezone.utc)).total_seconds())

        throughput = round(succ_24h / 24.0, 2)

        # Lane state heuristic.
        if fail_24h > 0 and succ_24h == 0:
            state = "failed"
        elif oldest_pending_seconds is not None and oldest_pending_seconds > 3600:
            state = "starved"
        elif running >= 4:
            state = "backlogged"
        elif fail_24h > 0 and (fail_24h / max(1, succ_24h + fail_24h)) >= 0.20:
            state = "degraded"
        else:
            state = "healthy"

        evidence = {
            "succeeded_24h": succ_24h,
            "failed_24h": fail_24h,
            "p95_ms": p95,
            "oldest_pending_seconds": oldest_pending_seconds,
        }

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO compute_lane_metrics
                  (lane, generated_at, pending_jobs, running_jobs,
                   succeeded_24h, failed_24h, avg_duration_ms, p95_duration_ms,
                   oldest_pending_seconds, throughput_per_hour, lane_state,
                   evidence_json)
                VALUES (%s, %s, 0, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    generated_at = VALUES(generated_at),
                    pending_jobs = VALUES(pending_jobs),
                    running_jobs = VALUES(running_jobs),
                    succeeded_24h = VALUES(succeeded_24h),
                    failed_24h = VALUES(failed_24h),
                    avg_duration_ms = VALUES(avg_duration_ms),
                    p95_duration_ms = VALUES(p95_duration_ms),
                    oldest_pending_seconds = VALUES(oldest_pending_seconds),
                    throughput_per_hour = VALUES(throughput_per_hour),
                    lane_state = VALUES(lane_state),
                    evidence_json = VALUES(evidence_json)
                """,
                (
                    lane, now, running, succ_24h, fail_24h,
                    avg_dur, p95,
                    oldest_pending_seconds, throughput,
                    state, json.dumps(evidence, default=str),
                ),
            )
        written += 1
        out[lane] = {
            "running": running, "succ_24h": succ_24h, "fail_24h": fail_24h,
            "state": state, "p95_ms": p95, "throughput": throughput,
        }
    conn.commit()
    log.info("phase4.9A lane metrics done", {"lanes": written})
    return {"lanes": written, "by_lane": out}
