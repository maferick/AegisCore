"""Tests for contract validation and serialization."""

import pytest
from pydantic import ValidationError

from supplycore.contracts import (
    BatchConfig,
    CheckpointConfig,
    JobContract,
    LogEnvelope,
    RetryPolicy,
)
from supplycore.enums import (
    CheckpointType,
    ErrorClass,
    ExecutionMode,
    JobTier,
    Outcome,
)


class TestJobContract:
    def test_valid_contract(self, sample_job_contract):
        assert sample_job_contract.job_key == "test_ingest_characters"
        assert sample_job_contract.tier == JobTier.CRITICAL

    def test_invalid_job_key_uppercase(self, sample_batch_config):
        with pytest.raises(ValidationError):
            JobContract(
                job_key="InvalidKey",
                processor_id="test.Processor",
                tier=JobTier.CRITICAL,
                owner="team",
                batch_config=sample_batch_config,
                checkpoint_config=CheckpointConfig(
                    checkpoint_type=CheckpointType.ID_CURSOR
                ),
            )

    def test_invalid_job_key_starts_with_underscore(self, sample_batch_config):
        with pytest.raises(ValidationError):
            JobContract(
                job_key="_hidden",
                processor_id="test.Processor",
                tier=JobTier.CRITICAL,
                owner="team",
                batch_config=sample_batch_config,
                checkpoint_config=CheckpointConfig(
                    checkpoint_type=CheckpointType.ID_CURSOR
                ),
            )

    def test_empty_job_key_rejected(self, sample_batch_config):
        with pytest.raises(ValidationError):
            JobContract(
                job_key="",
                processor_id="test.Processor",
                tier=JobTier.CRITICAL,
                owner="team",
                batch_config=sample_batch_config,
                checkpoint_config=CheckpointConfig(
                    checkpoint_type=CheckpointType.ID_CURSOR
                ),
            )

    def test_json_roundtrip(self, sample_job_contract):
        json_str = sample_job_contract.model_dump_json()
        restored = JobContract.model_validate_json(json_str)
        assert restored == sample_job_contract

    def test_json_schema_generation(self):
        schema = JobContract.model_json_schema()
        assert "properties" in schema
        assert "job_key" in schema["properties"]


class TestBatchConfig:
    def test_valid_config(self):
        config = BatchConfig(batch_size=1000, max_duration_seconds=600)
        assert config.batch_size == 1000

    def test_zero_batch_size_rejected(self):
        with pytest.raises(ValidationError):
            BatchConfig(batch_size=0, max_duration_seconds=600)

    def test_exceeding_max_batch_size(self):
        with pytest.raises(ValidationError):
            BatchConfig(batch_size=200_000, max_duration_seconds=600)


class TestRetryPolicy:
    def test_defaults(self):
        policy = RetryPolicy()
        assert policy.max_retries == 0
        assert policy.retryable_errors == [ErrorClass.TRANSIENT]

    def test_custom_policy(self):
        policy = RetryPolicy(
            max_retries=5,
            retryable_errors=[ErrorClass.TRANSIENT, ErrorClass.DATA_QUALITY],
        )
        assert len(policy.retryable_errors) == 2
