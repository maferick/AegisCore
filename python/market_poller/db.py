"""MariaDB connection helper for market_poller.

We open ONE connection and drive per-location transactions explicitly
(autocommit=False). Each location's poll is atomic: all order rows +
the outbox event commit together, or neither does. A failure inside a
location doesn't leak half-written rows or a dangling outbox event.
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from market_poller.config import Config
from market_poller.log import get


log = get(__name__)


@contextmanager
def connect(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    """Yield a MariaDB connection with autocommit disabled.

    Callers explicitly BEGIN/COMMIT per location via conn.commit() /
    conn.rollback(). The outer context manager still closes the
    connection on scope exit regardless of how the caller resolved
    their transactions.
    """
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
        read_timeout=300,
        write_timeout=300,
    )
    log.info("connected to mariadb", host=cfg.db_host, database=cfg.db_database)
    try:
        yield conn
    finally:
        conn.close()


def fetch_all(conn: pymysql.connections.Connection, sql: str, params: tuple = ()) -> list[dict]:
    """Convenience wrapper for read-only SELECTs. Does not begin a tx."""
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return list(cur.fetchall())
