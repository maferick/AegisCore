"""Project `market.orders_snapshot_ingested` events into InfluxDB.

Source: `market_orders` rows for the snapshot named in the outbox
payload (one source-string + location_id at the named observed_at).
Sink: InfluxDB measurement `market_orderbook`, one point per
`(type_id, is_buy)` per snapshot — i.e. AGGREGATES, not individual
orders.

Why aggregates and not raw order points:

  - A Jita snapshot is ~150k orders. Writing each as a point would
    push tag cardinality past sane limits (each `order_id` would
    become a unique series, never queried again, just bloating
    Influx's tsi index forever).
  - Order-level analysis is best done against the canonical
    MariaDB rows; InfluxDB's job is "give me the orderbook
    summary over time" — top-of-book, depth, spread.

Schema:

    measurement = market_orderbook
    tags        = region_id, location_id, type_id, side ("buy"|"sell")
    fields      = best_price (float),         # MAX(price) for buy, MIN for sell
                  weighted_avg_price (float), # SUM(price*volume_remain)/SUM(volume_remain)
                  total_volume_remain (int),  # SUM(volume_remain)
                  order_count (int)           # COUNT(*)
    time        = observed_at

Cardinality per snapshot: ~10k distinct types × 2 sides ≈ 20k
unique series per location. With Jita as the only watched location
in phase 1, that's well within InfluxDB's comfort zone. Adding more
structures multiplies linearly — still fine until you're tracking
hundreds of locations.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Mapping

import pymysql
from influxdb_client import Point

from outbox_relay.influx import InfluxClient
from outbox_relay.log import _KvLogger as Log


@dataclass
class _SideAgg:
    """Running aggregate for one (type_id, is_buy) bucket as we
    iterate a snapshot's orders. Keep state minimal — just what we
    need to compute the four output fields at flush time."""
    best_price: float = 0.0  # MAX for buy, MIN for sell — initialised on first row
    seen_first: bool = False
    weighted_price_sum: float = 0.0  # Σ(price × volume_remain)
    volume_sum: int = 0              # Σ(volume_remain)
    order_count: int = 0


def project(
    read_conn: pymysql.connections.Connection,
    influx: InfluxClient,
    payload: Mapping[str, object],
    log: Log,
) -> int:
    observed_at_raw = payload.get("observed_at")
    source = payload.get("source")
    location_id = payload.get("location_id")
    region_id = payload.get("region_id")
    if not isinstance(observed_at_raw, str) or not isinstance(source, str):
        raise ValueError(
            f"market.orders_snapshot_ingested payload missing observed_at/source: {payload}"
        )
    if not isinstance(location_id, int) or not isinstance(region_id, int):
        raise ValueError(
            f"market.orders_snapshot_ingested payload missing location_id/region_id: {payload}"
        )

    observed_at = _parse_iso8601(observed_at_raw)

    rows = _fetch_snapshot(read_conn, observed_at, source, location_id)
    log.info(
        "market_orderbook projection: rows fetched",
        observed_at=observed_at.isoformat(),
        source=source,
        row_count=len(rows),
    )
    if not rows:
        log.warning(
            "market_orderbook projection: no rows for snapshot",
            observed_at=observed_at.isoformat(),
            source=source,
        )
        return 0

    # Aggregate per (type_id, is_buy). Streaming pass — we'd rather
    # keep memory bounded for big Jita-sized snapshots than load
    # all rows into a DataFrame.
    buckets: dict[tuple[int, bool], _SideAgg] = defaultdict(_SideAgg)
    for row in rows:
        type_id = int(row["type_id"])
        is_buy = bool(row["is_buy"])
        price = float(row["price"])
        volume = int(row["volume_remain"])
        agg = buckets[(type_id, is_buy)]

        if not agg.seen_first:
            agg.best_price = price
            agg.seen_first = True
        elif is_buy and price > agg.best_price:
            agg.best_price = price
        elif (not is_buy) and price < agg.best_price:
            agg.best_price = price

        agg.weighted_price_sum += price * volume
        agg.volume_sum += volume
        agg.order_count += 1

    points = [
        _agg_to_point(
            type_id=type_id,
            is_buy=is_buy,
            agg=agg,
            region_id=region_id,
            location_id=location_id,
            observed_at=observed_at,
        )
        for (type_id, is_buy), agg in buckets.items()
    ]
    return influx.write(points)


def _fetch_snapshot(
    conn: pymysql.connections.Connection,
    observed_at: datetime,
    source: str,
    location_id: int,
) -> list[dict]:
    """Pull the snapshot's orders. Hits the
    `idx_market_orders_location_type` secondary index plus the
    partition prefix on `observed_at`, so it stays O(snapshot size)
    even as the table grows."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT type_id, is_buy, price, volume_remain
              FROM market_orders
             WHERE observed_at = %s
               AND source      = %s
               AND location_id = %s
            """,
            (observed_at.replace(tzinfo=None), source, location_id),
        )
        return list(cur.fetchall())


def _agg_to_point(
    *,
    type_id: int,
    is_buy: bool,
    agg: _SideAgg,
    region_id: int,
    location_id: int,
    observed_at: datetime,
) -> Point:
    weighted_avg = (
        agg.weighted_price_sum / agg.volume_sum if agg.volume_sum > 0 else agg.best_price
    )
    return (
        Point("market_orderbook")
        .tag("region_id", str(region_id))
        .tag("location_id", str(location_id))
        .tag("type_id", str(type_id))
        # tags must be strings — "buy"/"sell" reads better in
        # Influx's data-explorer UI than 1/0.
        .tag("side", "buy" if is_buy else "sell")
        .field("best_price", float(agg.best_price))
        .field("weighted_avg_price", float(weighted_avg))
        .field("total_volume_remain", int(agg.volume_sum))
        .field("order_count", int(agg.order_count))
        .time(observed_at)
    )


def _parse_iso8601(raw: str) -> datetime:
    """Normalise the trailing `Z` form CCP / our outbox emit. The
    market_poller writes `observed_at` via `.isoformat()` then a
    `+00:00` → `Z` substitution, so reading back is the inverse."""
    s = raw
    if s.endswith("Z"):
        s = s[:-1] + "+00:00"
    dt = datetime.fromisoformat(s)
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)
