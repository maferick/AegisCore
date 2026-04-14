"""Emit `market.orders_snapshot_ingested` to the Laravel outbox.

Schema follows app/database/migrations/2026_04_13_000000_create_outbox_table.php
(same shape as graph_universe_sync's outbox emitter, different
event_type / aggregate_type / payload).

Producer is pinned to "market_poller" so consumers can filter by
origin. One outbox row per successful location poll — downstream
consumers (InfluxDB rollups, valuation recomputes) can coalesce
across rows themselves; writing one event per poll keeps the
producer side dumb and makes audit queries trivial.

Payload shape (version 1):
    {
      "source": "esi_region_10000002_60003760",
      "region_id": 10000002,
      "location_id": 60003760,
      "location_type": "npc_station",
      "observed_at": "2026-04-14T11:29:37Z",
      "rows_received": 154231,
      "rows_inserted": 9874,
      "duration_ms": 4821
    }

`rows_received` is orders seen across all ESI pages; `rows_inserted`
is the subset that passed the location filter AND wasn't a duplicate
against an existing (observed_at, source, location_id, order_id) row.
A large delta between the two for an NPC row means the region-wide
fetch had lots of orders from other stations we discarded — that's
normal for minor hubs and the log serves as an efficiency hint for
future "poll multiple co-region hubs in one fetch" work.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone

import pymysql
from ulid import ULID

from market_poller.log import get


log = get(__name__)


EVENT_TYPE = "market.orders_snapshot_ingested"
AGGREGATE_TYPE = "market_watched_location"
PRODUCER = "market_poller"
PAYLOAD_VERSION = 1


def emit_orders_snapshot_ingested(
    conn: pymysql.connections.Connection,
    *,
    watched_location_id: int,
    source: str,
    region_id: int,
    location_id: int,
    location_type: str,
    observed_at: datetime,
    rows_received: int,
    rows_inserted: int,
    duration_ms: int,
) -> str:
    """Insert one outbox row; returns the event_id (ULID) for logging.

    Called inside the same transaction as the market_orders inserts
    + the watched-locations UPDATE — the runner owns the commit."""
    event_id = str(ULID())
    observed_iso = _as_utc(observed_at).isoformat(timespec="seconds")
    # Replace +00:00 with Z so the payload is visually consistent with
    # CCP-style timestamps. The consumer doesn't care either way.
    observed_iso = observed_iso.replace("+00:00", "Z")

    payload = {
        "source": source,
        "region_id": region_id,
        "location_id": location_id,
        "location_type": location_type,
        "observed_at": observed_iso,
        "rows_received": rows_received,
        "rows_inserted": rows_inserted,
        "duration_ms": duration_ms,
    }
    payload_json = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO outbox
                (event_id, event_type, aggregate_type, aggregate_id,
                 payload, producer, version)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            """,
            (
                event_id,
                EVENT_TYPE,
                AGGREGATE_TYPE,
                str(watched_location_id),
                payload_json,
                PRODUCER,
                PAYLOAD_VERSION,
            ),
        )
    log.info(
        "outbox event emitted",
        event_id=event_id,
        event_type=EVENT_TYPE,
        aggregate_id=watched_location_id,
        source=source,
        rows_inserted=rows_inserted,
    )
    return event_id


def _as_utc(dt: datetime) -> datetime:
    """Force a naive datetime into UTC; pass aware datetimes through."""
    if dt.tzinfo is None:
        return dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)
