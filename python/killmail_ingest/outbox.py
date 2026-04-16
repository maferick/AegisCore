"""Emit killmail.ingested events to the outbox.

Payload matches KillmailIngested.php V2 exactly so PHP and Python
consumers see an identical contract regardless of which plane
produced the event.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone

import pymysql
from ulid import ULID

from killmail_ingest.log import get
from killmail_ingest.parse import ParsedKillmail


log = get(__name__)

EVENT_TYPE = "killmail.ingested"
AGGREGATE_TYPE = "killmail"
PRODUCER = "killmail_ingest"
PAYLOAD_VERSION = 2


def emit_killmail_ingested(
    conn: pymysql.connections.Connection,
    *,
    km: ParsedKillmail,
) -> str:
    """Emit one killmail.ingested outbox event. Returns the event_id."""
    event_id = str(ULID())
    killed_at_iso = km.killed_at.strftime("%Y-%m-%dT%H:%M:%SZ")

    attacker_character_ids = [
        a.character_id for a in km.attackers if a.character_id
    ]

    payload = {
        "killmail_id": km.killmail_id,
        "killmail_hash": km.killmail_hash,
        "solar_system_id": km.solar_system_id,
        "region_id": 0,
        "victim_character_id": km.victim_character_id,
        "victim_corporation_id": km.victim_corporation_id,
        "victim_alliance_id": km.victim_alliance_id,
        "victim_ship_type_id": km.victim_ship_type_id,
        "attacker_character_ids": attacker_character_ids,
        "total_value": "0.00",
        "attacker_count": km.attacker_count,
        "killed_at": killed_at_iso,
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
                str(km.killmail_id),
                payload_json,
                PRODUCER,
                PAYLOAD_VERSION,
            ),
        )

    return event_id
