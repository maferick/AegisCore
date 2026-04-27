"""Emergency market_orders → market_order_daily_aggregates roller.

Lives in counter_intel for now (only Python module wired up to a
make target via the counter_intel image). Owns the aggregation
contract; not part of any phase-numbered intel pipeline.

Strategy:
  one (date, region) → one INSERT ... SELECT ... GROUP BY ...
  ON DUPLICATE KEY UPDATE.

For each (date, region) batch we time the query, log row counts,
commit, and move on. Idempotent: re-running re-aggregates the
same (date, region) and the upsert overwrites with the latest
metrics.

Caller responsibilities:
  - call run_backfill(conn, ...) with a date range + optional
    region filter
  - the caller decides which days are safe to fold (today's
    in-progress data should be excluded by passing
    end_date_exclusive)
"""

from __future__ import annotations

import time
from datetime import date, timedelta
from typing import Optional

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.market_order_aggregator")


_AGG_SQL = """
INSERT INTO market_order_daily_aggregates
  (observed_date, region_id, location_id, type_id, is_buy,
   min_price, max_price, avg_price, weighted_avg_price, best_price,
   order_count, unique_order_count, total_volume_remain,
   first_seen_at, last_seen_at)
SELECT
  DATE(observed_at) AS observed_date,
  region_id, location_id, type_id, is_buy,
  MIN(price), MAX(price), AVG(price),
  COALESCE(SUM(price * volume_remain) / NULLIF(SUM(volume_remain), 0), AVG(price)),
  CASE WHEN is_buy = 1 THEN MAX(price) ELSE MIN(price) END,
  COUNT(*),
  COUNT(DISTINCT order_id),
  SUM(volume_remain),
  MIN(observed_at), MAX(observed_at)
  FROM market_orders
 WHERE observed_at >= %s
   AND observed_at <  %s
   AND region_id    =  %s
 GROUP BY observed_date, region_id, location_id, type_id, is_buy
ON DUPLICATE KEY UPDATE
  min_price = VALUES(min_price),
  max_price = VALUES(max_price),
  avg_price = VALUES(avg_price),
  weighted_avg_price = VALUES(weighted_avg_price),
  best_price = VALUES(best_price),
  order_count = VALUES(order_count),
  unique_order_count = VALUES(unique_order_count),
  total_volume_remain = VALUES(total_volume_remain),
  first_seen_at = VALUES(first_seen_at),
  last_seen_at = VALUES(last_seen_at),
  updated_at = NOW()
"""


def discover_regions(conn: pymysql.connections.Connection, day_start, day_end) -> list[int]:
    """Return distinct region_ids that have rows in the date window."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT DISTINCT region_id FROM market_orders
             WHERE observed_at >= %s AND observed_at < %s
            """,
            (day_start, day_end),
        )
        return [int(r["region_id"]) for r in cur.fetchall()]


def run_backfill(
    conn: pymysql.connections.Connection,
    cfg: Config,
    *,
    start_date: date,
    end_date_exclusive: date,
    region_ids: Optional[list[int]] = None,
) -> dict:
    """Aggregate (date, region) pairs across the given window.

    region_ids: when None, walks every region with data on each day
    (auto-discovered per-day). When supplied, restricts to that list.
    """
    log.info("market_order_aggregator backfill starting",
             {"start": start_date.isoformat(),
              "end_exclusive": end_date_exclusive.isoformat(),
              "regions": region_ids if region_ids else "auto"})

    total_inserted = 0
    total_batches = 0
    total_seconds = 0.0
    by_day: dict[str, dict] = {}

    cur_day = start_date
    while cur_day < end_date_exclusive:
        day_start = f"{cur_day.isoformat()} 00:00:00"
        day_end = f"{(cur_day + timedelta(days=1)).isoformat()} 00:00:00"

        regions = region_ids if region_ids is not None else discover_regions(
            conn, day_start, day_end)

        if not regions:
            log.info("market_order_aggregator empty day", {"date": cur_day.isoformat()})
            cur_day += timedelta(days=1)
            continue

        day_inserted = 0
        day_batches = 0
        day_seconds = 0.0
        for region_id in regions:
            t0 = time.time()
            with conn.cursor() as c:
                c.execute(_AGG_SQL, (day_start, day_end, region_id))
                affected = c.rowcount
            conn.commit()
            elapsed = time.time() - t0
            day_inserted += max(0, affected)
            day_batches += 1
            day_seconds += elapsed
            log.info("aggregator batch",
                     {"date": cur_day.isoformat(),
                      "region_id": region_id,
                      "rows_affected": affected,
                      "duration_ms": int(elapsed * 1000)})

        by_day[cur_day.isoformat()] = {
            "inserted_or_updated": day_inserted,
            "batches": day_batches,
            "duration_seconds": round(day_seconds, 2),
        }
        total_inserted += day_inserted
        total_batches += day_batches
        total_seconds += day_seconds
        cur_day += timedelta(days=1)

    log.info("market_order_aggregator backfill complete",
             {"days": len(by_day),
              "batches": total_batches,
              "rows_affected": total_inserted,
              "total_seconds": round(total_seconds, 1)})
    return {
        "days": len(by_day),
        "batches": total_batches,
        "rows_affected": total_inserted,
        "total_seconds": round(total_seconds, 1),
        "by_day": by_day,
    }
