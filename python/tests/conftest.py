"""Shared test fixtures for AegisCore test suite."""

import pytest
from uuid import uuid4

from supplycore.contracts import (
    BatchConfig,
    BatchResult,
    CheckpointConfig,
    CheckpointState,
    JobContract,
    PersistOutcome,
    ProcessedBatch,
    RetryPolicy,
)
from supplycore.engine.processor import BaseProcessor, JobContext
from supplycore.enums import (
    BackoffStrategy,
    CheckpointType,
    ErrorClass,
    ExecutionMode,
    JobTier,
)
from supplycore.registry import _reset_registry_for_testing


@pytest.fixture(autouse=True)
def clean_registry():
    """Reset the job registry before each test."""
    _reset_registry_for_testing()
    yield
    _reset_registry_for_testing()


@pytest.fixture
def sample_batch_config():
    return BatchConfig(batch_size=100, max_duration_seconds=300)


@pytest.fixture
def sample_retry_policy():
    return RetryPolicy(
        max_retries=3,
        backoff_strategy=BackoffStrategy.EXPONENTIAL,
        base_backoff_seconds=0.01,  # Fast for tests
        max_backoff_seconds=0.1,
        retryable_errors=[ErrorClass.TRANSIENT],
    )


@pytest.fixture
def sample_job_contract(sample_batch_config):
    return JobContract(
        job_key="test_ingest_characters",
        processor_id="supplycore.processors.test.TestProcessor",
        tier=JobTier.CRITICAL,
        owner="platform-team",
        description="Test job for unit testing",
        batch_config=sample_batch_config,
        checkpoint_config=CheckpointConfig(
            checkpoint_type=CheckpointType.ID_CURSOR,
        ),
    )


class StubProcessor(BaseProcessor):
    """Minimal processor implementation for testing."""

    def __init__(self, key: str = "stub_job", records: list | None = None):
        self._key = key
        self._records = records or []
        self._setup_called = False
        self._teardown_called = False
        self._batch_count = 0

    @property
    def job_key(self) -> str:
        return self._key

    def setup(self, context: JobContext) -> None:
        self._setup_called = True

    def fetch_batch(self, checkpoint_value, batch_size):
        if self._batch_count >= 1:
            return BatchResult(records=[], cursor=None, has_more=False)
        self._batch_count += 1
        return BatchResult(
            records=self._records or [{"id": i} for i in range(batch_size)],
            cursor=str(batch_size),
            has_more=False,
        )

    def process_batch(self, batch):
        return ProcessedBatch(
            outputs=batch.records,
            rows_processed=len(batch.records),
        )

    def persist_results(self, results):
        return PersistOutcome(
            rows_written=results.rows_processed,
            success=True,
        )

    def teardown(self) -> None:
        self._teardown_called = True


@pytest.fixture
def stub_processor():
    return StubProcessor()
