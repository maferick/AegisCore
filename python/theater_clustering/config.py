"""Env-driven config for theater_clustering. Matches the DB_* convention
used by every other Python worker in the project."""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Config:
    # MariaDB — same credentials other workers use.
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # Clustering knobs. Defaults match ADR-0006 § 3 (45-minute proximity
    # window, 10-pilot minimum, 48-hour lock horizon, 5-minute scheduler
    # cadence). Overrideable via env for operator tuning without a
    # deploy.
    proximity_seconds: int          # default 2700 (45 min)
    min_participants: int           # default 10
    lock_after_hours: int           # default 48
    window_hours: int               # default 48 — candidate window per pass
    scheduler_interval_seconds: int # default 300 — how often loop mode fires

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
            proximity_seconds=int(env("THEATER_PROXIMITY_SECONDS", "2700") or "2700"),
            min_participants=int(env("THEATER_MIN_PARTICIPANTS", "10") or "10"),
            lock_after_hours=int(env("THEATER_LOCK_AFTER_HOURS", "48") or "48"),
            window_hours=int(env("THEATER_WINDOW_HOURS", "48") or "48"),
            scheduler_interval_seconds=int(env("THEATER_SCHEDULER_INTERVAL_SECONDS", "300") or "300"),
        )
