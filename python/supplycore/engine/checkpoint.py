"""Checkpoint persistence for resumable job execution.

Checkpoints are persisted to the job_checkpoints table after each successful
batch boundary, enabling mid-run failure recovery per Section 2.4.

Supports all three CheckpointType variants:
- ID_CURSOR: monotonic integer-based progression
- TIMESTAMP_WATERMARK: ISO-8601 time-based high-water mark
- COMPOSITE_CURSOR: JSON-serialized multi-field compound cursor
"""

import json
import logging
from datetime import datetime, timezone
from typing import Any, Optional, Protocol

from supplycore.contracts import CheckpointState
from supplycore.enums import CheckpointType

logger = logging.getLogger("supplycore.checkpoint")


class DbConnection(Protocol):
    """Minimal database connection interface for checkpoint operations."""

    def execute(self, query: str, params: tuple[Any, ...] | None = None) -> Any: ...
    def fetchone(self) -> Optional[tuple[Any, ...]]: ...
    def commit(self) -> None: ...


class CheckpointManager:
    """Load, save, and manage checkpoints for resumable jobs.

    Each job has at most one active checkpoint. The checkpoint is persisted
    atomically after each successful batch boundary.
    """

    def __init__(self, db: DbConnection, job_key: str) -> None:
        self._db = db
        self._job_key = job_key

    def load(self) -> Optional[CheckpointState]:
        """Load the current checkpoint for this job, if any."""
        self._db.execute(
            "SELECT checkpoint_type, checkpoint_value, updated_at "
            "FROM job_checkpoints WHERE job_key = %s",
            (self._job_key,),
        )
        row = self._db.fetchone()
        if row is None:
            logger.info("No checkpoint found for job '%s'", self._job_key)
            return None

        state = CheckpointState(
            checkpoint_type=CheckpointType(row[0]),
            value=row[1],
            updated_at=row[2],
        )
        logger.info(
            "Loaded checkpoint for job '%s': type=%s, value=%s",
            self._job_key,
            state.checkpoint_type,
            state.value,
        )
        return state

    def save(self, state: CheckpointState) -> None:
        """Persist a new checkpoint state. Uses upsert for atomicity."""
        now = datetime.now(timezone.utc)
        self._db.execute(
            "INSERT INTO job_checkpoints (job_key, checkpoint_type, checkpoint_value, updated_at) "
            "VALUES (%s, %s, %s, %s) "
            "ON CONFLICT (job_key) DO UPDATE SET "
            "checkpoint_type = EXCLUDED.checkpoint_type, "
            "checkpoint_value = EXCLUDED.checkpoint_value, "
            "updated_at = EXCLUDED.updated_at",
            (self._job_key, state.checkpoint_type.value, state.value, now),
        )
        self._db.commit()
        logger.info(
            "Saved checkpoint for job '%s': type=%s, value=%s",
            self._job_key,
            state.checkpoint_type,
            state.value,
        )

    def clear(self) -> None:
        """Remove the checkpoint for this job."""
        self._db.execute(
            "DELETE FROM job_checkpoints WHERE job_key = %s",
            (self._job_key,),
        )
        self._db.commit()
        logger.info("Cleared checkpoint for job '%s'", self._job_key)


def serialize_composite_cursor(fields: dict[str, Any]) -> str:
    """Serialize a composite cursor to a deterministic JSON string."""
    return json.dumps(fields, sort_keys=True, separators=(",", ":"))


def deserialize_composite_cursor(value: str) -> dict[str, Any]:
    """Deserialize a composite cursor from JSON string."""
    result: dict[str, Any] = json.loads(value)
    return result
