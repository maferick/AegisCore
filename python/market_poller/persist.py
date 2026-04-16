"""Bulk-insert market_orders + watched-locations bookkeeping.

Two responsibilities:

  1. `insert_orders` — batched `INSERT IGNORE` into `market_orders`.
     `IGNORE` rather than `ON DUPLICATE KEY UPDATE` because the PK is
     `(observed_at, source, location_id, order_id)` — a collision only
     happens if the same snapshot is re-applied (poll retry inside
     one `observed_at` instant), in which case re-writing the payload
     would be a no-op by construction. IGNORE is faster and avoids
     locking rows we have no intent to mutate.

  2. `record_success` / `record_failure` — update the
     `market_watched_locations` row with the poll outcome. Success
     zeroes the failure counter; failure increments it and may
     auto-disable the row per ADR-0004 § Failure handling.

Neither function commits — the runner owns the transaction boundary
around "insert all rows + update bookkeeping + emit outbox event" so
a crash in the middle rolls the whole poll back.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from typing import Iterable

import pymysql

from market_poller.config import Config
from market_poller.esi import RawOrder
from market_poller.log import get


log = get(__name__)


@dataclass(frozen=True)
class PersistResult:
    rows_received: int
    rows_inserted: int  # MariaDB's affected-rows count (IGNOREs don't count).


def insert_orders(
    conn: pymysql.connections.Connection,
    orders: Iterable[RawOrder],
    *,
    observed_at: datetime,
    source: str,
    region_id: int,
    filter_location_id: int | None,
    batch_size: int,
) -> PersistResult:
    """Stream `orders`, batch-insert matching rows into market_orders.

    `filter_location_id` is set for NPC stations (we fetch the whole
    region but keep only the target location's orders); `None` means
    "keep everything" (structure endpoints are already
    location-specific and come through a different code path later).
    """
    received = 0
    inserted = 0
    batch: list[tuple] = []
    now_observed_at = observed_at

    for o in orders:
        received += 1
        if filter_location_id is not None and o.location_id != filter_location_id:
            continue

        batch.append((
            now_observed_at,
            source,
            o.location_id,
            o.order_id,
            region_id,
            o.type_id,
            1 if o.is_buy_order else 0,
            o.price,
            o.volume_remain,
            o.volume_total,
            o.min_volume,
            o.range,
            o.duration,
            _parse_iso8601(o.issued),
            "snapshot",
        ))

        if len(batch) >= batch_size:
            inserted += _flush(conn, batch)
            batch.clear()

    if batch:
        inserted += _flush(conn, batch)

    log.info(
        "market_orders batch persisted",
        source=source,
        region_id=region_id,
        location_id=filter_location_id,
        rows_received=received,
        rows_inserted=inserted,
    )
    return PersistResult(rows_received=received, rows_inserted=inserted)


def _flush(conn: pymysql.connections.Connection, batch: list[tuple]) -> int:
    sql = """
        INSERT IGNORE INTO market_orders
            (observed_at, source, location_id, order_id,
             region_id, type_id, is_buy, price,
             volume_remain, volume_total, min_volume, `range`,
             duration, issued_at, observation_kind)
        VALUES
            (%s, %s, %s, %s,
             %s, %s, %s, %s,
             %s, %s, %s, %s,
             %s, %s, %s)
    """
    with conn.cursor() as cur:
        cur.executemany(sql, batch)
        return cur.rowcount


def _parse_iso8601(raw: str) -> datetime:
    """ESI issues timestamps as ISO-8601 with trailing `Z`. Python's
    `datetime.fromisoformat` handles most of that but rejects the
    trailing `Z` before 3.11 — we're on 3.12 so it's fine, but
    normalising defensively keeps the hot path free of version-sniff
    branches."""
    if raw.endswith("Z"):
        raw = raw[:-1] + "+00:00"
    return datetime.fromisoformat(raw)


# -- watched-locations bookkeeping ----------------------------------------


def record_success(
    conn: pymysql.connections.Connection,
    watched_location_id: int,
    last_polled_at: datetime,
) -> None:
    """Mark a successful poll: last_polled_at bumped, failure counter
    zeroed, last_error cleared."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_watched_locations
               SET last_polled_at            = %s,
                   consecutive_failure_count = 0,
                   last_error                = NULL,
                   last_error_at             = NULL,
                   updated_at                = %s
             WHERE id = %s
            """,
            (last_polled_at, last_polled_at, watched_location_id),
        )


def record_failure(
    conn: pymysql.connections.Connection,
    watched_location_id: int,
    *,
    cfg: Config,
    status_code: int | None,
    message: str,
    now: datetime,
) -> bool:
    """Record a routine failure. Returns True if this failure tripped
    the auto-disable threshold (caller logs the transition).

    Security-boundary failures (ownership mismatch, missing scope) use
    `disable_immediately` instead — no grace counter for those."""
    # Bump counter + capture the error. We read the new value back so
    # the threshold check is a single round-trip-per-update regardless
    # of how the counter drifted under concurrent access (which it
    # shouldn't, but defensive is cheap).
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_watched_locations
               SET consecutive_failure_count = consecutive_failure_count + 1,
                   last_error                = %s,
                   last_error_at             = %s,
                   updated_at                = %s
             WHERE id = %s
            """,
            (message[:2000], now, now, watched_location_id),
        )
        cur.execute(
            "SELECT consecutive_failure_count FROM market_watched_locations WHERE id = %s",
            (watched_location_id,),
        )
        row = cur.fetchone()
        count = int(row["consecutive_failure_count"]) if row else 0

    threshold_hit = False
    disabled_reason: str | None = None
    if status_code == 403 and count >= cfg.max_consecutive_403s:
        threshold_hit = True
        disabled_reason = "no_access"
    elif status_code is not None and 500 <= status_code < 600 and count >= cfg.max_consecutive_5xx:
        threshold_hit = True
        disabled_reason = "upstream_failing"
    elif status_code is None and count >= cfg.max_consecutive_5xx:
        # Network / timeout class — budget with the 5xx threshold.
        threshold_hit = True
        disabled_reason = "upstream_unreachable"

    if threshold_hit:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE market_watched_locations
                   SET enabled         = 0,
                       disabled_reason = %s,
                       updated_at      = %s
                 WHERE id = %s
                """,
                (disabled_reason, now, watched_location_id),
            )
    return threshold_hit


def disable_immediately(
    conn: pymysql.connections.Connection,
    watched_location_id: int,
    *,
    reason: str,
    message: str,
    now: datetime,
) -> None:
    """Security-violation path (token ownership mismatch, missing scope).
    No grace counter — flip enabled = false now and log the transition.

    Phase 1 has no auth'd structure polling yet, so this is unused today;
    it's here so the runner's branches are symmetric and the code path
    exists when admin/donor structure polling lands in later rollout
    steps."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_watched_locations
               SET enabled         = 0,
                   disabled_reason = %s,
                   last_error      = %s,
                   last_error_at   = %s,
                   updated_at      = %s
             WHERE id = %s
            """,
            (reason, message[:2000], now, now, watched_location_id),
        )


# -- market_hub_collectors bookkeeping (ADR-0005) --------------------------
#
# ADR-0005 moves per-token failure counters + disable thresholds from
# market_watched_locations onto market_hub_collectors, because a hub with
# multiple collectors needs per-collector state to drive failover: a
# hub-wide counter would lie when the poller alternates between a dead
# primary and a live backup. The watched-locations row still tracks
# `last_polled_at` for scheduler ordering; failure / disable lives here.


def record_collector_success(
    conn: pymysql.connections.Connection,
    collector_id: int,
    now: datetime,
) -> None:
    """Mark a successful poll on this collector. Clears failure state
    so a transient bad stretch doesn't linger into the next outage."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_hub_collectors
               SET last_success_at            = %s,
                   last_failure_at            = NULL,
                   failure_reason             = NULL,
                   consecutive_failure_count  = 0,
                   updated_at                 = %s
             WHERE id = %s
            """,
            (now, now, collector_id),
        )


def record_collector_failure(
    conn: pymysql.connections.Connection,
    collector_id: int,
    *,
    cfg: Config,
    status_code: int | None,
    message: str,
    now: datetime,
) -> bool:
    """Bump the collector's failure counter and flip `is_active = false`
    once the tier-appropriate threshold is crossed. Returns True if the
    threshold tripped so the caller can log the transition.

    Uses the same 3-for-403 / 5-for-5xx tiering as
    `record_failure()` on watched-locations — only the storage location
    moves. Operators tuning the threshold via env vars keep a single
    knob for both surfaces."""
    short_reason = _collector_failure_reason(status_code)
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_hub_collectors
               SET consecutive_failure_count = consecutive_failure_count + 1,
                   last_failure_at           = %s,
                   failure_reason            = %s,
                   updated_at                = %s
             WHERE id = %s
            """,
            (now, (short_reason + ": " + message)[:255], now, collector_id),
        )
        cur.execute(
            "SELECT consecutive_failure_count FROM market_hub_collectors WHERE id = %s",
            (collector_id,),
        )
        row = cur.fetchone()
        count = int(row["consecutive_failure_count"]) if row else 0

    threshold_hit = False
    if status_code == 403 and count >= cfg.max_consecutive_403s:
        threshold_hit = True
    elif status_code is not None and 500 <= status_code < 600 and count >= cfg.max_consecutive_5xx:
        threshold_hit = True
    elif status_code is None and count >= cfg.max_consecutive_5xx:
        # Network / timeout class — budget with the 5xx threshold.
        threshold_hit = True

    if threshold_hit:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE market_hub_collectors
                   SET is_active  = 0,
                       updated_at = %s
                 WHERE id = %s
                """,
                (now, collector_id),
            )
    return threshold_hit


def disable_collector_immediately(
    conn: pymysql.connections.Connection,
    collector_id: int,
    *,
    reason: str,
    message: str,
    now: datetime,
) -> None:
    """Security-boundary disable — scope missing, ownership mismatch.
    No grace counter. Mirrors `disable_immediately` on watched-locations
    but writes to the collector row instead."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_hub_collectors
               SET is_active      = 0,
                   last_failure_at = %s,
                   failure_reason  = %s,
                   updated_at      = %s
             WHERE id = %s
            """,
            (now, (reason + ": " + message)[:255], now, collector_id),
        )


def record_hub_sync_success(
    conn: pymysql.connections.Connection,
    hub_id: int,
    character_id: int | None,
    now: datetime,
) -> None:
    """Post-success hub-level bookkeeping. Called on every successful
    poll, public-reference or private:

      - Always bump `last_sync_at` (per the migration header: "most
        recent successful poll against this hub, regardless of which
        collector did it").
      - Always clear `disabled_reason` — if the hub was previously
        frozen (e.g. `'no_active_collector'`) a success here means
        it's alive again.
      - Update `active_collector_character_id` only when a collector
        is involved (private hub). Public-reference hubs are polled
        by the service token or unauth'd region endpoint; they
        legitimately have NULL here and we must not overwrite it with
        stale state from a previous private-hub reuse of the same
        hub_id (can't happen today, but the invariant is cheap).
    """
    if character_id is None:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE market_hubs
                   SET last_sync_at    = %s,
                       disabled_reason = NULL,
                       updated_at      = %s
                 WHERE id = %s
                """,
                (now, now, hub_id),
            )
        return
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_hubs
               SET active_collector_character_id = %s,
                   last_sync_at                  = %s,
                   disabled_reason               = NULL,
                   updated_at                    = %s
             WHERE id = %s
            """,
            (character_id, now, now, hub_id),
        )


def freeze_hub_no_collectors(
    conn: pymysql.connections.Connection,
    hub_id: int,
    now: datetime,
) -> None:
    """All collectors for this hub are inactive. Stamp
    `disabled_reason = 'no_active_collector'` on the hub so the next
    tick's SELECT skips it. Per ADR-0005 we deliberately do NOT flip
    `market_hubs.is_active` — an admin / donor re-auth can rescue the
    hub without an operator first flipping that bit."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE market_hubs
               SET disabled_reason                = 'no_active_collector',
                   active_collector_character_id  = NULL,
                   updated_at                     = %s
             WHERE id = %s
            """,
            (now, hub_id),
        )


def _collector_failure_reason(status_code: int | None) -> str:
    """Short label stored in market_hub_collectors.failure_reason.
    Values match the vocabulary ADR-0005's header comment lists."""
    if status_code == 403:
        return "revoked_access"
    if status_code is not None and 500 <= status_code < 600:
        return "5xx_timeout"
    if status_code is None:
        return "5xx_timeout"
    return f"http_{status_code}"
