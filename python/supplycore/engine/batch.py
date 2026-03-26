"""Batch executor — drives the batch loop for any BaseProcessor.

This is the operational core of the execution plane. It orchestrates:
1. Load checkpoint
2. Fetch bounded batch
3. Validate and process records
4. Persist outputs atomically
5. Persist new checkpoint
6. Emit structured metrics/logs
7. Exit or continue until window exhausted

Per Appendix B of the master plan.
"""

import logging
import time
from typing import Optional
from uuid import UUID

from supplycore.contracts import (
    BatchConfig,
    CheckpointState,
    JobExecutionResult,
)
from supplycore.engine.checkpoint import CheckpointManager
from supplycore.engine.lifecycle import LifecycleHooks
from supplycore.engine.processor import BaseProcessor, JobContext
from supplycore.engine.retry import RetryEngine, RetryableError
from supplycore.enums import CheckpointType, ErrorClass, ExecutionMode, Outcome
from supplycore.logging.emitter import emit_job_log
from supplycore.logging.envelope import LogEnvelopeBuilder

logger = logging.getLogger("supplycore.batch")


class BatchExecutor:
    """Drives the batch execution loop for any BaseProcessor.

    Enforces:
    - Bounded batches (no unbounded full-table scans)
    - Checkpoint after each successful batch boundary
    - Structured log emission for every run
    - Retry on transient failures per policy
    - Clean abort on permanent failures
    - Max duration enforcement
    """

    def __init__(
        self,
        processor: BaseProcessor,
        checkpoint_mgr: CheckpointManager,
        retry_engine: RetryEngine,
        batch_config: BatchConfig,
        execution_mode: ExecutionMode,
        run_id: UUID,
        checkpoint_type: CheckpointType,
        lifecycle: Optional[LifecycleHooks] = None,
        parameters: Optional[dict] = None,
    ) -> None:
        self._processor = processor
        self._checkpoint_mgr = checkpoint_mgr
        self._retry = retry_engine
        self._config = batch_config
        self._execution_mode = execution_mode
        self._run_id = run_id
        self._checkpoint_type = checkpoint_type
        self._lifecycle = lifecycle or LifecycleHooks()
        self._parameters = parameters or {}

    def execute(self) -> JobExecutionResult:
        """Run the full batch loop. Resumable from last checkpoint."""
        job_key = self._processor.job_key
        start_time = time.monotonic()
        total_rows = 0
        total_batches = 0
        checkpoint_final: Optional[str] = None
        error_type: Optional[ErrorClass] = None
        error_message: Optional[str] = None
        outcome = Outcome.SUCCESS

        log_builder = LogEnvelopeBuilder(
            job_key, self._run_id, self._execution_mode
        )
        log_builder.set_batch_size(self._config.batch_size)

        # Load existing checkpoint for resume
        existing_checkpoint = self._checkpoint_mgr.load()
        checkpoint_before = existing_checkpoint.value if existing_checkpoint else None
        current_cursor = checkpoint_before

        log_builder.set_checkpoint(checkpoint_before, None)
        self._lifecycle.before_job(job_key, str(self._run_id))

        # Setup processor
        context = JobContext(
            job_key=job_key,
            run_id=str(self._run_id),
            execution_mode=self._execution_mode,
            batch_config=self._config,
            checkpoint_type=self._checkpoint_type,
            parameters=self._parameters,
        )

        try:
            self._processor.setup(context)

            while True:
                # Check max duration
                elapsed_ms = int((time.monotonic() - start_time) * 1000)
                if elapsed_ms >= self._config.max_duration_seconds * 1000:
                    logger.info(
                        "Job '%s' reached max duration (%ds), stopping gracefully",
                        job_key,
                        self._config.max_duration_seconds,
                    )
                    if total_batches > 0 and total_rows > 0:
                        outcome = Outcome.PARTIAL
                    break

                # Fetch batch (with retry)
                batch = self._retry.execute_with_retry(
                    lambda: self._processor.fetch_batch(
                        current_cursor, self._config.batch_size
                    )
                )

                if not batch.records:
                    logger.info("Job '%s' no more records to process", job_key)
                    break

                # Process batch (with retry)
                processed = self._retry.execute_with_retry(
                    lambda: self._processor.process_batch(batch)
                )

                # Persist results (with retry)
                persist_result = self._retry.execute_with_retry(
                    lambda: self._processor.persist_results(processed)
                )

                total_rows += processed.rows_processed
                total_batches += 1
                current_cursor = batch.cursor

                # Persist checkpoint after successful batch
                if current_cursor is not None:
                    new_checkpoint = CheckpointState(
                        checkpoint_type=self._checkpoint_type,
                        value=current_cursor,
                        updated_at=__import__("datetime").datetime.now(
                            __import__("datetime").timezone.utc
                        ),
                    )
                    self._checkpoint_mgr.save(new_checkpoint)
                    checkpoint_final = current_cursor
                    self._lifecycle.on_checkpoint_saved(job_key, current_cursor)

                self._lifecycle.on_batch_complete(
                    job_key, total_batches, batch, processed
                )

                if not batch.has_more:
                    break

        except RetryableError as e:
            outcome = Outcome.FAILURE
            error_type = e.error_class
            error_message = str(e)
            self._lifecycle.on_error(
                job_key, e, e.error_class, 0, total_batches
            )
        except Exception as e:
            outcome = Outcome.FAILURE
            error_type = ErrorClass.PERMANENT
            error_message = str(e)
            self._lifecycle.on_error(
                job_key, e, ErrorClass.PERMANENT, 0, total_batches
            )
        finally:
            self._processor.teardown()

        duration_ms = int((time.monotonic() - start_time) * 1000)

        # Build and emit structured log
        log_builder.set_rows_processed(total_rows)
        log_builder.set_checkpoint(checkpoint_before, checkpoint_final)

        if outcome == Outcome.SUCCESS:
            envelope = log_builder.success(duration_ms)
        elif outcome == Outcome.PARTIAL:
            envelope = log_builder.partial(duration_ms, error_type, error_message)
        else:
            assert error_type is not None
            envelope = log_builder.failure(duration_ms, error_type, error_message or "")

        emit_job_log(envelope)

        result = JobExecutionResult(
            job_key=job_key,
            run_id=self._run_id,
            execution_mode=self._execution_mode,
            outcome=outcome,
            total_rows_processed=total_rows,
            total_batches=total_batches,
            duration_ms=duration_ms,
            error_type=error_type,
            error_message=error_message,
            checkpoint_final=checkpoint_final,
        )

        self._lifecycle.after_job(result)
        return result
