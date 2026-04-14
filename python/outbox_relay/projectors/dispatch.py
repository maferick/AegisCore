"""Routing table from outbox `event_type` → projector function.

Adding a new event type means landing a new module under
`projectors/` and adding one entry to `PROJECTOR_REGISTRY`. The
relay framework itself never knows about specific event types.

Unknown event_types are NOT errors — the outbox is shared with
future projectors / consumers (Neo4j, OpenSearch). The relay logs
+ skips (without marking processed) so a future projector
deployment can pick them up. The `attempts` column doesn't tick
for skipped-as-unknown events because we never claim them in the
first place — see relay.py's WHERE clause on the SELECT.
"""

from __future__ import annotations

from typing import Callable, Mapping

import pymysql

from outbox_relay.influx import InfluxClient
from outbox_relay.log import _KvLogger as Log
from outbox_relay.projectors import market_history, market_orders


# Type signature every projector implements. Returning point-count
# is for log-line richness, not control-flow.
ProjectorFn = Callable[
    [pymysql.connections.Connection, InfluxClient, Mapping[str, object], Log],
    int,
]


PROJECTOR_REGISTRY: dict[str, ProjectorFn] = {
    "market.history_snapshot_loaded": market_history.project,
    "market.orders_snapshot_ingested": market_orders.project,
}


def known_event_types() -> frozenset[str]:
    """Used by the relay's claim WHERE to filter only events this
    deployment knows how to project — leaves unknown types in the
    outbox for a future projector instance."""
    return frozenset(PROJECTOR_REGISTRY)
