"""MariaDB connection helper."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from killmail_search.config import Config
from killmail_search.log import get

log = get(__name__)


@contextmanager
def connect(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        charset="utf8mb4",
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
        read_timeout=600,
    )
    log.info("connected to mariadb", host=cfg.db_host)
    try:
        yield conn
    finally:
        conn.close()
