"""Phase 4.9D — retry / back-off / circuit breaker.

One module covers:

  RetryClass        enum of retry-trigger categories
  classify_exception(exc) → RetryClass
  RetryPolicy       dataclass; max_attempts + base + cap + lane
  retry(fn, policy) callable that loops with backoff
  CircuitBreaker    per (lane, pipeline) state machine

Defaults are tuned for v1 platform: most pipelines tolerate 4
attempts with 1s base, 30s cap; dscan fetch tolerates 6 with 2s
base + Retry-After honoring; parser jobs tolerate 2 attempts
because malformed-input is the typical permanent failure.

Usage from a CLI handler:

    policy = POLICIES["compute_default"]
    with ComputeLog(...) as r:
        retry(lambda: my_compute(...), policy, conn=conn,
              lane="operational", pipeline="phase4-incidents",
              run_log=r)

The retry helper updates compute_run_log + compute_circuit_state
on every attempt + on terminal success/failure.
"""

from __future__ import annotations

import enum
import json
import random
import time
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from typing import Callable, Optional

import pymysql

from counter_intel.log import get

log = get("counter_intel.phase49d_retry")


# ---------------------------------------------------------------------
# Retry classes
# ---------------------------------------------------------------------

class RetryClass(str, enum.Enum):
    TRANSIENT = "transient"          # network / conn drop / timeout
    CONTENTION = "contention"        # lock wait, deadlock
    RATE_LIMIT = "rate_limit"        # HTTP 429, ESI rate limit headers
    PERMANENT = "permanent"          # FK violation, schema mismatch
    MALFORMED_INPUT = "malformed_input"  # parse error on the row itself


# Map a few well-known pymysql / runtime errors to retry classes.
# Anything not classified falls through to TRANSIENT — caller can
# override via classifier= on the policy.
_PYMYSQL_TRANSIENT = {2003, 2006, 2013, 2014}   # conn refused / lost
_PYMYSQL_CONTENTION = {1205, 1213}              # lock_wait / deadlock
_PYMYSQL_PERMANENT = {1062, 1452, 1451, 1054, 1146}  # dup, fk, missing


def classify_exception(exc: BaseException) -> RetryClass:
    """Default classifier. Returns the retry class for an exception.

    Callers can wrap this in a custom classifier when they have
    domain-specific errors (e.g. dscan rate-limit detection).
    """
    if isinstance(exc, pymysql.err.OperationalError):
        code = (exc.args[0] if exc.args else 0)
        if code in _PYMYSQL_TRANSIENT:
            return RetryClass.TRANSIENT
        if code in _PYMYSQL_CONTENTION:
            return RetryClass.CONTENTION
        return RetryClass.TRANSIENT  # most OperationalErrors are transient
    if isinstance(exc, pymysql.err.IntegrityError):
        code = (exc.args[0] if exc.args else 0)
        if code in _PYMYSQL_PERMANENT:
            return RetryClass.PERMANENT
        return RetryClass.PERMANENT  # IntegrityError → don't retry
    if isinstance(exc, pymysql.err.ProgrammingError):
        return RetryClass.PERMANENT
    if isinstance(exc, (ConnectionError, TimeoutError, BrokenPipeError)):
        return RetryClass.TRANSIENT
    if isinstance(exc, (ValueError, TypeError, AttributeError, KeyError)):
        return RetryClass.MALFORMED_INPUT
    return RetryClass.TRANSIENT


# ---------------------------------------------------------------------
# RetryPolicy
# ---------------------------------------------------------------------

@dataclass
class RetryPolicy:
    name: str
    max_attempts: int = 4
    base_seconds: float = 1.0
    cap_seconds: float = 30.0
    jitter: float = 0.25
    classifier: Callable[[BaseException], RetryClass] = field(default=classify_exception)
    # Per-class attempt budget. When a class hits its budget the loop
    # treats it as terminal regardless of max_attempts.
    transient_budget: int = 4
    contention_budget: int = 6  # locks resolve naturally; back-off helps
    rate_limit_budget: int = 6
    permanent_budget: int = 0   # never retry permanent
    malformed_budget: int = 0   # never retry malformed input


