"""Read/write killmail_ingest_state for source progress tracking."""

from __future__ import annotations

import pymysql


def get_state(conn: pymysql.connections.Connection, source: str, key: str) -> str | None:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT state_value FROM killmail_ingest_state WHERE source = %s AND state_key = %s",
            (source, key),
        )
        row = cur.fetchone()
        return row["state_value"] if row else None


def set_state(conn: pymysql.connections.Connection, source: str, key: str, value: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO killmail_ingest_state (source, state_key, state_value)
            VALUES (%s, %s, %s)
            ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)
            """,
            (source, key, value),
        )


def get_all_states(conn: pymysql.connections.Connection, source: str) -> dict[str, str]:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT state_key, state_value FROM killmail_ingest_state WHERE source = %s",
            (source,),
        )
        return {row["state_key"]: row["state_value"] for row in cur.fetchall()}
