"""Job execution contracts and data models.

These Pydantic models define the canonical contracts for the AegisCore platform.
All job registration, execution, checkpointing, and logging must conform to these schemas.
"""

from datetime import datetime
from typing import Any, Optional
from uuid import UUID

from pydantic import BaseModel, Field, field_validator

from supplycore.enums import (
    BackoffStrategy,
    CheckpointType,
    ErrorClass,
    ExecutionMode,
    JobTier,
    Outcome,
)


class BatchConfig(BaseModel):
    """Batch processing configuration for a job."""

    batch_size: int = Field(gt=0, le=100_000, description="Records per batch")
    max_duration_seconds: int = Field(
        gt=0, le=3600, description="Maximum wall-clock time for entire job run"
    )
    memory_limit_mb: Optional[int] = Field(
        default=None, gt=0, description="Optional memory ceiling"
    )


class RetryPolicy(BaseModel):
    """Retry behavior configuration by error classification."""

    max_retries: int = Field(default=0, ge=0, le=10, description="Maximum retry attempts")
    backoff_strategy: BackoffStrategy = BackoffStrategy.EXPONENTIAL
    base_backoff_seconds: float = Field(
        default=1.0, gt=0, description="Initial backoff interval"
    )
    max_backoff_seconds: float = Field(
        default=300.0, gt=0, description="Backoff ceiling"
    )
    retryable_errors: list[ErrorClass] = Field(
        default_factory=lambda: [ErrorClass.TRANSIENT],
        description="Error classes eligible for retry",
    )


class CheckpointConfig(BaseModel):
    """Checkpoint configuration for resumable jobs."""

    checkpoint_type: CheckpointType
    enabled: bool = True


class CheckpointState(BaseModel):
    """Runtime checkpoint state for a job."""

    checkpoint_type: CheckpointType
    value: str = Field(description="Serialized cursor value")
    updated_at: datetime


class JobContract(BaseModel):
    """Complete metadata contract for a registered job.

    This is the unit of registration in supplycore_authoritative_job_registry().
    Job keys are immutable once released.
    """

    job_key: str = Field(
        min_length=1,
        max_length=128,
        pattern=r"^[a-z][a-z0-9_]*$",
        description="Unique, immutable job identifier",
    )
    processor_id: str = Field(
        min_length=1,
        description="Fully qualified Python processor class path",
    )
    tier: JobTier
    owner: str = Field(min_length=1, description="Team or individual owning this job")
    description: str = Field(default="", description="Human-readable job description")

    batch_config: BatchConfig
    checkpoint_config: CheckpointConfig
    retry_policy: RetryPolicy = Field(default_factory=RetryPolicy)

    enabled: bool = True
    schedule_cron: Optional[str] = Field(
        default=None, description="Cron expression for scheduled execution"
    )
    tags: list[str] = Field(default_factory=list, description="Categorization tags")

    @field_validator("job_key")
    @classmethod
    def job_key_no_leading_underscore(cls, v: str) -> str:
        if v.startswith("_"):
            raise ValueError("Job key must not start with underscore")
        return v


class LogEnvelope(BaseModel):
    """Structured log envelope per Appendix A of the master plan.

    Every job execution must emit logs conforming to this schema.
    """

    timestamp: datetime
    job_key: str
    run_id: UUID
    execution_mode: ExecutionMode
    batch_size: int = Field(ge=0)
    rows_processed: int = Field(ge=0)
    duration_ms: int = Field(ge=0)
    outcome: Outcome
    error_type: Optional[ErrorClass] = None
    error_message: Optional[str] = None
    checkpoint_before: Optional[str] = None
    checkpoint_after: Optional[str] = None


class BatchResult(BaseModel):
    """Result of fetching a batch of work items."""

    records: list[dict[str, Any]]
    cursor: Optional[str] = Field(
        description="New cursor position after this batch"
    )
    has_more: bool = Field(
        description="Whether more batches remain"
    )


class ProcessedBatch(BaseModel):
    """Result of processing a batch through business logic."""

    outputs: list[dict[str, Any]]
    rows_processed: int = Field(ge=0)
    errors: list[dict[str, Any]] = Field(default_factory=list)


class PersistOutcome(BaseModel):
    """Result of persisting processed batch outputs."""

    rows_written: int = Field(ge=0)
    rows_skipped: int = Field(ge=0, default=0)
    success: bool


class JobExecutionResult(BaseModel):
    """Final result of a complete job execution run."""

    job_key: str
    run_id: UUID
    execution_mode: ExecutionMode
    outcome: Outcome
    total_rows_processed: int = Field(ge=0, default=0)
    total_batches: int = Field(ge=0, default=0)
    duration_ms: int = Field(ge=0)
    error_type: Optional[ErrorClass] = None
    error_message: Optional[str] = None
    checkpoint_final: Optional[str] = None


class AdapterHealth(BaseModel):
    """Health status of an external API adapter."""

    adapter_name: str
    healthy: bool
    latency_ms: Optional[float] = None
    error_rate: float = Field(ge=0.0, le=1.0, default=0.0)
    last_success_at: Optional[datetime] = None
    notes: str = ""


class NormalizedPayload(BaseModel):
    """Normalized output from an adapter, ready for processor consumption."""

    source: str = Field(description="Adapter name that produced this payload")
    records: list[dict[str, Any]]
    cursor: Optional[str] = None
    fetched_at: datetime