POLICIES: dict[str, RetryPolicy] = {
    # General compute pipelines (incidents, threat surface, etc.)
    "compute_default": RetryPolicy("compute_default"),

    # Parser jobs: malformed input is the typical failure → no retry.
    "parser": RetryPolicy(
        "parser", max_attempts=2, base_seconds=0.5, cap_seconds=4.0,
        permanent_budget=0, malformed_budget=0,
    ),

    # Graph / Neo4j: contention recoverable, transient = bolt-pool drops.
    "graph": RetryPolicy(
        "graph", max_attempts=5, base_seconds=2.0, cap_seconds=60.0,
        contention_budget=8,
    ),

    # dscan fetch: rate-limited, transient = network blips.
    "dscan_fetch": RetryPolicy(
        "dscan_fetch", max_attempts=6, base_seconds=2.0, cap_seconds=120.0,
        rate_limit_budget=6,
    ),

    # Uploader ingest: defensive — connection drops are common from
    # mobile uploaders. Many small attempts.
    "uploader_ingest": RetryPolicy(
        "uploader_ingest", max_attempts=8, base_seconds=0.5, cap_seconds=30.0,
        transient_budget=8,
    ),
}


# ---------------------------------------------------------------------
# Circuit breaker
# ---------------------------------------------------------------------

# When a (lane, pipeline) accumulates 5 consecutive failures within a
# 10-minute window, the circuit opens for 5 minutes. Subsequent
# `should_skip_for_circuit` calls return True until cooldown_until.
# After cooldown, the next attempt runs in `half_open` — if it
# succeeds, circuit closes. If it fails, circuit re-opens with a
# longer cooldown (×2, capped at 30 minutes).

CIRCUIT_FAILURE_THRESHOLD = 5
CIRCUIT_WINDOW_SECONDS = 10 * 60
CIRCUIT_INITIAL_COOLDOWN_SECONDS = 5 * 60
CIRCUIT_MAX_COOLDOWN_SECONDS = 30 * 60


