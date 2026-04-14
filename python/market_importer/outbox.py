"""Emit `market.history_snapshot_loaded` to the Laravel outbox.

Same producer-tagged pattern as graph_universe_sync and market_poller.
One event per successfully imported day — downstream consumers
(InfluxDB rollups, valuation recomputes) coalesce across events
themselves.

Payload shape (version 1):
    {
      "trade_date":   "2025-01-01",
      "rows_received": 47123,
      "rows_affected": 47123,
      "source":        "everef_market_history",
      "observation_kind": "historical_dump",
      "loaded_at":     "2026-04-14T11:29:37Z"
    }
"""

from __future__ import annotations

import json
from datetime import date, datetime, timezone

import pymysql
from ulid import ULID

from market_importer.log import get


log = get(__name__)


EVENT_TYPE = "market.history_snapshot_loaded"
AGGREGATE_TYPE = "market_history_day"
PRODUCER = "market_importer"
PAYLOAD_VERSION = 1


def emit_history_snapshot_loaded(
    conn: pymysql.connections.Connection,
    *,
    trade_date: date,
    rows_received: int,
    rows_affected: int,
    source: str,
    observation_kind: str,
) -> str:
    event_id = str(ULID())
    loaded_at = datetime.now(timezone.utc).replace(microsecond=0)
    payload = {
        "trade_date": trade_date.isoformat(),
        "rows_received": rows_received,
        "rows_affected": rows_affected,
        "source": source,
        "observation_kind": observation_kind,
        "loaded_at": loaded_at.isoformat().replace("+00:00", "Z"),
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
                trade_date.isoformat(),
                payload_json,
                PRODUCER,
                PAYLOAD_VERSION,
            ),
        )
    log.info(
        "outbox event emitted",
        event_id=event_id,
        event_type=EVENT_TYPE,
        aggregate_id=trade_date.isoformat(),
        rows_affected=rows_affected,
    )
    return event_id
