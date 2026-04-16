"""MariaDB connection helper for killmail_ingest."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from killmail_ingest.config import Config
from killmail_ingest.log import get


log = get(__name__)


# pymysql error codes that indicate a dead connection — safe to recover
# by closing and opening a fresh one. The raw int 0 is pymysql's way of
# reporting a socket-level "connection gone away" with no server errno
# (the classic `(0, '')` tuple seen in logs).
#
# - 0    → socket closed / empty packet from server
# - 2006 → MySQL server has gone away (wait_timeout / server restart)
# - 2013 → Lost connection during query
# - 2014 → Commands out of sync (usually follows a dropped connection)
# - 2055 → Lost connection (network-level) — newer driver codepath
_CONNECTION_LOST_CODES = frozenset({0, 2006, 2013, 2014, 2055})

# InnoDB errnos that mean "server chose to abort our tx, connection is
# fine, retry might succeed".
#
# - 1213 → Deadlock found; transaction rolled back.
# - 1205 → Lock wait timeout exceeded; statement rolled back (the
#          surrounding tx is usually NOT auto-rolled-back by the server,
#          but we roll back ourselves before retrying to keep the state
#          clean).
_RETRYABLE_SERVER_ABORT_CODES = frozenset({1213, 1205})


def is_connection_lost(exc: BaseException) -> bool:
    """True if `exc` indicates a dead pymysql connection and the caller
    should close + reconnect before continuing. Matches on both the
    structured errno (most paths) and the `(0, '')` tuple signature
    (rare fallthrough where pymysql returns that instead of a proper
    OperationalError object).
    """
    if isinstance(exc, pymysql.err.OperationalError) and exc.args:
        return exc.args[0] in _CONNECTION_LOST_CODES
    if hasattr(exc, "args") and exc.args:
        first = exc.args[0]
        if isinstance(first, int) and first in _CONNECTION_LOST_CODES:
            return True
    msg = str(exc)
    return "Lost connection" in msg or "(0, '')" in msg


def is_retryable_server_abort(exc: BaseException) -> bool:
    """True if `exc` is a deadlock (1213) or lock-wait timeout (1205).
    The connection is still usable; the transaction was rolled back
    (deadlock) or the statement failed (lock-wait). Caller should
    rollback defensively and retry the unit of work after a short
    backoff."""
    if isinstance(exc, pymysql.err.OperationalError) and exc.args:
        return exc.args[0] in _RETRYABLE_SERVER_ABORT_CODES
    return False


def open_connection(cfg: Config) -> pymysql.connections.Connection:
    """Open a fresh MariaDB connection (bare — no context-manager).
    Used by long-running runners that reconnect on loss; keeps the
    parameter set in one place so stream + backfill agree on timeouts
    and cursor class."""
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
    return conn


@contextmanager
def connect(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    """Context-manager wrapper around `open_connection` for callers
    that don't need reconnect-on-loss."""
    conn = open_connection(cfg)
    try:
        yield conn
    finally:
        try:
            conn.close()
        except Exception:  # pragma: no cover — best-effort close
            pass


def fetch_all(conn: pymysql.connections.Connection, sql: str, params: tuple = ()) -> list[dict]:
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return list(cur.fetchall())
