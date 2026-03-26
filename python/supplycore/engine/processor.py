"""Base processor abstract class — the heart of the execution plane.

Every compute job in AegisCore implements this interface. The lifecycle is:

    setup() → [fetch_batch() → process_batch() → persist_results()]* → teardown()

The BatchExecutor drives this lifecycle with checkpoint, retry, and logging.
Concrete processors implement the abstract methods only — the engine handles
all orchestration concerns.

Per Section 2.1: Python is the only execution runtime for jobs.
Per Section 2.4: Every compute job is batch-based and resumable.
"""

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any, Optional

from supplycore.contracts import BatchConfig, BatchResult, PersistOutcome, ProcessedBatch
from supplycore.enums import CheckpointType, ExecutionMode


@dataclass
class JobContext:
    """Runtime context provided to processors during setup."""

    job_key: str
    run_id: str
    execution_mode: ExecutionMode
    batch_config: BatchConfig
    checkpoint_type: CheckpointType
    db_connection: Any = None  # Injected database connection
    parameters: dict[str, Any] = field(default_factory=dict)


class BaseProcessor(ABC):
    """Abstract base class for all compute job processors.

    Subclasses must implement:
    - job_key: unique identifier matching the authoritative registry
    - setup: one-time initialization (connections, caches, state)
    - fetch_batch: retrieve the next bounded batch of work
    - process_batch: pure transform logic (no side effects)
    - persist_results: atomic write of processed outputs

    Optional override:
    - teardown: cleanup resources after execution completes
    """

    @property
    @abstractmethod
    def job_key(self) -> str:
        """Unique job identifier. Must match the authoritative registry entry."""
        ...

    @abstractmethod
    def setup(self, context: JobContext) -> None:
        """One-time initialization before batch processing begins.

        Use this to establish connections, load reference data, or prepare caches.
        Called once per job execution run.
        """
        ...

    @abstractmethod
    def fetch_batch(
        self, checkpoint_value: Optional[str], batch_size: int
    ) -> BatchResult:
        """Fetch the next bounded batch of work items.

        Args:
            checkpoint_value: Current cursor position (None on first run).
            batch_size: Maximum records to fetch (from BatchConfig).

        Returns:
            BatchResult with records, new cursor, and has_more flag.

        MUST return a bounded result set. No unbounded full-table scans.
        """
        ...

    @abstractmethod
    def process_batch(self, batch: BatchResult) -> ProcessedBatch:
        """Apply business logic to a batch of records.

        This should be a pure transform — no database writes or API calls.
        Side effects belong in persist_results().
        """
        ...

    @abstractmethod
    def persist_results(self, results: ProcessedBatch) -> PersistOutcome:
        """Atomically write processed results to the target store.

        Must be idempotent where possible — re-persisting the same batch
        should not create duplicates.
        """
        ...

    def teardown(self) -> None:
        """Optional cleanup after execution completes.

        Override to close connections, flush buffers, etc.
        Called in finally block — guaranteed to run even after errors.
        """
        pass
