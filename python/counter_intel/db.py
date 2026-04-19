"""Thin pymysql connection wrapper."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from counter_intel.config import Config


@contextmanager
def connection(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
        charset="utf8mb4",
        connect_timeout=30,
    )
    try:
        yield conn
    finally:
        conn.close()
