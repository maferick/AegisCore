"""The outbox claim → project → ack loop.

One pass:

  1. Open a transaction on the outbox connection.
  2. SELECT up to `batch_size` rows where:
       processed_at IS NULL
       AND attempts < max_attempts          (skip dead letters)
       AND event_type IN (known projectors) (skip unknowns for
                                             future consumers)
     ORDER BY id ASC
     FOR UPDATE SKIP LOCKED
  3. For each claimed row:
       - Look up the projector by event_type.
       - Run it against the read connection + influx client.
       - On success, mark `processed_at = NOW(6)` and clear
         `last_error`.
       - On failure, increment `attempts`, store the error
         excerpt, leave `processed_at` NULL.
  4. Commit the transaction (releases SKIP LOCKED holds).

`SELECT FOR UPDATE SKIP LOCKED` is the load-bearing primitive: it
lets multiple relay processes (or a future scale-out) claim
disjoint batches without blocking each other. Phase-1 single
process doesn't strictly need it, but it costs nothing and gates
the path to horizontal scale.

Per-event isolation: a projector that throws does NOT poison the
batch. The relay records the failure on the row and proceeds to
the next claimed event. Only an exception inside the relay-
framework code itself (DB connection drop, etc.) aborts the batch
and rolls back — those rows simply re-claim on the next pass.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import datetime, timezone

import pymysql

from outbox_relay.config import Config
from outbox_relay.db import connect_outbox, connect_reads
from outbox_relay.influx import InfluxClient, client as influx_client
from outbox_relay.log import get
from outbox_relay.projectors.dispatch import PROJECTOR_REGISTRY, known_event_types


log = get(__name__)


@dataclass(frozen=True)
class PassResult:
    """Outcome of one claim/project/ack pass — drives the loop's
    "queue is empty, sleep" decision and the operator log line."""
    claimed: int
    succeeded: int
    failed: int
    points_written: int

    @property
    def queue_empty(self) -> bool:
        return self.claimed == 0


def run_one_pass(cfg: Config) -> PassResult:
    """Claim + project + ack one batch. Returns counters for
    operator-facing logging + the loop's idle-detection."""
    with connect_outbox(cfg) as outbox_conn, connect_reads(cfg) as read_conn:
        with influx_client(cfg) as influx:
            return _process_one_batch(outbox_conn, read_conn, influx, cfg)


def run_loop(cfg: Config, *, interval_seconds: int) -> None:
    """Long-lived loop. Holds connections open across passes (cheaper
    than reconnecting each tick) and sleeps `interval_seconds`
    between passes. Exits cleanly on KeyboardInterrupt / SIGINT."""
    import time as _time  # local import keeps the one-shot path import-clean

    log.info(
        "outbox_relay starting in loop mode",
        interval_seconds=interval_seconds,
        batch_size=cfg.batch_size,
        max_attempts=cfg.max_attempts,
    )
    with connect_outbox(cfg) as outbox_conn, connect_reads(cfg) as read_conn:
        with influx_client(cfg) as influx:
            while True:
                try:
                    result = _process_one_batch(outbox_conn, read_conn, influx, cfg)
                except Exception:  # pragma: no cover — top-level safety net
                    log.exception("relay batch crashed; reconnecting on next pass")
                    # Bubble up so the outer connection contexts
                    # tear down + re-open. Simpler than mid-loop
                    # reconnect logic.
                    raise

                # Adaptive cadence: when the queue had nothing,
                # sleep the full interval; when there was work,
                # immediately try again — there's likely more
                # waiting. Bounded by a minimum 100ms back-off so
                # we don't spin CPU on a misbehaving DB.
                if result.queue_empty:
                    log.debug("queue empty, sleeping", interval_seconds=interval_seconds)
                    try:
                        _time.sleep(interval_seconds)
                    except KeyboardInterrupt:
                        log.info("interrupted; exiting loop")
                        return
                else:
                    _time.sleep(0.1)


# -- internals ------------------------------------------------------------


