"""Env-driven config. DB credentials share the AegisCore Python worker
convention (DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD).

Tuning knobs exposed via CI_* env vars so operators can adjust without
a code change + redeploy.
"""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Config:
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # Rolling-window length for feature extraction. 90 days = default;
    # keeps active signal without burying pilots in ancient history.
    window_days: int

    # Minimum battles in window for a character to score as "has
    # sufficient history". Below this, row is written but flagged for
    # triage UI to badge "insufficient history" rather than score.
    min_battles_90d: int

    @classmethod
    def from_env(cls) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            v = os.environ.get(key)
            if not v:
                v = default
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        return cls(
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306") or "3306"),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            window_days=int(env("CI_WINDOW_DAYS", "90") or "90"),
            min_battles_90d=int(env("CI_MIN_BATTLES_90D", "5") or "5"),
        )
