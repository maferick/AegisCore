"""MariaDB + Neo4j clients; thin wrappers matching the patterns used by
theater_clustering (pymysql) and graph_universe_sync (neo4j driver)."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from neo4j import Driver, GraphDatabase, Session

from battle_graph.config import Config


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


@contextmanager
def neo(cfg: Config) -> Iterator[Driver]:
    driver = GraphDatabase.driver(
        cfg.neo4j_host,
        auth=(cfg.neo4j_user, cfg.neo4j_password),
    )
    try:
        yield driver
    finally:
        driver.close()


@contextmanager
def neo_session(driver: Driver, cfg: Config) -> Iterator[Session]:
    session = driver.session(database=cfg.neo4j_database)
    try:
        yield session
    finally:
        session.close()
