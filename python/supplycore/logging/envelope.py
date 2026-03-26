"""Structured log envelope builder per Appendix A of the master plan.

Every job execution must emit logs conforming to the LogEnvelope schema.
This module provides a builder pattern for constructing envelopes with
progressive field population during batch execution.
"""

from datetime import datetime, timezone
from uuid import UUID

from supplycore.contracts import LogEnvelope
from supplycore.enums import ErrorClass, ExecutionMode, Outcome


class LogEnvelopeBuilder:
    """Builder for structured log envelopes.

    Usage:
        builder = LogEnvelopeBuilder("my_job", run_id, ExecutionMode.WORKER)
        builder.set_batch_size(1000)
        builder.set_rows_processed(987)
        builder.set_checkpoint("cursor:100", "cursor:200")
        envelope = builder.success(duration_ms=1234)
    """

    def __init__(
        self,
        job_key: str,
        run_id: UUID,
        execution_mode: ExecutionMode,
    ) -> None:
        self._job_key = job_key
        self._run_id = run_id
        self._execution_mode = execution_mode
        self._batch_size: int = 0
        self._rows_processed: int = 0
        self._checkpoint_before: str | None = None
        self._checkpoint_after: str | None = None

    def set_batch_size(self, size: int) -> "LogEnvelopeBuilder":
        self._batch_size = size
        return self

    def set_rows_processed(self, count: int) -> "LogEnvelopeBuilder":
        self._rows_processed = count
        return self

    def add_rows_processed(self, count: int) -> "LogEnvelopeBuilder":
        self._rows_processed += count
        return self

    def set_checkpoint(
        self, before: str | None, after: str | None
    ) -> "LogEnvelopeBuilder":
        self._checkpoint_before = before
        self._checkpoint_after = after
        return self

    def success(self, duration_ms: int) -> LogEnvelope:
        return self._build(Outcome.SUCCESS, duration_ms)

    def failure(
        self, duration_ms: int, error_type: ErrorClass, message: str
    ) -> LogEnvelope:
        return self._build(
            Outcome.FAILURE, duration_ms, error_type=error_type, error_message=message
        )

    def partial(
        self,
        duration_ms: int,
        error_type: ErrorClass | None = None,
        message: str | None = None,
    ) -> LogEnvelope:
        return self._build(
            Outcome.PARTIAL, duration_ms, error_type=error_type, error_message=message
        )

    def _build(
        self,
        outcome: Outcome,
        duration_ms: int,
        error_type: ErrorClass | None = None,
        error_message: str | None = None,
    ) -> LogEnvelope:
        return LogEnvelope(
            timestamp=datetime.now(timezone.utc),
            job_key=self._job_key,
            run_id=self._run_id,
            execution_mode=self._execution_mode,
            batch_size=self._batch_size,
            rows_processed=self._rows_processed,
            duration_ms=duration_ms,
            outcome=outcome,
            error_type=error_type,
            error_message=error_message,
            checkpoint_before=self._checkpoint_before,
            checkpoint_after=self._checkpoint_after,
        )
