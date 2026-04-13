"""MariaDB connection helpers."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from sde_importer.config import Config
from sde_importer.log import get

log = get(__name__)


@contextmanager
def connect(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    """Yield a MariaDB connection with sane defaults for bulk loading.

    - autocommit=False — the whole SDE load wants to be one transaction
      (per ADR-0001 §4).
    - local_infile=False — we don't need LOAD DATA; batched INSERTs are
      plenty at ~700k total rows and reading from a file would need extra
      server config on the MariaDB side.
    - charset=utf8mb4 — EVE names include CJK + accented Latin. ASCII
      breakage would be a fun debugging story we don't need.
    """
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.Cursor,
        # Big packets so large INSERT VALUES (...) batches go through.
        # Server-side max_allowed_packet must be >= this.
        read_timeout=300,
        write_timeout=300,
    )
    log.info("connected to mariadb", host=cfg.db_host, database=cfg.db_database)
    try:
        yield conn
    finally:
        conn.close()
