"""Job lifecycle hooks for cross-cutting concerns.

Hooks are called at key points during job execution. Default implementations
are no-ops. Override for custom behavior (metrics, notifications, etc.).
"""

import logging
from typing import Optional

from supplycore.contracts import BatchResult, JobExecutionResult, ProcessedBatch
from supplycore.enums import ErrorClass

logger = logging.getLogger("supplycore.lifecycle")


class LifecycleHooks:
    """Default lifecycle hooks. Override methods for custom behavior."""

    def before_job(self, job_key: str, run_id: str) -> None:
        """Called before job execution begins."""
        logger.debug("Starting job '%s' run '%s'", job_key, run_id)

    def after_job(self, result: JobExecutionResult) -> None:
        """Called after job execution completes (success or failure)."""
        logger.debug(
            "Completed job '%s': outcome=%s, rows=%d",
            result.job_key,
            result.outcome,
            result.total_rows_processed,
        )

    def on_batch_complete(
        self,
        job_key: str,
        batch_number: int,
        batch: BatchResult,
        results: ProcessedBatch,
    ) -> None:
        """Called after each batch is processed and persisted."""
        logger.debug(
            "Job '%s' batch %d: processed %d rows",
            job_key,
            batch_number,
            results.rows_processed,
        )

    def on_error(
        self,
        job_key: str,
        error: Exception,
        error_class: ErrorClass,
        attempt: int,
        batch_number: Optional[int] = None,
    ) -> None:
        """Called when an error occurs during execution."""
        logger.warning(
            "Job '%s' error (class=%s, attempt=%d, batch=%s): %s",
            job_key,
            error_class,
            attempt,
            batch_number,
            error,
        )

    def on_checkpoint_saved(self, job_key: str, checkpoint_value: str) -> None:
        """Called after a checkpoint is successfully persisted."""
        logger.debug(
            "Job '%s' checkpoint saved: %s",
            job_key,
            checkpoint_value,
        )