def _process_one_batch(
    outbox_conn: pymysql.connections.Connection,
    read_conn: pymysql.connections.Connection,
    influx: InfluxClient,
    cfg: Config,
) -> PassResult:
    claimed_rows = _claim_batch(outbox_conn, cfg)
    if not claimed_rows:
        # Commit immediately to release any read locks the SELECT
        # might have held even with no matching rows.
        outbox_conn.commit()
        return PassResult(claimed=0, succeeded=0, failed=0, points_written=0)

    log.info("batch claimed", count=len(claimed_rows))

    succeeded = 0
    failed = 0
    points_total = 0
    now = _utc_now_naive()

    for row in claimed_rows:
        result = _project_one(row, read_conn, influx)
        if result.success:
            _mark_processed(outbox_conn, row["id"], now)
            succeeded += 1
            points_total += result.points_written
        else:
            _mark_failed(outbox_conn, row["id"], now, result.error_excerpt)
            failed += 1

    outbox_conn.commit()
    log.info(
        "batch complete",
        claimed=len(claimed_rows),
        succeeded=succeeded,
        failed=failed,
        points_written=points_total,
    )
    return PassResult(
        claimed=len(claimed_rows),
        succeeded=succeeded,
        failed=failed,
        points_written=points_total,
    )


def _claim_batch(
    conn: pymysql.connections.Connection,
    cfg: Config,
) -> list[dict]:
    """Claim up to `batch_size` rows under SELECT FOR UPDATE SKIP
    LOCKED, scoped to event types this relay knows. Excludes rows
    already attempted >= max_attempts (dead letters)."""
    known = sorted(known_event_types())
    if not known:
        # No projectors registered — nothing for this relay to do.
        return []
    placeholders = ",".join(["%s"] * len(known))
    sql = f"""
        SELECT id, event_id, event_type, aggregate_type, aggregate_id,
               payload, attempts
          FROM outbox
         WHERE processed_at IS NULL
           AND attempts     < %s
           AND event_type IN ({placeholders})
         ORDER BY id ASC
         LIMIT %s
         FOR UPDATE SKIP LOCKED
    """
    params = (cfg.max_attempts, *known, cfg.batch_size)
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return list(cur.fetchall())


@dataclass(frozen=True)
class _ProjectionResult:
    success: bool
    points_written: int
    error_excerpt: str  # populated only when success=False


def _project_one(
    row: dict,
    read_conn: pymysql.connections.Connection,
    influx: InfluxClient,
) -> _ProjectionResult:
    event_type = str(row["event_type"])
    projector = PROJECTOR_REGISTRY.get(event_type)
    if projector is None:
        # Shouldn't happen — we filtered the SELECT to known types.
        # But defensive: if a projector is removed between SELECT
        # and dispatch (race), record the failure so the operator
        # notices.
        return _ProjectionResult(False, 0, f"no projector for event_type={event_type}")

    payload = _decode_payload(row["payload"])
    if payload is None:
        return _ProjectionResult(
            False, 0, f"payload not JSON-decodable for outbox.id={row['id']}",
        )

    try:
        points = projector(read_conn, influx, payload, log)
    except Exception as exc:
        # Truncate + log, then return as a failure so the relay can
        # bump attempts on the row. The full traceback goes to
        # logs (via .exception); only an excerpt persists in
        # outbox.last_error.
        log.exception(
            "projector raised",
            event_id=row["event_id"],
            event_type=event_type,
            outbox_id=row["id"],
        )
        return _ProjectionResult(False, 0, _excerpt(exc))

    return _ProjectionResult(True, int(points), "")


def _decode_payload(raw: object) -> dict | None:
    """The `payload` column is JSON. pymysql may return it as either
    `str` (older drivers) or `dict` (with native JSON decode)."""
    if isinstance(raw, dict):
        return raw
    if isinstance(raw, (bytes, bytearray)):
        raw = raw.decode("utf-8", errors="replace")
    if isinstance(raw, str):
        try:
            return json.loads(raw)
        except json.JSONDecodeError:
            return None
    return None


def _mark_processed(
    conn: pymysql.connections.Connection,
    outbox_id: int,
    now: datetime,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE outbox
               SET processed_at = %s,
                   last_error   = NULL
             WHERE id = %s
            """,
            (now, outbox_id),
        )


def _mark_failed(
    conn: pymysql.connections.Connection,
    outbox_id: int,
    now: datetime,
    error_excerpt: str,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE outbox
               SET attempts   = attempts + 1,
                   last_error = %s
             WHERE id = %s
            """,
            (error_excerpt, outbox_id),
        )


def _utc_now_naive() -> datetime:
    """MariaDB TIMESTAMP(6) columns are naive — strip tz before
    INSERT. We always write UTC."""
    return datetime.now(timezone.utc).replace(tzinfo=None)


def _excerpt(exc: BaseException, *, limit: int = 1000) -> str:
    """Truncate exception messages to keep `outbox.last_error`
    bounded. The full traceback is in logs."""
    s = f"{type(exc).__name__}: {exc}"
    return s if len(s) <= limit else s[: limit - 3] + "..."
