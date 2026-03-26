"""Retry policy engine with error classification.

Implements the retry policy matrix from WS-1:
- TRANSIENT errors: retry with configurable backoff
- PERMANENT errors: fail immediately, no retry
- DATA_QUALITY errors: flag and skip (configurable)
- CONTRACT_VIOLATION errors: fail immediately, no retry
"""

import logging
import time
from typing import Callable, TypeVar

from supplycore.contracts import RetryPolicy
from supplycore.enums import BackoffStrategy, ErrorClass

logger = logging.getLogger("supplycore.retry")

T = TypeVar("T")


class RetryableError(Exception):
    """Wraps an error with its classification for retry decisions."""

    def __init__(self, message: str, error_class: ErrorClass, cause: Exception | None = None) -> None:
        super().__init__(message)
        self.error_class = error_class
        self.cause = cause


class PermanentError(RetryableError):
    """Non-retryable error. Execution should halt."""

    def __init__(self, message: str, cause: Exception | None = None) -> None:
        super().__init__(message, ErrorClass.PERMANENT, cause)


class TransientError(RetryableError):
    """Retryable transient error (network, timeout, 503)."""

    def __init__(self, message: str, cause: Exception | None = None) -> None:
        super().__init__(message, ErrorClass.TRANSIENT, cause)


class DataQualityError(RetryableError):
    """Data quality issue (null rates, drift, anomaly)."""

    def __init__(self, message: str, cause: Exception | None = None) -> None:
        super().__init__(message, ErrorClass.DATA_QUALITY, cause)


class ContractViolationError(RetryableError):
    """Schema or contract mismatch. Non-retryable."""

    def __init__(self, message: str, cause: Exception | None = None) -> None:
        super().__init__(message, ErrorClass.CONTRACT_VIOLATION, cause)


class RetryEngine:
    """Execute operations with retry based on error classification and policy."""

    def __init__(self, policy: RetryPolicy) -> None:
        self._policy = policy

    def should_retry(self, error: Exception, attempt: int) -> bool:
        """Determine whether to retry based on error class and attempt count."""
        if attempt >= self._policy.max_retries:
            return False

        error_class = self.classify_error(error)
        return error_class in self._policy.retryable_errors

    def classify_error(self, error: Exception) -> ErrorClass:
        """Classify an error for retry decisions."""
        if isinstance(error, RetryableError):
            return error.error_class
        # Classify common Python exceptions as transient
        if isinstance(error, (TimeoutError, ConnectionError, OSError)):
            return ErrorClass.TRANSIENT
        return ErrorClass.PERMANENT

    def backoff_seconds(self, attempt: int) -> float:
        """Calculate backoff duration for the given attempt number."""
        base = self._policy.base_backoff_seconds
        match self._policy.backoff_strategy:
            case BackoffStrategy.EXPONENTIAL:
                delay = base * (2**attempt)
            case BackoffStrategy.LINEAR:
                delay = base * (attempt + 1)
            case BackoffStrategy.FIXED:
                delay = base
        return min(delay, self._policy.max_backoff_seconds)

    def execute_with_retry(self, operation: Callable[[], T]) -> T:
        """Execute an operation with retry according to policy.

        Returns the operation result on success.
        Raises the last error if all retries are exhausted.
        """
        last_error: Exception | None = None

        for attempt in range(self._policy.max_retries + 1):
            try:
                return operation()
            except Exception as e:
                last_error = e
                error_class = self.classify_error(e)

                if not self.should_retry(e, attempt):
                    logger.error(
                        "Non-retryable error (class=%s, attempt=%d): %s",
                        error_class,
                        attempt,
                        e,
                    )
                    raise

                backoff = self.backoff_seconds(attempt)
                logger.warning(
                    "Retryable error (class=%s, attempt=%d/%d, backoff=%.1fs): %s",
                    error_class,
                    attempt + 1,
                    self._policy.max_retries,
                    backoff,
                    e,
                )
                time.sleep(backoff)

        # Should not reach here, but satisfy type checker
        assert last_error is not None
        raise last_error
