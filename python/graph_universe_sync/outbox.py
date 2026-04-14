"""Emit `graph.universe_projected` to the Laravel outbox.

Schema follows app/database/migrations/2026_04_13_000000_create_outbox_table.php.
Producer is pinned to "graph_universe_sync" so consumers can filter.

Payload shape (version 1):
    {
      "build_number": 3294658,
      "node_counts":  {"regions": 113, "constellations": 1156, ...},
      "edge_counts":  {"jumps_to": 7593, ...},
      "projected_at": "2026-04-14T11:29:37Z",
      "only_new_eden": true
    }
"""

from __future__ import annotations

import json
from datetime import datetime, timezone

import pymysql
from ulid import ULID

from graph_universe_sync.log import get


log = get(__name__)


EVENT_TYPE = "graph.universe_projected"
AGGREGATE_TYPE = "sde_snapshot"
PRODUCER = "graph_universe_sync"
PAYLOAD_VERSION = 1


def emit_universe_projected(
    conn: pymysql.connections.Connection,
    build_number: int | None,
    node_counts: dict[str, int],
    edge_counts: dict[str, int],
    only_new_eden: bool,
) -> str:
    """Insert one outbox row. Returns the event_id (ULID) for logging."""
    event_id = str(ULID())
    payload: dict = {
        "build_number": build_number,
        "node_counts": node_counts,
        "edge_counts": edge_counts,
        "projected_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "only_new_eden": only_new_eden,
    }
    payload_json = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)

    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO outbox
                (event_id, event_type, aggregate_type, aggregate_id,
                 payload, producer, version)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            """,
            (
                event_id,
                EVENT_TYPE,
                AGGREGATE_TYPE,
                str(build_number) if build_number is not None else "0",
                payload_json,
                PRODUCER,
                PAYLOAD_VERSION,
            ),
        )
    log.info(
        "outbox event emitted",
        event_id=event_id,
        event_type=EVENT_TYPE,
        aggregate_id=build_number,
        nodes_total=sum(node_counts.values()),
        edges_total=sum(edge_counts.values()),
    )
    return event_id
