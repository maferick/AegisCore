"""MariaDB connection + SHARED advisory-lock key functions.

Lock key derivations MUST stay byte-identical to the upstream modules:
  - graph_metrics_lock_key — battle_graph, battle_partition, battle_features
  - partition_lock_key     — battle_partition, battle_features
  - scoring_lock_key       — Spec 5-specific; same hash input as the
                             partition lock but 'bs_' prefix. Also
                             scoped by weight_version so two Spec 5
                             runs under DIFFERENT weight_versions don't
                             block each other."""

from __future__ import annotations

import hashlib
from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from battle_role_scoring.config import Config


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
    raw = f"battle_graph:{battle_id}:{alliance_id}:{edge_profile_version}:{algo_profile_version}"
    return "bg_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


def partition_lock_key(
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> str:
    raw = f"battle_partition:{battle_id}:{alliance_id}:{partition_algo_version}"
    return "bp_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


def scoring_lock_key(
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
    weight_version: int,
) -> str:
    raw = f"battle_scoring:{battle_id}:{alliance_id}:{partition_algo_version}:{weight_version}"
    return "bs_" + hashlib.sha1(raw.encode()).hexdigest()[:40]


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
        pass
