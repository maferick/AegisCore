"""Bulk upsert into `market_history`.

`INSERT ... ON DUPLICATE KEY UPDATE` on the PK
`(trade_date, region_id, type_id)`. Upsert rather than INSERT IGNORE
because EVE Ref re-publishes partial days as more ESI data becomes
available — the counts and http_last_modified can change for an
already-loaded (date, region_id, type_id) as the day completes. We
want the latest values to win, and the PK guarantees it's the same
logical row.

All rows for one day land inside one transaction, committed by the
runner. This keeps "a day either loaded or not loaded" atomic: if a
CSV turns out to be corrupt mid-stream, the partial day rolls back
and the next run re-attempts from scratch.
"""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Iterable

import pymysql

from market_importer.log import get
from market_importer.parse import HistoryRow


log = get(__name__)


_SQL = """
    INSERT INTO market_history
        (trade_date, region_id, type_id,
         average, highest, lowest,
         volume, order_count, http_last_modified,
         source, observation_kind,
         created_at, updated_at)
    VALUES
        (%s, %s, %s,
         %s, %s, %s,
         %s, %s, %s,
         %s, %s,
         %s, %s)
    ON DUPLICATE KEY UPDATE
        average            = VALUES(average),
        highest            = VALUES(highest),
        lowest             = VALUES(lowest),
        volume             = VALUES(volume),
        order_count        = VALUES(order_count),
        http_last_modified = VALUES(http_last_modified),
        source             = VALUES(source),
        observation_kind   = VALUES(observation_kind),
        updated_at         = VALUES(updated_at)
"""


def upsert_rows(
    conn: pymysql.connections.Connection,
    rows: Iterable[HistoryRow],
    *,
    source: str,
    observation_kind: str,
    batch_size: int,
) -> tuple[int, int]:
    """Stream `rows`, batch-upsert into market_history. Returns
    `(rows_received, rows_affected)`.

    `rows_affected` is MariaDB's raw `cur.rowcount`, which under
    ON DUPLICATE KEY UPDATE counts 1 per new insert and 2 per row
    that actually updated an existing one (MariaDB/MySQL quirk) —
    useful as a sanity number in logs + outbox, but not a clean
    "inserted vs updated" split. Callers treat it as "at least this
    much changed".
    """
    now = datetime.now(timezone.utc).replace(microsecond=0)
    received = 0
    affected = 0
    batch: list[tuple] = []

    for row in rows:
        received += 1
        batch.append((
            row.trade_date,
            row.region_id,
            row.type_id,
            row.average,
            row.highest,
            row.lowest,
            row.volume,
            row.order_count,
            row.http_last_modified,
            source,
            observation_kind,
            now,
            now,
        ))
        if len(batch) >= batch_size:
            affected += _flush(conn, batch)
            batch.clear()

    if batch:
        affected += _flush(conn, batch)

    return received, affected


def _flush(conn: pymysql.connections.Connection, batch: list[tuple]) -> int:
    with conn.cursor() as cur:
        cur.executemany(_SQL, batch)
        return cur.rowcount
