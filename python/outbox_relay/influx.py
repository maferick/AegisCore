"""Thin synchronous-write wrapper around the official influxdb-client.

Why a wrapper at all (vs. callers building Points + calling
write_api.write directly):

  - Single authority on bucket / org / synchronous-vs-batched mode.
    Projectors don't need to know the InfluxDB connection topology.
  - One place to add retry / dead-letter / backoff logic when /
    if needed (TODO marker, not implemented).
  - One place to decide between SYNCHRONOUS (commit per write,
    simple) vs BATCHING (background flush, lower latency, harder
    failure semantics). Phase-1 we go SYNCHRONOUS — the relay
    already processes outbox events serially within a batch, so
    batching at the InfluxDB layer wouldn't add throughput.

Connection lifecycle: one `InfluxClient` per relay process; lives
as long as the process. No per-batch open/close.
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator, Sequence

from influxdb_client import InfluxDBClient, Point
from influxdb_client.client.write_api import SYNCHRONOUS

from outbox_relay.config import Config
from outbox_relay.log import get


log = get(__name__)


@contextmanager
def client(cfg: Config) -> Iterator["InfluxClient"]:
    """Open a connected InfluxClient for the lifetime of a `with` block.

    Caller scope is "one relay process" — the client is created at
    process start and closed at process exit. Inside the block,
    `write(measurement_points)` is the only thing projectors need."""
    sdk_client = InfluxDBClient(
        url=cfg.influx_host,
        token=cfg.influx_token,
        org=cfg.influx_org,
        # 30s default isn't enough for the first-run market_history
        # backfill projection, which may write tens of thousands of
        # points in one batch. Bump to 120s.
        timeout=120_000,
    )
    write_api = sdk_client.write_api(write_options=SYNCHRONOUS)
    log.info(
        "connected to influxdb",
        host=cfg.influx_host,
        org=cfg.influx_org,
        bucket=cfg.influx_bucket,
    )
    try:
        yield InfluxClient(sdk_client, write_api, bucket=cfg.influx_bucket)
    finally:
        # close() also flushes any in-flight writes (no-op under
        # SYNCHRONOUS mode but cheap and consistent).
        write_api.close()
        sdk_client.close()


class InfluxClient:
    """Narrow facade — projectors only use `write()`. Adding more
    methods (query, delete, etc.) is fine when a real caller needs
    them; YAGNI for now."""

    def __init__(self, sdk_client: InfluxDBClient, write_api, *, bucket: str) -> None:
        self._sdk = sdk_client
        self._write_api = write_api
        self._bucket = bucket

    def write(self, points: Sequence[Point]) -> int:
        """Write a batch of points to the configured bucket. Returns
        the number of points sent for caller logging.

        On failure, raises whatever the influxdb-client surfaced —
        the relay catches it, leaves the outbox row unprocessed, and
        moves on. Next pass will retry (until max_attempts).
        """
        if not points:
            return 0
        self._write_api.write(bucket=self._bucket, record=list(points))
        return len(points)
