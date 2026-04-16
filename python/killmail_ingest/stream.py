"""R2Z2 live killmail stream runner.

Sequence-based cursor: fetches killmails one-at-a-time by incrementing
sequence ID. Persists each killmail + emits outbox event, then advances
the cursor in killmail_ingest_state.

Poll contract: minimum 6 seconds between requests after a 404 (caught
up to head). No sleep needed on successful fetches — R2Z2 allows 20
req/s/IP.

Self-healing: reconnects to MariaDB automatically when the connection
is lost (container restart, network blip, idle timeout).
"""

from __future__ import annotations

import logging
import time

import pymysql

from killmail_ingest.config import Config
from killmail_ingest.db import connect
from killmail_ingest.log import get
from killmail_ingest.outbox import emit_killmail_ingested
from killmail_ingest.parse import parse_esi_killmail
from killmail_ingest.persist import ingest_killmail
from killmail_ingest.r2z2 import (
    R2z2Transient,
    extract_esi_killmail,
    fetch_killmail,
    fetch_latest_sequence,
)
from killmail_ingest.state import get_state, set_state


log = get(__name__)

# DB errors that indicate a lost connection — reconnect on these.
_LOST_CONNECTION_CODES = {0, 2006, 2013, 2014, 2055}


def _is_connection_lost(exc: Exception) -> bool:
    """Check if a pymysql error indicates a dead connection."""
    if isinstance(exc, pymysql.err.OperationalError) and exc.args:
        return exc.args[0] in _LOST_CONNECTION_CODES
    # Generic "(0, '')" also means dead connection.
    if hasattr(exc, 'args') and exc.args and exc.args[0] in _LOST_CONNECTION_CODES:
        return True
    return "Lost connection" in str(exc) or "(0, '')" in str(exc)


def run_stream(cfg: Config) -> int:
    """Run the R2Z2 live stream. Blocks indefinitely until interrupted."""
    logging.getLogger("httpx").setLevel(logging.WARNING)

    conn = _connect(cfg)
    cursor = _init_cursor(cfg, conn)
    if cursor is None:
        return 1

    ingested = 0
    duplicates = 0
    errors = 0
    consecutive_db_errors = 0

    try:
        while True:
            try:
                data = fetch_killmail(cfg, cursor)
            except R2z2Transient as exc:
                errors += 1
                log.warning("transient error, retrying after sleep", sequence=cursor, error=str(exc))
                time.sleep(cfg.r2z2_poll_interval_seconds)
                continue

            if data is None:
                time.sleep(cfg.r2z2_poll_interval_seconds)
                cursor += 1
                continue

            try:
                esi_payload, killmail_hash = extract_esi_killmail(data)

                if "killmail_id" not in esi_payload:
                    log.warning("no killmail_id in R2Z2 response", sequence=cursor)
                    cursor += 1
                    continue

                km = parse_esi_killmail(esi_payload, killmail_hash=killmail_hash)

                was_new = ingest_killmail(conn, km)
                emit_killmail_ingested(conn, km=km)

                set_state(conn, "r2z2", "last_sequence", str(cursor))

                if cfg.dry_run:
                    conn.rollback()
                else:
                    conn.commit()

                consecutive_db_errors = 0

                if was_new:
                    ingested += 1
                else:
                    duplicates += 1

                if ingested % 100 == 0 and ingested > 0:
                    log.info(
                        "stream progress",
                        ingested=ingested,
                        duplicates=duplicates,
                        errors=errors,
                        sequence=cursor,
                    )

            except Exception as exc:
                errors += 1

                if _is_connection_lost(exc):
                    consecutive_db_errors += 1
                    log.warning(
                        "DB connection lost, reconnecting",
                        sequence=cursor,
                        consecutive=consecutive_db_errors,
                    )
                    try:
                        conn.close()
                    except Exception:
                        pass

                    # Back off on repeated failures.
                    backoff = min(30, consecutive_db_errors * 5)
                    time.sleep(backoff)

                    try:
                        conn = _connect(cfg)
                        log.info("reconnected to MariaDB")
                    except Exception as reconn_exc:
                        log.error("reconnect failed", error=str(reconn_exc))
                        time.sleep(10)
                    continue
                else:
                    consecutive_db_errors = 0
                    log.warning(
                        "failed to process killmail",
                        sequence=cursor,
                        error=str(exc),
                    )
                    try:
                        conn.rollback()
                    except Exception:
                        pass

            cursor += 1

    except KeyboardInterrupt:
        log.info(
            "stream stopped by user",
            ingested=ingested,
            duplicates=duplicates,
            errors=errors,
            last_sequence=cursor,
        )

    try:
        conn.close()
    except Exception:
        pass

    return 0


def _connect(cfg: Config) -> pymysql.connections.Connection:
    """Open a fresh MariaDB connection."""
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


def _init_cursor(cfg: Config, conn) -> int | None:
    """Load or fetch the starting sequence cursor."""
    cursor_str = get_state(conn, "r2z2", "last_sequence")

    if cursor_str is not None:
        cursor = int(cursor_str)
        log.info("resuming from saved cursor", sequence=cursor)
        return cursor

    try:
        cursor = fetch_latest_sequence(cfg)
        log.info("starting from current head", sequence=cursor)
        return cursor
    except R2z2Transient as exc:
        log.error("failed to fetch initial sequence", error=str(exc))
        return None
