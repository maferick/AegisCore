"""MariaDB connection for theater_clustering. Thin wrapper around pymysql
matching the style used by killmail_ingest / market_poller."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from theater_clustering.config import Config


def connect(cfg: Config) -> pymysql.connections.Connection:
    return pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )


@contextmanager
def connection(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    conn = connect(cfg)
    try:
        yield conn
    finally:
        try:
            conn.close()
        except Exception:
            pass
