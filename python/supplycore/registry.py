"""Authoritative Job Registry — the single source of truth for user-manageable jobs.

Per Section 2.2 of the master plan:
- supplycore_authoritative_job_registry() is the ONLY source of truth.
- No runtime inference from table scans, scheduler state, or filesystem scanning.
- Job keys are immutable once released.
"""

from supplycore.contracts import JobContract


_REGISTRY: dict[str, JobContract] = {}
_FROZEN_KEYS: set[str] = set()


def register_job(contract: JobContract) -> None:
    """Register a job contract in the authoritative registry.

    Raises ValueError if the job_key is already registered (keys are immutable).
    """
    if contract.job_key in _REGISTRY:
        raise ValueError(
            f"Job key '{contract.job_key}' is already registered. "
            "Job keys are immutable once released."
        )
    contract.model_validate(contract.model_dump())
    _REGISTRY[contract.job_key] = contract
    _FROZEN_KEYS.add(contract.job_key)


def supplycore_authoritative_job_registry() -> dict[str, JobContract]:
    """Return the complete authoritative job registry.

    This is the ONLY function that should be used to enumerate managed jobs.
    Returns a shallow copy to prevent external mutation.
    """
    return dict(_REGISTRY)


def get_job(job_key: str) -> JobContract:
    """Look up a single job by key.

    Raises KeyError if the job is not in the authoritative registry.
    """
    if job_key not in _REGISTRY:
        raise KeyError(
            f"Job '{job_key}' not found in authoritative registry. "
            "Only registered jobs may be executed."
        )
    return _REGISTRY[job_key]


def is_registered(job_key: str) -> bool:
    """Check if a job key exists in the registry."""
    return job_key in _REGISTRY


def registered_job_keys() -> list[str]:
    """Return all registered job keys, sorted."""
    return sorted(_REGISTRY.keys())


def _reset_registry_for_testing() -> None:
    """Clear the registry. FOR TESTING ONLY — never call in production."""
    _REGISTRY.clear()
    _FROZEN_KEYS.clear()
