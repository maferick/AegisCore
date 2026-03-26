"""Tests for authoritative job registry."""

import pytest

from supplycore.registry import (
    get_job,
    is_registered,
    register_job,
    registered_job_keys,
    supplycore_authoritative_job_registry,
)


class TestJobRegistry:
    def test_register_and_retrieve(self, sample_job_contract):
        register_job(sample_job_contract)
        retrieved = get_job("test_ingest_characters")
        assert retrieved.job_key == sample_job_contract.job_key
        assert retrieved.processor_id == sample_job_contract.processor_id

    def test_immutable_keys(self, sample_job_contract):
        register_job(sample_job_contract)
        with pytest.raises(ValueError, match="immutable"):
            register_job(sample_job_contract)

    def test_get_unregistered_raises(self):
        with pytest.raises(KeyError, match="not found"):
            get_job("nonexistent_job")

    def test_is_registered(self, sample_job_contract):
        assert not is_registered("test_ingest_characters")
        register_job(sample_job_contract)
        assert is_registered("test_ingest_characters")

    def test_registry_returns_copy(self, sample_job_contract):
        register_job(sample_job_contract)
        registry = supplycore_authoritative_job_registry()
        registry.pop("test_ingest_characters")
        # Original registry should not be affected
        assert is_registered("test_ingest_characters")

    def test_registered_keys_sorted(self, sample_batch_config):
        from supplycore.contracts import CheckpointConfig, JobContract
        from supplycore.enums import CheckpointType, JobTier

        for key in ["zebra_job", "alpha_job", "middle_job"]:
            register_job(
                JobContract(
                    job_key=key,
                    processor_id="test.Proc",
                    tier=JobTier.ANCILLARY,
                    owner="team",
                    batch_config=sample_batch_config,
                    checkpoint_config=CheckpointConfig(
                        checkpoint_type=CheckpointType.ID_CURSOR
                    ),
                )
            )
        assert registered_job_keys() == ["alpha_job", "middle_job", "zebra_job"]
