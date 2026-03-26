"""Database connection factory and pool management.

All connection parameters are read from environment variables per Section 10:
no hardcoded credentials, secrets managed through environment-safe mechanisms.
"""

import logging
import os
from contextlib import contextmanager
from typing import Any, Generator, Optional

logger = logging.getLogger("supplycore.db")


class DatabaseConfig:
    """Database configuration from environment variables."""

    def __init__(self) -> None:
        self.host: str = os.environ.get("AEGIS_DB_HOST", "localhost")
        self.port: int = int(os.environ.get("AEGIS_DB_PORT", "5432"))
        self.database: str = os.environ.get("AEGIS_DB_NAME", "aegiscore")
        self.user: str = os.environ.get("AEGIS_DB_USER", "aegiscore")
        self.password: str = os.environ.get("AEGIS_DB_PASSWORD", "")
        self.min_connections: int = int(os.environ.get("AEGIS_DB_POOL_MIN", "2"))
        self.max_connections: int = int(os.environ.get("AEGIS_DB_POOL_MAX", "10"))

    @property
    def dsn(self) -> str:
        return (
            f"host={self.host} port={self.port} dbname={self.database} "
            f"user={self.user} password={self.password}"
        )


class ConnectionPool:
    """Database connection pool wrapper.

    Wraps psycopg2 connection pooling with structured logging
    and environment-based configuration.
    """

    def __init__(self, config: Optional[DatabaseConfig] = None) -> None:
        self._config = config or DatabaseConfig()
        self._pool: Any = None

    def initialize(self) -> None:
        """Initialize the connection pool. Call once at startup."""
        import psycopg2.pool

        self._pool = psycopg2.pool.ThreadedConnectionPool(
            minconn=self._config.min_connections,
            maxconn=self._config.max_connections,
            dsn=self._config.dsn,
        )
        logger.info(
            "Database pool initialized: %s:%d/%s (pool: %d-%d)",
            self._config.host,
            self._config.port,
            self._config.database,
            self._config.min_connections,
            self._config.max_connections,
        )

    def close(self) -> None:
        """Close all connections in the pool."""
        if self._pool:
            self._pool.closeall()
            logger.info("Database pool closed")

    @contextmanager
    def connection(self) -> Generator[Any, None, None]:
        """Get a connection from the pool. Returns it on exit."""
        if self._pool is None:
            raise RuntimeError("Connection pool not initialized. Call initialize() first.")
        conn = self._pool.getconn()
        try:
            yield conn
        finally:
            self._pool.putconn(conn)

    @contextmanager
    def cursor(self) -> Generator[Any, None, None]:
        """Get a cursor from a pooled connection. Auto-commits on clean exit."""
        with self.connection() as conn:
            cur = conn.cursor()
            try:
                yield cur
                conn.commit()
            except Exception:
                conn.rollback()
                raise
            finally:
                cur.close()
