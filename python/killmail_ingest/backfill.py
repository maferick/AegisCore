"""EVE Ref historical backfill runner.

Reconciles local state against EVE Ref's totals.json manifest,
downloads missing or updated days, and ingests all killmails via
the shared persist.ingest_killmail() entry point.

Resilience model (mirrors stream.py — single-killmail unit of work):

  - **Per-killmail commit.** Each ingest + outbox write runs in its
    own tiny transaction. One poisoned killmail never taints the
    rest of the day. Lock hold time stays in milliseconds, which
    avoids the deadlock trap the old per-day transaction opened
    against the live stream.

  - **Deadlock / lock-wait retry.** Server-side aborts (errno 1213
    or 1205) are retried up to 3× with small jittered backoff. The
    connection stays live; the tx was rolled back by the server, we
    roll back defensively anyway, and re-attempt the same unit.

  - **Connection-loss reconnect.** On the `(0, '')` class of errors
    (MariaDB restart, wait_timeout, broken socket), close the dead
    handle, open a fresh one, and SKIP the current killmail. The
    next pass will re-process it — `ingest_killmail` is idempotent
    via `INSERT ... ON DUPLICATE KEY UPDATE` on killmails + DELETE
    / INSERT on attackers and items, so re-ingesting an existing
    killmail just refreshes it.

  - **Consistent lock order.** Killmails are processed in
    `killmail_id ASC` within each day. R2Z2 delivers the live stream
    in sequence order, which is roughly chronological and therefore
    roughly killmail_id-sorted. Consistent acquisition order across
    backfill + stream keeps deadlock probability low.

Day-level contract:

  - A day is "complete" when every killmail in the archive was
    ingested at least once. The day's `everef` state row is written
    at the end of the day with the total count.
  - If we crash mid-day, the state for that day is NOT set. Next
    pass re-downloads the archive (reconcile sees local < remote or
    local null) and re-ingests. Idempotence makes that safe.
"""

from __future__ import annotations

import random
import time
from datetime import date, timedelta

import pymysql

from killmail_ingest.config import Config
from killmail_ingest.db import (
    is_connection_lost,
    is_retryable_server_abort,
    open_connection,
)
from killmail_ingest.everef import (
    EverefMissing,
    EverefTransient,
    fetch_day_killmails,
    fetch_totals,
)
from killmail_ingest.log import get
from killmail_ingest.outbox import emit_killmail_ingested
from killmail_ingest.parse import parse_esi_killmail
from killmail_ingest.persist import ingest_killmail
from killmail_ingest.state import get_all_states, set_state


log = get(__name__)


# Retry discipline for deadlocks / lock-wait timeouts on a single
# killmail. 3 attempts covers the vast majority of transient
# contention with the live stream; beyond that we skip the row
# (idempotent re-process on next backfill pass).
_MAX_DEADLOCK_RETRIES = 3

# Jittered backoff between retries. Keeps retrying killmails in a
# concurrent-backfill scenario from synchronising on the next
# attempt. Values in seconds.
_RETRY_BASE_DELAY = 0.05
_RETRY_JITTER = 0.15


class _ConnHolder:
    """Reassignable pymysql connection handle. Long-running runners
    (stream, backfill) need to replace the live connection on loss;
    passing the holder around keeps `holder.conn` pointed at the
    current valid socket without restructuring every call site."""

    def __init__(self, cfg: Config) -> None:
        self._cfg = cfg
        self.conn: pymysql.connections.Connection = open_connection(cfg)
        self._consecutive_reconnect_failures = 0

    def reconnect(self) -> None:
        """Close (best-effort) and reopen. Uses escalating backoff so
        a thrashing MariaDB doesn't get hammered by immediate retries
        during a cold start — we pay the wait time once per
        consecutive failure, not per killmail."""
        try:
            self.conn.close()
        except Exception:  # pragma: no cover — best-effort close
            pass
        try:
            self.conn = open_connection(self._cfg)
            self._consecutive_reconnect_failures = 0
        except Exception as exc:
            self._consecutive_reconnect_failures += 1
            backoff = min(30, 5 * self._consecutive_reconnect_failures)
            log.error(
                "reconnect failed; sleeping before next attempt",
                error=str(exc),
                consecutive_failures=self._consecutive_reconnect_failures,
                backoff_seconds=backoff,
            )
            time.sleep(backoff)
            raise

    def close(self) -> None:
        try:
            self.conn.close()
        except Exception:  # pragma: no cover — best-effort close
            pass


