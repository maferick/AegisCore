"""Thin pymysql + Neo4j connection wrappers."""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import pymysql
import pymysql.cursors

from neo4j import Driver, GraphDatabase, Session

from counter_intel.config import Config


@contextmanager
def connection(cfg: Config) -> Iterator[pymysql.connections.Connection]:
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_username,
        password=cfg.db_password,
        database=cfg.db_database,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
        charset="utf8mb4",
        connect_timeout=30,
    )
    try:
        yield conn
    finally:
        conn.close()


@contextmanager
def neo_driver(cfg: Config) -> Iterator[Driver]:
    driver = GraphDatabase.driver(cfg.neo4j_host, auth=(cfg.neo4j_user, cfg.neo4j_password))
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
