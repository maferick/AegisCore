"""Project `market.history_snapshot_loaded` events into InfluxDB.

Source: `market_history` rows for the day named in the outbox
payload. Sink: InfluxDB measurement `market_history`, one point per
`(region_id, type_id)` per `trade_date`.

Schema:

    measurement = market_history
    tags        = region_id, type_id
    fields      = average, highest, lowest (decimal as float),
                  volume (int), order_count (int)
    time        = trade_date at 00:00:00 UTC

This is essentially a 1:1 copy from MariaDB to InfluxDB — no
aggregation. The reason to project at all (rather than have
charts query MariaDB directly):

  - InfluxDB's time-series indexes are dramatically faster for the
    "show me Tritanium price in The Forge over 6 months" query
    pattern than MariaDB's row-based PK scan.
  - Decouples the chart-rendering API from the canonical store —
    InfluxDB can be dropped + rebuilt from MariaDB on demand
    (ADR-0003 § InfluxDB).

Cardinality: ~100 regions × ~14k types ≈ 1.4M unique series. Well
within InfluxDB 2.x's millions-of-series ceiling.
"""

from __future__ import annotations

from datetime import date, datetime, time, timezone
from typing import Mapping

import pymysql
from influxdb_client import Point

from outbox_relay.influx import InfluxClient
from outbox_relay.log import _KvLogger as Log


def project(
    read_conn: pymysql.connections.Connection,
    influx: InfluxClient,
    payload: Mapping[str, object],
    log: Log,
) -> int:
    """Project one `market.history_snapshot_loaded` event.

    Returns the number of InfluxDB points written. Raises on any
    error (DB read failure, malformed payload, InfluxDB write
    failure) — the relay catches it and re-queues.
    """
    raw_date = payload.get("trade_date")
    if not isinstance(raw_date, str):
        raise ValueError(f"market.history_snapshot_loaded payload missing trade_date: {payload}")
    try:
        trade_date = date.fromisoformat(raw_date)
    except ValueError as exc:
        raise ValueError(f"trade_date not ISO-8601: {raw_date}") from exc

    rows = _fetch_day(read_conn, trade_date)
    log.info(
        "market_history projection: rows fetched",
        trade_date=trade_date.isoformat(),
        row_count=len(rows),
    )
    if not rows:
        # The day fired an outbox event but the table has no rows
        # for it — usually means the day was rolled back after the
        # outbox event was committed in a separate tx (shouldn't
        # happen given our atomic per-day transaction, but be
        # defensive). Log + zero points written; relay marks it
        # processed.
        log.warning("market_history projection: no rows for day", trade_date=trade_date.isoformat())
        return 0

    point_time = datetime.combine(trade_date, time(0, 0), tzinfo=timezone.utc)
    points = [_row_to_point(row, point_time) for row in rows]
    return influx.write(points)


def _fetch_day(conn: pymysql.connections.Connection, trade_date: date) -> list[dict]:
    """Pull the day's rows from MariaDB. Indexed scan on the partition
    + PK prefix; cheap even for the largest days (~50k rows)."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT region_id, type_id,
                   average, highest, lowest,
                   volume, order_count
              FROM market_history
             WHERE trade_date = %s
            """,
            (trade_date,),
        )
        return list(cur.fetchall())


def _row_to_point(row: dict, point_time: datetime) -> Point:
    """One MariaDB row → one InfluxDB Point. DECIMAL(20,2) values
    cast to float — InfluxDB doesn't carry arbitrary-precision
    decimal, but for chart-rendering use cases the float round-trip
    is fine (we have <1 cent of slop on prices that range up to 9
    quadrillion ISK; the canonical exact value still lives in
    MariaDB)."""
    return (
        Point("market_history")
        .tag("region_id", str(int(row["region_id"])))
        .tag("type_id", str(int(row["type_id"])))
        .field("average", float(row["average"]))
        .field("highest", float(row["highest"]))
        .field("lowest", float(row["lowest"]))
        .field("volume", int(row["volume"]))
        .field("order_count", int(row["order_count"]))
        .time(point_time)
    )