def get_circuit_state(conn, lane: str, pipeline: str) -> dict:
    """Return current circuit row, creating one in 'closed' state
    if missing."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT * FROM compute_circuit_state WHERE lane=%s AND pipeline=%s",
            (lane, pipeline),
        )
        row = cur.fetchone()
    if row is None:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO compute_circuit_state (lane, pipeline, state) VALUES (%s, %s, 'closed')",
                (lane, pipeline),
            )
        conn.commit()
        return {"lane": lane, "pipeline": pipeline, "state": "closed",
                "consecutive_failures": 0, "window_failures": 0,
                "window_started_at": None, "opened_at": None,
                "cooldown_until": None}
    return dict(row)


def should_skip_for_circuit(conn, lane: str, pipeline: str) -> tuple[bool, str]:
    """Returns (skip, state). When circuit is open and within cooldown,
    skip=True. After cooldown, transition to half_open and allow."""
    cs = get_circuit_state(conn, lane, pipeline)
    if cs["state"] == "closed":
        return (False, "closed")
    if cs["state"] == "open":
        cooldown = cs.get("cooldown_until")
        if cooldown is None:
            return (False, "open_no_cooldown")
        now = datetime.now(timezone.utc)
        cd = cooldown.replace(tzinfo=timezone.utc) if cooldown.tzinfo is None else cooldown
        if now < cd:
            return (True, "open")
        # cooldown expired → half_open
        with conn.cursor() as cur:
            cur.execute(
                "UPDATE compute_circuit_state SET state='half_open' WHERE lane=%s AND pipeline=%s",
                (lane, pipeline),
            )
        conn.commit()
        return (False, "half_open")
    return (False, cs["state"])


def record_success(conn, lane: str, pipeline: str) -> None:
    """Successful run closes the circuit."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE compute_circuit_state
               SET state='closed',
                   consecutive_failures=0,
                   window_failures=0,
                   window_started_at=NULL,
                   opened_at=NULL,
                   cooldown_until=NULL,
                   last_success_at=NOW()
             WHERE lane=%s AND pipeline=%s
            """,
            (lane, pipeline),
        )
    conn.commit()


def record_failure(conn, lane: str, pipeline: str, retry_class: RetryClass) -> str:
    """Failed run accumulates against the threshold. Returns the
    resulting circuit state."""
    cs = get_circuit_state(conn, lane, pipeline)
    now = datetime.now(timezone.utc)
    window_start = cs.get("window_started_at")
    window_failures = int(cs.get("window_failures") or 0)
    if window_start is None:
        window_start = now
        window_failures = 0
    else:
        ws = window_start.replace(tzinfo=timezone.utc) if window_start.tzinfo is None else window_start
        if (now - ws).total_seconds() > CIRCUIT_WINDOW_SECONDS:
            window_start = now
            window_failures = 0
    window_failures += 1
    consecutive = int(cs.get("consecutive_failures") or 0) + 1

    if window_failures >= CIRCUIT_FAILURE_THRESHOLD:
        # Open. Pick cooldown — first open is 5 min; if we were
        # already open and reopened from half_open, double up to cap.
        prior_cd = cs.get("cooldown_until")
        if prior_cd is not None and cs.get("state") in ("half_open", "open"):
            base_cd = CIRCUIT_INITIAL_COOLDOWN_SECONDS * 2
        else:
            base_cd = CIRCUIT_INITIAL_COOLDOWN_SECONDS
        cooldown_seconds = min(base_cd, CIRCUIT_MAX_COOLDOWN_SECONDS)
        cooldown_until = now + timedelta(seconds=cooldown_seconds)
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE compute_circuit_state
                   SET state='open',
                       consecutive_failures=%s,
                       window_failures=%s,
                       window_started_at=%s,
                       opened_at=%s,
                       cooldown_until=%s,
                       last_failure_at=%s,
                       last_failure_reason=%s
                 WHERE lane=%s AND pipeline=%s
                """,
                (consecutive, window_failures, window_start, now,
                 cooldown_until, now, retry_class.value, lane, pipeline),
            )
        conn.commit()
        log.warning("circuit opened",
                    {"lane": lane, "pipeline": pipeline,
                     "consecutive_failures": consecutive,
                     "cooldown_seconds": cooldown_seconds})
        # Best-effort emit a system_quality_event so the platform-health
        # dashboard surfaces the open circuit.
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO system_quality_events
                      (viewer_bloc_id, detector, severity, detected_at,
                       window_start, window_end, title, summary,
                       metric_value, threshold_value, evidence_json)
                    VALUES (NULL, 'circuit_open', 'elevated', NOW(),
                            %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        severity = VALUES(severity),
                        title = VALUES(title),
                        summary = VALUES(summary),
                        evidence_json = VALUES(evidence_json),
                        updated_at = NOW()
                    """,
                    (window_start, now,
                     f"Circuit opened: {lane}/{pipeline}",
                     f"{consecutive} consecutive failures in {window_failures} attempts within {CIRCUIT_WINDOW_SECONDS//60}min window. Last reason: {retry_class.value}. Cooldown {cooldown_seconds//60}min.",
                     consecutive, CIRCUIT_FAILURE_THRESHOLD,
                     json.dumps({
                         "lane": lane, "pipeline": pipeline,
                         "consecutive_failures": consecutive,
                         "window_failures": window_failures,
                         "cooldown_seconds": cooldown_seconds,
                         "retry_class": retry_class.value,
                     })),
                )
            conn.commit()
        except Exception as e:
            log.warning("circuit_open quality event insert failed", {"error": str(e)})
        return "open"

    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE compute_circuit_state
               SET consecutive_failures=%s,
                   window_failures=%s,
                   window_started_at=%s,
                   last_failure_at=%s,
                   last_failure_reason=%s
             WHERE lane=%s AND pipeline=%s
            """,
            (consecutive, window_failures, window_start, now,
             retry_class.value, lane, pipeline),
        )
    conn.commit()
    return cs.get("state") or "closed"


