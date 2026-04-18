"""Audit-log + concurrency-control helpers for
battle_graph_projection_runs.

Concurrency model
-----------------

Spec 3 will partition sub-fleets by reading the metrics rows Spec 2
produces, and a mid-write read from Spec 3 would tear across the
INSERT ... ON DUPLICATE KEY UPDATE batch. Row-level status checks on
``battle_graph_projection_runs`` don't compose with that reader —
Spec 3 can't block on a SELECT from a status column.

Both writers (Spec 2) and readers (Spec 3) share a MariaDB advisory
lock keyed on ``(battle_id, alliance_id, edge_profile_version,
algo_profile_version)``. Writers grab the lock non-blocking before
starting; readers grab it with a timeout before their SELECT. The
result: mutual exclusion without a snapshot / versioning layer.

Locks are session-scoped — if the worker crashes, MariaDB releases
automatically when the connection closes, so a broken Spec 2 run
doesn't wedge future Spec 3 reads on the same tuple.
"""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone

import pymysql


def _lock_key(
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
) -> str:
    """Stable short lock name. MariaDB caps GET_LOCK names at 64 bytes
    — the raw tuple string is already under that but we hash to
    future-proof against wider IDs."""
    raw = f"battle_graph:{battle_id}:{alliance_id}:{edge_profile_version}:{algo_profile_version}"
    return "bg_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


def acquire_write_lock(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
) -> str:
    """Non-blocking acquire for Spec 2 writers. Returns the lock name
    (caller passes it to ``release_lock`` on exit)."""
    key = _lock_key(battle_id, alliance_id, edge_profile_version, algo_profile_version)
    with conn.cursor() as cur:
        cur.execute("SELECT GET_LOCK(%s, 0)", (key,))
        row = cur.fetchone()
    # pymysql DictCursor returns {'GET_LOCK(%s, 0)': 1} keyed on the
    # raw SQL expression; fall through by position via list()[0].
    got = list(row.values())[0] if row else None
    if got != 1:
        raise RuntimeError(
            f"battle_graph write lock busy: battle={battle_id} alliance={alliance_id} "
            f"edge_profile_version={edge_profile_version} algo_profile_version={algo_profile_version}"
        )
    return key


def acquire_read_lock(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
    timeout_seconds: int = 30,
) -> str:
    """Spec 3 readers call this before SELECTing from
    ``battle_character_graph_metrics``. Blocks up to ``timeout_seconds``
    so concurrent Spec 2 writes can finish; raises if the writer
    holds the lock longer."""
    key = _lock_key(battle_id, alliance_id, edge_profile_version, algo_profile_version)
    with conn.cursor() as cur:
        cur.execute("SELECT GET_LOCK(%s, %s)", (key, timeout_seconds))
        row = cur.fetchone()
    got = list(row.values())[0] if row else None
    if got != 1:
        raise RuntimeError(
            f"battle_graph read lock timeout: battle={battle_id} alliance={alliance_id} "
            f"edge_profile_version={edge_profile_version} algo_profile_version={algo_profile_version}"
        )
    return key


def release_lock(conn: pymysql.connections.Connection, key: str) -> None:
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT RELEASE_LOCK(%s)", (key,))
            cur.fetchall()
    except Exception:
        # Best-effort release. MariaDB drops the lock on connection
        # close anyway — this is belt-and-braces for long-lived
        # connections that might be reused for another run.
        pass


def start_run(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
) -> tuple[int, str]:
    """Reserve the advisory lock + create the audit row. Returns
    ``(run_id, lock_key)``; the caller passes ``lock_key`` back into
    ``finalize_run`` so the lock releases on the exact same connection
    that took it."""
    lock_key = acquire_write_lock(
        conn, battle_id, alliance_id, edge_profile_version, algo_profile_version,
    )
    try:
        now = datetime.now(timezone.utc)
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO battle_graph_projection_runs
                  (battle_id, alliance_id, edge_profile_version, algo_profile_version,
                   started_at, status)
                VALUES (%s, %s, %s, %s, %s, 'running')
                """,
                (battle_id, alliance_id, edge_profile_version, algo_profile_version, now),
            )
            run_id = cur.lastrowid
        conn.commit()
    except Exception:
        release_lock(conn, lock_key)
        raise
    return int(run_id), lock_key


def finalize_run(
    conn: pymysql.connections.Connection,
    run_id: int,
    status: str,
    *,
    lock_key: str | None = None,
    pilot_count: int | None = None,
    edge_count: int | None = None,
    graph_tier: str | None = None,
    algorithms_run: list[str] | None = None,
    error_message: str | None = None,
) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT started_at FROM battle_graph_projection_runs WHERE run_id=%s", (run_id,))
        row = cur.fetchone()
        started_at = row["started_at"] if row else datetime.now(timezone.utc)
        now = datetime.now(timezone.utc)
        # Strip tzinfo so MariaDB DATETIME doesn't need timezone handling.
        naive_now = now.replace(tzinfo=None)
        naive_started = started_at if not hasattr(started_at, 'tzinfo') or started_at.tzinfo is None else started_at.replace(tzinfo=None)
        duration_ms = int((naive_now - naive_started).total_seconds() * 1000)
        cur.execute(
            """
            UPDATE battle_graph_projection_runs
            SET completed_at=%s,
                duration_ms=%s,
                status=%s,
                pilot_count=%s,
                edge_count=%s,
                graph_tier=%s,
                algorithms_run_json=%s,
                error_message=%s
            WHERE run_id=%s
            """,
            (
                naive_now,
                duration_ms,
                status,
                pilot_count,
                edge_count,
                graph_tier,
                json.dumps(algorithms_run) if algorithms_run is not None else None,
                error_message,
                run_id,
            ),
        )
    conn.commit()
    if lock_key is not None:
        release_lock(conn, lock_key)
