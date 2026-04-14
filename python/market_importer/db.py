"""MariaDB connection helper for market_importer.

Per-day transactions (autocommit=False). Each day commits or rolls
back as one unit — if a day's CSV is corrupt mid-stream, we don't
leave a half-populated day.
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from market_importer.config import Config
from market_importer.log import get


log = get(__name__)


@contextmanager
def connect(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    """Yield a MariaDB connection with autocommit disabled.

    The runner owns BEGIN/COMMIT/ROLLBACK per day via conn.commit() /
    conn.rollback(). The outer context manager closes the connection
    on scope exit regardless of how the caller resolved the last
    transaction.
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
        read_timeout=600,
        write_timeout=600,
    )
    log.info("connected to mariadb", host=cfg.db_host, database=cfg.db_database)
    try:
        yield conn
    finally:
        conn.close()


def fetch_all(conn: pymysql.connections.Connection, sql: str, params: tuple = ()) -> list[dict]:
    """Read-only SELECT convenience. Does not begin a tx."""
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return list(cur.fetchall())