def run_backfill(cfg: Config) -> int:
    """Run one backfill pass. Returns 0 on success, 1 if any day failed."""
    log.info(
        "backfill starting",
        min_date=cfg.min_date.isoformat(),
        max_date=cfg.max_date.isoformat(),
        recheck_days=cfg.recheck_window_days,
    )

    # Fetch the manifest.
    try:
        totals = fetch_totals(cfg)
    except (EverefMissing, EverefTransient) as exc:
        log.error("failed to fetch totals.json", error=str(exc))
        return 1

    log.info("totals.json loaded", days=len(totals))

    holder = _ConnHolder(cfg)
    try:
        local_state = get_all_states(holder.conn, "everef")

        # Build target list: days that need (re)processing.
        targets = _compute_targets(cfg, totals, local_state)

        if not targets:
            log.info("backfill: nothing to do")
            return 0

        log.info("backfill: targets computed", days=len(targets))

        loaded = 0
        skipped = 0
        failed = 0

        for day in sorted(targets):
            try:
                count = _process_day(holder, cfg, day)
                loaded += 1
                log.info("day loaded", day=day.isoformat(), killmails=count)
            except EverefMissing:
                skipped += 1
                log.info("day skipped (no archive)", day=day.isoformat())
            except EverefTransient as exc:
                failed += 1
                log.error("day failed (transient everef)", day=day.isoformat(), error=str(exc))
            except Exception as exc:
                failed += 1
                log.error("day failed (unexpected)", day=day.isoformat(), error=str(exc))
                # Best-effort rollback of any in-flight state write.
                try:
                    holder.conn.rollback()
                except Exception:
                    pass
    finally:
        holder.close()

    log.info("backfill complete", loaded=loaded, skipped=skipped, failed=failed)
    return 1 if failed > 0 else 0


def _compute_targets(
    cfg: Config,
    totals: dict[date, int],
    local_state: dict[str, str],
) -> list[date]:
    """Determine which days need processing."""
    targets = []
    recheck_start = cfg.max_date - timedelta(days=cfg.recheck_window_days)

    # Filter to configured date range.
    candidate_days = sorted(
        d for d in totals
        if cfg.min_date <= d <= cfg.max_date
    )

    # Override with only_dates if specified.
    if cfg.only_dates:
        return sorted(cfg.only_dates)

    for day in candidate_days:
        local_count_str = local_state.get(day.isoformat())
        remote_count = totals[day]

        if local_count_str is None:
            # Never processed — must download.
            targets.append(day)
        elif day >= recheck_start:
            # Within rolling recheck window — re-check if count changed.
            if int(local_count_str) < remote_count:
                targets.append(day)
        # Otherwise: already processed and outside recheck window, skip.

    return targets


def _process_day(
    holder: _ConnHolder,
    cfg: Config,
    day: date,
) -> int:
    """Download, parse, and ingest one day's killmails. Returns count
    of successfully-ingested killmails."""
    raw_killmails = fetch_day_killmails(cfg, day)

    if not raw_killmails:
        # Empty archive — mark as processed with 0 count.
        _persist_day_state(holder, cfg, day, 0)
        return 0

    # Sort by killmail_id for consistent lock acquisition order against
    # the live stream. R2Z2 delivers in sequence order (roughly
    # chronological → roughly killmail_id-ordered); backfill matching
    # that order minimises the deadlock window when both processes
    # write the same rows concurrently.
    raw_killmails.sort(key=lambda r: int(r.get("killmail_id") or 0))

    new_count = 0
    update_count = 0
    failed_count = 0

    for raw in raw_killmails:
        outcome = _ingest_one(holder, cfg, raw, day)
        if outcome == "new":
            new_count += 1
        elif outcome == "updated":
            update_count += 1
        else:
            failed_count += 1

    total = new_count + update_count

    if failed_count == 0:
        # Full day — persist the state so the reconcile skips it next
        # pass. Partial days deliberately do not set state: the
        # reconcile will see local_count=None or local_count<remote
        # and re-download. Re-ingestion is idempotent via
        # INSERT ON DUP UPDATE, so the only cost is bandwidth + time.
        _persist_day_state(holder, cfg, day, total)
    else:
        log.warning(
            "day partially loaded; state not persisted so next pass re-runs",
            day=day.isoformat(),
            new=new_count,
            updated=update_count,
            failed=failed_count,
        )

    return total