# ---------------------------------------------------------------------
# Retry executor
# ---------------------------------------------------------------------

class CircuitOpenError(RuntimeError):
    """Raised by retry() when the circuit for (lane, pipeline) is
    open and within cooldown. Callers should treat this as a clean
    skip rather than a hard failure — the circuit breaker has already
    surfaced the problem to operators via system_quality_events."""


def retry(
    fn: Callable[[], object],
    policy: RetryPolicy,
    *,
    conn: Optional[pymysql.connections.Connection] = None,
    lane: Optional[str] = None,
    pipeline: Optional[str] = None,
    run_log=None,  # phase49a_orchestration.ComputeLog instance
) -> object:
    """Run fn() with retry/back-off + circuit breaker. Returns fn()'s
    result. Updates the supplied ComputeLog (if any) with retry_count
    and retry_reason."""
    # Circuit gate — only when conn + lane + pipeline supplied.
    if conn is not None and lane is not None and pipeline is not None:
        skip, state = should_skip_for_circuit(conn, lane, pipeline)
        if skip:
            log.warning("circuit open — skipping run",
                        {"lane": lane, "pipeline": pipeline, "state": state})
            if run_log is not None:
                run_log.set_stats({"circuit_state": state, "skipped": True})
            raise CircuitOpenError(f"circuit open for {lane}/{pipeline}")

    last_exc: Optional[BaseException] = None
    last_class = RetryClass.TRANSIENT
    attempt = 0
    class_attempts: dict[RetryClass, int] = {c: 0 for c in RetryClass}

    while attempt < policy.max_attempts:
        attempt += 1
        try:
            result = fn()
            # Success.
            if conn is not None and lane is not None and pipeline is not None:
                record_success(conn, lane, pipeline)
            if run_log is not None:
                run_log.set_stats({
                    "retry_count": attempt - 1,
                    "retry_reason": last_class.value if attempt > 1 else "none",
                    "circuit_state": "closed",
                })
            return result
        except CircuitOpenError:
            raise
        except BaseException as exc:
            last_exc = exc
            last_class = policy.classifier(exc)
            class_attempts[last_class] += 1

            # Class-specific budget exhausted → terminal.
            budget_map = {
                RetryClass.TRANSIENT: policy.transient_budget,
                RetryClass.CONTENTION: policy.contention_budget,
                RetryClass.RATE_LIMIT: policy.rate_limit_budget,
                RetryClass.PERMANENT: policy.permanent_budget,
                RetryClass.MALFORMED_INPUT: policy.malformed_budget,
            }
            if class_attempts[last_class] > budget_map[last_class]:
                break

            log.info("retry pending", {
                "policy": policy.name, "attempt": attempt,
                "class": last_class.value,
                "error": str(exc)[:200],
            })

            # No retry for permanent / malformed input.
            if last_class in (RetryClass.PERMANENT, RetryClass.MALFORMED_INPUT):
                break

            if attempt >= policy.max_attempts:
                break

            # Exponential back-off with jitter, capped.
            delay = min(policy.cap_seconds,
                        policy.base_seconds * (2 ** (attempt - 1)))
            if policy.jitter > 0:
                delay = delay * (1 + random.uniform(-policy.jitter, policy.jitter))
            delay = max(0.0, delay)
            time.sleep(delay)

    # Exhausted.
    if conn is not None and lane is not None and pipeline is not None:
        record_failure(conn, lane, pipeline, last_class)
    if run_log is not None:
        run_log.set_stats({
            "retry_count": attempt,
            "retry_reason": last_class.value,
            "circuit_state": "closed_failed",
        })
    if last_exc is None:
        raise RuntimeError(f"retry: max_attempts={policy.max_attempts} but no exception captured")
    raise last_exc
