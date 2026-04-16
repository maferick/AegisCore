"""MariaDB connection helper for killmail_ingest."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from killmail_ingest.config import Config
from killmail_ingest.log import get


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
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return list(cur.fetchall())
