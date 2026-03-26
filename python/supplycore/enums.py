"""Shared vocabulary for the AegisCore platform.

These enums are the canonical definitions referenced across the entire execution plane.
"""

from enum import Enum


class ErrorClass(str, Enum):
    """Classification of errors for retry policy decisions."""

    TRANSIENT = "transient"  # Network timeout, 503, temporary unavailability
    PERMANENT = "permanent"  # Bad data, missing required field, logic error
    DATA_QUALITY = "data_quality"  # Null rates, drift, anomaly detection
    CONTRACT_VIOLATION = "contract_violation"  # Schema/contract mismatch


class CheckpointType(str, Enum):
    """Supported checkpoint cursor types for resumable jobs."""

    ID_CURSOR = "id_cursor"  # Monotonic ID-based progression
    TIMESTAMP_WATERMARK = "timestamp_watermark"  # Time-based high-water mark
    COMPOSITE_CURSOR = "composite_cursor"  # Multi-field compound cursor


class JobTier(int, Enum):
    """Job priority tiers. Determines rollout order, not quality bar."""

    CRITICAL = 1  # Directly impacts core analytics correctness or UI trust
    IMPORTANT = 2  # Operationally valuable but not immediately customer-visible
    ANCILLARY = 3  # Helper/support workflows


class ExecutionMode(str, Enum):
    """How a job was triggered. All modes must produce identical outcomes."""

    WORKER = "worker"  # Queue-driven worker pool
    SCHEDULER = "scheduler"  # Scheduler-dispatched
    CLI = "cli"  # Manual CLI invocation


class Outcome(str, Enum):
    """Job execution outcome classification."""

    SUCCESS = "success"
    FAILURE = "failure"
    PARTIAL = "partial"  # Some batches succeeded, others failed


class AdapterName(str, Enum):
    """Registered external data source adapters."""

    ESI = "esi"  # EVE Swagger Interface - primary authority
    EVEWHO = "evewho"  # Supplemental entity intelligence
    ZKILL = "zkill"  # Killmail/event behavioral stream


class BackoffStrategy(str, Enum):
    """Retry backoff strategies."""

    EXPONENTIAL = "exponential"
    LINEAR = "linear"
    FIXED = "fixed"
