"""Tests for retry engine and error classification."""

import pytest

from supplycore.contracts import RetryPolicy
from supplycore.engine.retry import (
    ContractViolationError,
    DataQualityError,
    PermanentError,
    RetryEngine,
    TransientError,
)
from supplycore.enums import BackoffStrategy, ErrorClass


class TestRetryEngine:
    def test_transient_error_retried(self, sample_retry_policy):
        engine = RetryEngine(sample_retry_policy)
        assert engine.should_retry(TransientError("timeout"), attempt=0)
        assert engine.should_retry(TransientError("timeout"), attempt=2)
        assert not engine.should_retry(TransientError("timeout"), attempt=3)

    def test_permanent_error_not_retried(self, sample_retry_policy):
        engine = RetryEngine(sample_retry_policy)
        assert not engine.should_retry(PermanentError("bad data"), attempt=0)

    def test_contract_violation_not_retried(self, sample_retry_policy):
        engine = RetryEngine(sample_retry_policy)
        assert not engine.should_retry(ContractViolationError("schema mismatch"), attempt=0)

    def test_classify_builtin_exceptions(self, sample_retry_policy):
        engine = RetryEngine(sample_retry_policy)
        assert engine.classify_error(TimeoutError()) == ErrorClass.TRANSIENT
        assert engine.classify_error(ConnectionError()) == ErrorClass.TRANSIENT
        assert engine.classify_error(ValueError()) == ErrorClass.PERMANENT

    def test_exponential_backoff(self):
        policy = RetryPolicy(
            max_retries=5,
            backoff_strategy=BackoffStrategy.EXPONENTIAL,
            base_backoff_seconds=1.0,
            max_backoff_seconds=60.0,
        )
        engine = RetryEngine(policy)
        assert engine.backoff_seconds(0) == 1.0
        assert engine.backoff_seconds(1) == 2.0
        assert engine.backoff_seconds(2) == 4.0
        assert engine.backoff_seconds(3) == 8.0
        assert engine.backoff_seconds(10) == 60.0  # Capped

    def test_linear_backoff(self):
        policy = RetryPolicy(
            max_retries=5,
            backoff_strategy=BackoffStrategy.LINEAR,
            base_backoff_seconds=2.0,
            max_backoff_seconds=20.0,
        )
        engine = RetryEngine(policy)
        assert engine.backoff_seconds(0) == 2.0
        assert engine.backoff_seconds(1) == 4.0
        assert engine.backoff_seconds(2) == 6.0

    def test_fixed_backoff(self):
        policy = RetryPolicy(
            max_retries=3,
            backoff_strategy=BackoffStrategy.FIXED,
            base_backoff_seconds=5.0,
            max_backoff_seconds=5.0,
        )
        engine = RetryEngine(policy)
        assert engine.backoff_seconds(0) == 5.0
        assert engine.backoff_seconds(5) == 5.0

    def test_execute_with_retry_success(self, sample_retry_policy):
        engine = RetryEngine(sample_retry_policy)
        call_count = 0

        def flaky():
            nonlocal call_count
            call_count += 1
            if call_count < 3:
                raise TransientError("temporary")
            return "ok"

        result = engine.execute_with_retry(flaky)
        assert result == "ok"
        assert call_count == 3

    def test_execute_with_retry_exhausted(self, sample_retry_policy):
        engine = RetryEngine(sample_retry_policy)

        def always_fails():
            raise TransientError("always broken")

        with pytest.raises(TransientError):
            engine.execute_with_retry(always_fails)

    def test_execute_with_retry_permanent_fails_immediately(self, sample_retry_policy):
        engine = RetryEngine(sample_retry_policy)
        call_count = 0

        def permanent():
            nonlocal call_count
            call_count += 1
            raise PermanentError("fatal")

        with pytest.raises(PermanentError):
            engine.execute_with_retry(permanent)
        assert call_count == 1
