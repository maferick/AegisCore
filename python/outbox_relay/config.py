"""Runtime configuration for outbox_relay.

Same MariaDB env convention as the other Python services + InfluxDB
connection details (host/token/org/bucket) + relay-loop knobs.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, replace


@dataclass(frozen=True)
class Config:
    # MariaDB — outbox source + market_history / market_orders read-side.
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # InfluxDB — derived-store sink. Token must be one with write
    # access to `bucket` in `org`. The `host` env name matches the
    # PHP-side convention (config/aegiscore.php) so operators only
    # remember one set.
    influx_host: str
    influx_token: str
    influx_org: str
    influx_bucket: str

    # Relay loop knobs.
    #
    #   batch_size: how many outbox rows to claim per pass under
    #     `SELECT ... FOR UPDATE SKIP LOCKED LIMIT N`. Smaller =
    #     more frequent commits, less wasted work on a crash. Larger
    #     = fewer round-trips. 50 hits a sweet spot for steady-state
    #     market events arriving every few seconds.
    #
    #   max_attempts: an event that fails this many times in a row
    #     stops getting claimed (parked as a "dead letter"). Operator
    #     SELECTs it, fixes the projector / payload, resets attempts
    #     to 0, and the next pass re-processes. Without this guard a
    #     poisoned event would loop forever, eating relay capacity.
    #
    #   poll_interval: when the queue is empty the relay sleeps this
    #     many seconds before the next claim attempt. Default 5s
    #     keeps end-to-end latency low for new events without
    #     hammering MariaDB on an idle queue. The CLI's --interval
    #     overrides this for one-shot draining vs. long-lived loop
    #     mode.
    batch_size: int
    max_attempts: int
    poll_interval_seconds: int

    @classmethod
    def from_env(cls, **overrides) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            # Treat unset AND empty-string as "not provided" — same
            # gotcha PR #60 fixed in the market poller/importer
            # configs (compose's `${VAR:-}` expansion).
            v = os.environ.get(key)
            if not v:
                v = default
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        cfg = cls(
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306")),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            influx_host=env("INFLUXDB_HOST", "http://influxdb2:8086"),
            influx_token=env("INFLUXDB_TOKEN", required=True),
            influx_org=env("INFLUXDB_ORG", "aegiscore"),
            influx_bucket=env("INFLUXDB_BUCKET", "primary"),
            batch_size=int(env("OUTBOX_RELAY_BATCH_SIZE", "50")),
            max_attempts=int(env("OUTBOX_RELAY_MAX_ATTEMPTS", "5")),
            poll_interval_seconds=int(env("OUTBOX_RELAY_POLL_INTERVAL_SECONDS", "5")),
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg
