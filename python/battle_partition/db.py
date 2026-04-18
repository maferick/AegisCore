"""MariaDB connection helper + SHARED advisory-lock key function.

The lock key derivation MUST be byte-identical to
battle_graph/runs.py::_lock_key. That is the whole concurrency
contract with Spec 2 — both workers compute the same sha1 on the
same tuple and MariaDB's GET_LOCK enforces mutual exclusion.

If you change the derivation here, change it in battle_graph/runs.py
at the same time. There is no shared package between the two workers
because they ship as separate Docker images; a cross-reference
comment is the enforcement."""

from __future__ import annotations

import hashlib
from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from battle_partition.config import Config


def connect_mariadb(cfg: Config) -> pymysql.connections.Connection:
    return pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )


@contextmanager
def maria(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    conn = connect_mariadb(cfg)
    try:
        yield conn
    finally:
        try:
            conn.close()
        except Exception:
            pass


def graph_metrics_lock_key(
    battle_id: int,
    alliance_id: int,
    edge_profile_version: int,
    algo_profile_version: int,
) -> str:
    """Shared with Spec 2. Must stay in sync with
    battle_graph/runs.py::_lock_key."""
    raw = f"battle_graph:{battle_id}:{alliance_id}:{edge_profile_version}:{algo_profile_version}"
    return "bg_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


def partition_lock_key(
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> str:
    """Spec 3-specific. Prevents two concurrent partition passes on the
    same (battle, alliance, rule). Separate key space from Spec 2's
    graph-metrics lock so a Spec 3 run can hold both locks
    simultaneously without self-deadlock."""
    raw = f"battle_partition:{battle_id}:{alliance_id}:{partition_algo_version}"
    return "bp_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


def acquire_lock(
    conn: pymysql.connections.Connection,
    key: str,
    timeout_seconds: int,
) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT GET_LOCK(%s, %s) AS got", (key, timeout_seconds))
        row = cur.fetchone()
    got = (row or {}).get("got")
    if got != 1:
        raise RuntimeError(f"could not acquire advisory lock: {key} (timeout={timeout_seconds}s)")


def release_lock(conn: pymysql.connections.Connection, key: str) -> None:
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT RELEASE_LOCK(%s)", (key,))
            cur.fetchall()
    except Exception:
        # Best-effort; MariaDB auto-releases on connection close.
        pass