def _ingest_one(
    holder: _ConnHolder,
    cfg: Config,
    raw: dict,
    day: date,
) -> str:
    """Ingest a single killmail in its own per-row transaction.

    Returns:
      - "new"     — new row inserted into killmails.
      - "updated" — existing row updated (idempotent re-ingest).
      - "failed"  — gave up; caller should count it as a miss.

    Failure classification:
      - Parse / data-shape errors → rollback, log, return "failed".
        Not retryable, not a DB problem; re-ingest won't help.
      - Deadlock / lock-wait (1213 / 1205) → rollback, jittered sleep,
        retry up to `_MAX_DEADLOCK_RETRIES`.
      - Connection lost (`(0, '')`, 2006, 2013, 2014, 2055) → close
        the dead connection, reopen, return "failed" without
        retrying. The next pass re-processes the row via idempotence.
      - Any other DB error → rollback, log, return "failed". The
        row is left for next pass; if the problem is persistent
        (schema drift, data corruption), the operator sees
        repeated warnings + can investigate.
    """
    killmail_id = raw.get("killmail_id", "?")

    try:
        km = parse_esi_killmail(raw)
    except Exception as exc:
        log.warning(
            "killmail parse failed; skipping",
            killmail_id=killmail_id,
            day=day.isoformat(),
            error=str(exc),
        )
        return "failed"

    for attempt in range(_MAX_DEADLOCK_RETRIES):
        try:
            was_new = ingest_killmail(holder.conn, km)
            emit_killmail_ingested(holder.conn, km=km)

            if cfg.dry_run:
                holder.conn.rollback()
            else:
                holder.conn.commit()

            return "new" if was_new else "updated"

        except Exception as exc:
            # Unwind whatever we started in this attempt. Harmless on
            # a server-rolled-back deadlock; necessary for lock-wait
            # timeouts (which only roll the statement back).
            try:
                holder.conn.rollback()
            except Exception:
                pass

            if is_connection_lost(exc):
                log.warning(
                    "DB connection lost during killmail ingest; reconnecting",
                    killmail_id=killmail_id,
                    day=day.isoformat(),
                )
                try:
                    holder.reconnect()
                except Exception:
                    # Reconnect attempt failed; _ConnHolder.reconnect
                    # already slept + logged. Fall through and let the
                    # next killmail trigger another attempt rather
                    # than stacking retries here.
                    pass
                return "failed"

            if is_retryable_server_abort(exc) and attempt < _MAX_DEADLOCK_RETRIES - 1:
                delay = _RETRY_BASE_DELAY + random.random() * _RETRY_JITTER
                log.info(
                    "server-side abort; retrying killmail",
                    killmail_id=killmail_id,
                    day=day.isoformat(),
                    errno=(exc.args[0] if exc.args else None),
                    attempt=attempt + 1,
                    max_attempts=_MAX_DEADLOCK_RETRIES,
                    backoff_seconds=round(delay, 3),
                )
                time.sleep(delay)
                continue

            # Non-retryable, non-connection error — log + move on.
            log.warning(
                "skipping killmail",
                killmail_id=killmail_id,
                day=day.isoformat(),
                error=str(exc),
            )
            return "failed"

    # Exhausted deadlock retries.
    log.warning(
        "skipping killmail after deadlock retries",
        killmail_id=killmail_id,
        day=day.isoformat(),
        max_attempts=_MAX_DEADLOCK_RETRIES,
    )
    return "failed"


def _persist_day_state(
    holder: _ConnHolder,
    cfg: Config,
    day: date,
    total: int,
) -> None:
    """Write the day's completion state + commit. Tolerates connection
    loss: if the write fails because the socket died, we reconnect
    so subsequent days can proceed — the un-persisted day becomes a
    no-op re-download on the next pass via the reconcile path."""
    try:
        set_state(holder.conn, "everef", day.isoformat(), str(total))
        if cfg.dry_run:
            holder.conn.rollback()
        else:
            holder.conn.commit()
    except Exception as exc:
        try:
            holder.conn.rollback()
        except Exception:
            pass
        if is_connection_lost(exc):
            log.warning(
                "DB connection lost writing day state; reconnecting",
                day=day.isoformat(),
                total=total,
            )
            try:
                holder.reconnect()
            except Exception:
                pass
        else:
            log.error(
                "failed to persist day state",
                day=day.isoformat(),
                total=total,
                error=str(exc),
            )
