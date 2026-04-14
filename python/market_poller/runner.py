"""End-to-end orchestrator for one market-poll pass.

Steps:

  1. Open MariaDB connection (autocommit off; per-location transaction).
  2. Open httpx client (shared connection pool across all locations).
  3. SELECT enabled watched locations, optionally filtered by
     `--only-location-id`.
  4. For each location:
       a. Compute `observed_at = now(UTC)` at the start of the poll so
          every order in this pass shares one timestamp.
       b. Dispatch to the location-type-specific fetcher. Phase 1 only
          handles NPC stations — structures log-skip until the auth'd
          ESI path lands.
       c. Bulk-insert into market_orders + update watched-locations
          bookkeeping + emit outbox event, all in one transaction.
       d. On transient/permanent ESI failure: rollback the attempt,
          record the failure against the watched-locations row
          (possibly auto-disabling it), commit *only* the bookkeeping
          update, and move on.

A failure in one location never stops the loop — each has its own
try/except and its own transaction boundary.
"""

from __future__ import annotations

import time
from datetime import datetime, timezone

import pymysql

from market_poller.config import Config, SUPPORTED_LOCATION_TYPES
from market_poller.db import connect, fetch_all
from market_poller.esi import (
    EsiClient,
    PermanentEsiError,
    TransientEsiError,
    client as esi_client,
)
from market_poller.log import get
from market_poller.outbox import emit_orders_snapshot_ingested
from market_poller.persist import (
    insert_orders,
    record_failure,
    record_success,
)


log = get(__name__)


def run(cfg: Config) -> int:
    log.info(
        "market_poller starting",
        dry_run=cfg.dry_run,
        only_location_ids=",".join(str(i) for i in sorted(cfg.only_location_ids)) or "all",
        batch_size=cfg.batch_size,
    )

    polled = 0
    failed = 0
    skipped = 0

    with connect(cfg) as conn:
        with esi_client(cfg) as esi:
            locations = _load_enabled_locations(conn, cfg)
            log.info("locations to poll", count=len(locations))

            for loc in locations:
                outcome = _poll_one(conn, esi, loc, cfg)
                if outcome == "polled":
                    polled += 1
                elif outcome == "failed":
                    failed += 1
                else:
                    skipped += 1

    log.info(
        "market_poller complete",
        polled=polled,
        failed=failed,
        skipped=skipped,
    )
    return 0 if failed == 0 else 1


# -- internals ------------------------------------------------------------


def _load_enabled_locations(
    conn: pymysql.connections.Connection,
    cfg: Config,
) -> list[dict]:
    """Pull the driver-table slice we'll iterate. Ordered by
    last_polled_at asc (nulls first) so freshly-seeded rows get
    picked up in the same pass they were added."""
    sql = """
        SELECT id, location_type, region_id, location_id, name,
               owner_user_id, consecutive_failure_count
          FROM market_watched_locations
         WHERE enabled = 1
    """
    params: tuple = ()
    if cfg.only_location_ids:
        placeholders = ",".join(["%s"] * len(cfg.only_location_ids))
        sql += f" AND location_id IN ({placeholders})"
        params = tuple(cfg.only_location_ids)
    sql += " ORDER BY last_polled_at IS NULL DESC, last_polled_at ASC"
    return fetch_all(conn, sql, params)


def _poll_one(
    conn: pymysql.connections.Connection,
    esi: EsiClient,
    loc: dict,
    cfg: Config,
) -> str:
    """Poll one watched-location row. Returns 'polled' | 'failed' |
    'skipped' for the caller's summary counters."""
    location_type = loc["location_type"]
    if location_type not in SUPPORTED_LOCATION_TYPES:
        log.info(
            "location type not supported yet, skipping",
            watched_location_id=loc["id"],
            location_type=location_type,
            location_id=loc["location_id"],
        )
        return "skipped"

    # One timestamp for every order in this pass. Truncating microseconds
    # keeps the value readable in logs / payloads without losing the
    # precision we need (all rows share it exactly; sub-second resolution
    # only matters for ordering between snapshots, which are minutes
    # apart).
    observed_at = datetime.now(timezone.utc).replace(microsecond=0)
    started = time.monotonic()

    source = _source_string_for(loc)
    log.info(
        "polling location",
        watched_location_id=loc["id"],
        location_type=location_type,
        region_id=loc["region_id"],
        location_id=loc["location_id"],
        source=source,
    )

    try:
        result = insert_orders(
            conn,
            esi.region_orders(int(loc["region_id"])),
            observed_at=observed_at,
            source=source,
            region_id=int(loc["region_id"]),
            filter_location_id=int(loc["location_id"]),
            batch_size=cfg.batch_size,
        )
    except TransientEsiError as exc:
        conn.rollback()
        _handle_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except PermanentEsiError as exc:
        conn.rollback()
        _handle_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except Exception as exc:
        # Defensive: never let an unexpected error abort the whole loop.
        # We treat unknown errors as transient (no auto-disable) so an
        # operator gets the log line and can investigate without a
        # permanently-disabled row to unwind.
        conn.rollback()
        log.exception("unexpected poll error", watched_location_id=loc["id"])
        _handle_failure(conn, loc, cfg, None, f"unexpected: {exc}", observed_at)
        return "failed"

    duration_ms = int((time.monotonic() - started) * 1000)

    if cfg.dry_run:
        conn.rollback()
        log.info(
            "dry-run — rolled back inserts",
            watched_location_id=loc["id"],
            rows_received=result.rows_received,
            rows_inserted=result.rows_inserted,
            duration_ms=duration_ms,
        )
        return "polled"

    # Commit path: bookkeeping + outbox + inserts, all together.
    record_success(conn, int(loc["id"]), observed_at)
    emit_orders_snapshot_ingested(
        conn,
        watched_location_id=int(loc["id"]),
        source=source,
        region_id=int(loc["region_id"]),
        location_id=int(loc["location_id"]),
        location_type=location_type,
        observed_at=observed_at,
        rows_received=result.rows_received,
        rows_inserted=result.rows_inserted,
        duration_ms=duration_ms,
    )
    conn.commit()
    return "polled"


def _handle_failure(
    conn: pymysql.connections.Connection,
    loc: dict,
    cfg: Config,
    status_code: int | None,
    message: str,
    now: datetime,
) -> None:
    """Record a failure on the watched-locations row in its own tiny
    transaction. Separated from the poll rollback above so the failure
    telemetry lands even if the poll transaction is doomed."""
    disabled = record_failure(
        conn,
        int(loc["id"]),
        cfg=cfg,
        status_code=status_code,
        message=message,
        now=now,
    )
    conn.commit()
    log.warning(
        "poll failed",
        watched_location_id=loc["id"],
        location_id=loc["location_id"],
        status_code=status_code,
        auto_disabled=disabled,
        error=message[:200],
    )


def _source_string_for(loc: dict) -> str:
    """Provenance string stamped onto every market_orders row.

    Convention:
      - NPC (region endpoint + location filter):
            esi_region_<region_id>_<location_id>
      - Structure (authed structure endpoint, later rollout step):
            esi_structure_<structure_id>

    The string is human-readable on purpose — it shows up in audit
    queries and grep-a-log-file contexts a lot more often than a
    bare ID."""
    if loc["location_type"] == "npc_station":
        return f"esi_region_{int(loc['region_id'])}_{int(loc['location_id'])}"
    return f"esi_structure_{int(loc['location_id'])}"
