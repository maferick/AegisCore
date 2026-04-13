"""Emit the `reference.sde_snapshot_loaded` event to the Laravel outbox.

Schema follows app/database/migrations/2026_04_13_000000_create_outbox_table.php
verbatim. Producer is pinned to "sde_importer" so consumers can filter.

Payload shape (version 1):
    {
      "build_number": 3294658,
      "release_date": "2026-04-09T11:29:37Z",
      "etag": "5743b7cb89928645788c46defd7c6535-10",
      "last_modified": "Thu, 09 Apr 2026 11:47:16 GMT",
      "rows_total": 663821,
      "tables": {"ref_regions": 113, "ref_constellations": 1156, …}
    }

Bump `PAYLOAD_VERSION` any time the shape changes in a non-additive way
and gate the new consumer behavior on it (docs/CONTRACTS.md § Payload
versioning).
"""

from __future__ import annotations

import json
from typing import Any

import pymysql
from ulid import ULID

from sde_importer.log import get

log = get(__name__)


EVENT_TYPE = "reference.sde_snapshot_loaded"
AGGREGATE_TYPE = "sde_snapshot"
PRODUCER = "sde_importer"
PAYLOAD_VERSION = 1


def emit_snapshot_loaded(
    conn: pymysql.connections.Connection,
    build_number: int,
    release_date: str | None,
    etag: str | None,
    last_modified: str | None,
    table_counts: dict[str, int],
) -> str:
    """Insert one outbox row. Returns the event_id (ULID) for logging."""
    event_id = str(ULID())
    payload: dict[str, Any] = {
        "build_number": build_number,
        "release_date": release_date,
        "etag": etag,
        "last_modified": last_modified,
        "rows_total": sum(table_counts.values()),
        "tables": table_counts,
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
                str(build_number),
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
        rows_total=payload["rows_total"],
    )
    return event_id
