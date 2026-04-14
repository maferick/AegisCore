"""MariaDB connection helper for outbox_relay.

Two distinct connections per process:

  - **Outbox connection** (`autocommit=False`): claims rows under
    `SELECT ... FOR UPDATE SKIP LOCKED`, dispatches each to a
    projector, marks `processed_at` (or bumps `attempts` + sets
    `last_error`), commits per batch.
  - **Read connection** (`autocommit=True`): used by projectors to
    read `market_history` / `market_orders` rows referenced in the
    outbox payload. Independent of the claim transaction so a
    long-running projector query doesn't hold the outbox row lock.

The two-connection pattern matters because pymysql is single-
threaded per connection — running the projector's SELECT on the
same connection as the outbox claim would mean we either hold the
row lock for the whole projector run (blocking parallel relays
unnecessarily) or the projector can't read while the claim
transaction is open. Two connections sidestep both.
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from outbox_relay.config import Config
from outbox_relay.log import get


log = get(__name__)


@contextmanager
def connect_outbox(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    """Outbox-claim connection. autocommit=False; caller owns
    BEGIN/COMMIT/ROLLBACK per batch via conn.commit() / rollback()."""
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
    log.info("connected to mariadb (outbox)", host=cfg.db_host, database=cfg.db_database)
    try:
        yield conn
    finally:
        conn.close()


@contextmanager
def connect_reads(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    """Projector-read connection. autocommit=True — projector queries
    are read-only against committed rows, no transaction needed."""
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
    log.info("connected to mariadb (reads)", host=cfg.db_host, database=cfg.db_database)
    try:
        yield conn
    finally:
        conn.close()
