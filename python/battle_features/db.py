"""MariaDB connection helper + SHARED advisory-lock key functions.

Lock key derivations MUST be byte-identical to the originating module:
  - graph_metrics_lock_key — battle_graph/runs.py::_lock_key
                           — battle_partition/db.py::graph_metrics_lock_key
  - partition_lock_key     — battle_partition/db.py::partition_lock_key
  - feature_lock_key       — Spec-4 specific; same hash input as
                             partition_lock_key, different prefix.

The workers ship as separate Docker images so there is no shared
Python package — this cross-reference comment is the enforcement.
If any derivation changes here, change it in the other modules at
the same time."""

from __future__ import annotations

import hashlib
from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from battle_features.config import Config


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
    """Shared with Spec 2 + Spec 3."""
    raw = f"battle_graph:{battle_id}:{alliance_id}:{edge_profile_version}:{algo_profile_version}"
    return "bg_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


def partition_lock_key(
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> str:
    """Shared with Spec 3."""
    raw = f"battle_partition:{battle_id}:{alliance_id}:{partition_algo_version}"
    return "bp_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


def feature_lock_key(
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> str:
    """Spec-4 specific. Same hash input as partition_lock_key, different
    prefix — prevents two concurrent Spec 4 runs on the same
    (battle, alliance, partition_algo_version) while still being in a
    different key space from Spec 3's partition lock so a single Spec 4
    run can hold both without self-deadlock."""
    raw = f"battle_partition:{battle_id}:{alliance_id}:{partition_algo_version}"
    return "bf_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


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
