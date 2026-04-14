"""MariaDB connection helper — thin re-skin of sde_importer/db.py.

We open a read-only connection (no transaction needed; we only SELECT
from ref_*) but still tunnel through pymysql for a consistent driver
across the python plane.
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from graph_universe_sync.config import Config
from graph_universe_sync.log import get


log = get(__name__)


@contextmanager
def connect(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    """Yield a MariaDB connection.

    autocommit=True because we only SELECT from ref_* and the outbox
    INSERT (one row) is fine without a wrapping transaction.
    """
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        charset="utf8mb4",
        autocommit=True,
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
    """Convenience wrapper for read-only SELECTs."""
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return list(cur.fetchall())
