"""Idempotency key management for safe re-execution.

Provides idempotency guarantees for operations that must not be
duplicated on retry (e.g., external writes, notifications).
"""

import hashlib
import logging
from typing import Any, Optional, Protocol

logger = logging.getLogger("supplycore.idempotency")


class IdempotencyStore(Protocol):
    """Storage backend for idempotency keys."""

    def exists(self, key: str) -> bool: ...
    def store(self, key: str, result: str) -> None: ...
    def get(self, key: str) -> Optional[str]: ...


def generate_idempotency_key(job_key: str, run_id: str, batch_cursor: str) -> str:
    """Generate a deterministic idempotency key for a batch operation."""
    raw = f"{job_key}:{run_id}:{batch_cursor}"
    return hashlib.sha256(raw.encode()).hexdigest()


class InMemoryIdempotencyStore:
    """Simple in-memory idempotency store for testing and single-process use."""

    def __init__(self) -> None:
        self._store: dict[str, str] = {}

    def exists(self, key: str) -> bool:
        return key in self._store

    def get(self, key: str) -> Optional[str]:
        return self._store.get(key)

    def store(self, key: str, result: str) -> None:
        self._store[key] = result

    def clear(self) -> None:
        self._store.clear()


class DbIdempotencyStore:
    """Database-backed idempotency store for production use."""

    def __init__(self, db: Any) -> None:
        self._db = db

    def exists(self, key: str) -> bool:
        self._db.execute(
            "SELECT 1 FROM idempotency_keys WHERE key = %s", (key,)
        )
        return self._db.fetchone() is not None

    def get(self, key: str) -> Optional[str]:
        self._db.execute(
            "SELECT result FROM idempotency_keys WHERE key = %s", (key,)
        )
        row = self._db.fetchone()
        return row[0] if row else None

    def store(self, key: str, result: str) -> None:
        self._db.execute(
            "INSERT INTO idempotency_keys (key, result) VALUES (%s, %s) "
            "ON CONFLICT (key) DO NOTHING",
            (key, result),
        )
        self._db.commit()
