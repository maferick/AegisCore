"""Audit-log + concurrency-control helpers for
battle_graph_projection_runs."""

from __future__ import annotations

import json
from datetime import datetime, timezone

import pymysql


def start_run(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
) -> int:
    # Reject if another run for the same tuple is already running.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT run_id FROM battle_graph_projection_runs
            WHERE battle_id=%s AND alliance_id=%s
              AND edge_profile_version=%s AND algo_profile_version=%s
              AND status='running'
            LIMIT 1
            """,
            (battle_id, alliance_id, edge_profile_version, algo_profile_version),
        )
        if cur.fetchone() is not None:
            raise RuntimeError(
                f"run already active for battle={battle_id} alliance={alliance_id}"
            )

        now = datetime.now(timezone.utc)
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
    return int(run_id)


def finalize_run(
    conn: pymysql.connections.Connection,
    run_id: int,
    status: str,
    *,
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
