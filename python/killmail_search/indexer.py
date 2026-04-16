"""Bulk index enriched killmails from MariaDB into OpenSearch."""

from __future__ import annotations

from opensearchpy import OpenSearch, helpers

import pymysql

from killmail_search.config import Config
from killmail_search.db import connect
from killmail_search.index import create_client, ensure_index
from killmail_search.log import get

log = get(__name__)


def run_backfill(cfg: Config) -> int:
    """Index all enriched killmails that aren't in OpenSearch yet.

    Uses a cursor-based approach: tracks the last indexed killmail_id
    and only processes newer ones.
    """
    client = create_client(cfg)
    ensure_index(client, cfg.opensearch_index)

    with connect(cfg) as conn:
        # Find the highest killmail_id already in OpenSearch.
        last_indexed = _get_max_indexed_id(client, cfg.opensearch_index)
        log.info("starting backfill", after_killmail_id=last_indexed)

        total_indexed = 0

        while True:
            killmails = _fetch_batch(conn, last_indexed, cfg.batch_size)

            if not killmails:
                break

            docs = [_killmail_to_doc(conn, km) for km in killmails]
            docs = [d for d in docs if d is not None]

            if docs:
                actions = [
                    {
                        "_index": cfg.opensearch_index,
                        "_id": str(doc["killmail_id"]),
                        "_source": doc,
                    }
                    for doc in docs
                ]

                if not cfg.dry_run:
                    success, errors = helpers.bulk(client, actions, raise_on_error=False)
                    if errors:
                        log.warning("bulk index errors", count=len(errors))
                else:
                    success = len(actions)

                total_indexed += success

            last_indexed = killmails[-1]["killmail_id"]

            log.info(
                "batch indexed",
                count=len(docs),
                total=total_indexed,
                cursor=last_indexed,
            )

        log.info("backfill complete", total_indexed=total_indexed)

    return 0


def _get_max_indexed_id(client: OpenSearch, index_name: str) -> int:
    """Get the highest killmail_id currently in the index."""
    try:
        result = client.search(
            index=index_name,
            body={
                "size": 0,
                "aggs": {"max_id": {"max": {"field": "killmail_id"}}},
            },
        )
        val = result["aggregations"]["max_id"]["value"]
        return int(val) if val else 0
    except Exception:
        return 0


def _fetch_batch(
    conn: pymysql.connections.Connection,
    after_id: int,
    batch_size: int,
) -> list[dict]:
    """Fetch the next batch of enriched killmails from MariaDB."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT k.killmail_id, k.killed_at, k.solar_system_id,
                   k.constellation_id, k.region_id,
                   k.victim_character_id, k.victim_corporation_id,
                   k.victim_alliance_id, k.victim_ship_type_id,
                   k.victim_ship_type_name, k.victim_ship_group_name,
                   k.victim_ship_category_name, k.victim_damage_taken,
                   k.total_value, k.hull_value, k.fitted_value,
                   k.cargo_value, k.drone_value,
                   k.attacker_count, k.is_npc_kill, k.is_solo_kill
            FROM killmails k
            WHERE k.enriched_at IS NOT NULL
              AND k.killmail_id > %s
            ORDER BY k.killmail_id
            LIMIT %s
            """,
            (after_id, batch_size),
        )
        return list(cur.fetchall())


def _killmail_to_doc(conn: pymysql.connections.Connection, km: dict) -> dict | None:
    """Transform a killmail row into an OpenSearch document."""
    killmail_id = km["killmail_id"]

    # Resolve entity names.
    entity_ids = [
        km["victim_character_id"],
        km["victim_corporation_id"],
        km["victim_alliance_id"],
    ]
    entity_ids = [eid for eid in entity_ids if eid]

    names = {}
    if entity_ids:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT entity_id, name FROM esi_entity_names WHERE entity_id IN %s",
                (entity_ids,),
            )
            names = {row["entity_id"]: row["name"] for row in cur.fetchall()}

    # Resolve region/system names.
    region_name = None
    system_name = None
    with conn.cursor() as cur:
        if km["region_id"]:
            cur.execute("SELECT name FROM ref_regions WHERE id = %s", (km["region_id"],))
            row = cur.fetchone()
            if row:
                region_name = row["name"]
        if km["solar_system_id"]:
            cur.execute("SELECT name FROM ref_solar_systems WHERE id = %s", (km["solar_system_id"],))
            row = cur.fetchone()
            if row:
                system_name = row["name"]

    # Final blow attacker.
    fb_char_name = None
    fb_corp_name = None
    fb_ship_name = None
    attacker_char_ids = []
    attacker_corp_ids = []
    attacker_alliance_ids = []

    with conn.cursor() as cur:
        cur.execute(
            """SELECT character_id, corporation_id, alliance_id,
                      ship_type_id, is_final_blow
               FROM killmail_attackers WHERE killmail_id = %s""",
            (killmail_id,),
        )
        attackers = cur.fetchall()

    for att in attackers:
        if att["character_id"]:
            attacker_char_ids.append(att["character_id"])
        if att["corporation_id"]:
            attacker_corp_ids.append(att["corporation_id"])
        if att["alliance_id"]:
            attacker_alliance_ids.append(att["alliance_id"])

        if att["is_final_blow"]:
            if att["character_id"]:
                with conn.cursor() as cur:
                    cur.execute(
                        "SELECT name FROM esi_entity_names WHERE entity_id = %s",
                        (att["character_id"],),
                    )
                    row = cur.fetchone()
                    fb_char_name = row["name"] if row else None
            if att["corporation_id"]:
                with conn.cursor() as cur:
                    cur.execute(
                        "SELECT name FROM esi_entity_names WHERE entity_id = %s",
                        (att["corporation_id"],),
                    )
                    row = cur.fetchone()
                    fb_corp_name = row["name"] if row else None
            if att["ship_type_id"]:
                with conn.cursor() as cur:
                    cur.execute(
                        "SELECT name FROM ref_item_types WHERE id = %s",
                        (att["ship_type_id"],),
                    )
                    row = cur.fetchone()
                    fb_ship_name = row["name"] if row else None

    killed_at = km["killed_at"]
    if hasattr(killed_at, "isoformat"):
        killed_at = killed_at.isoformat()

    return {
        "killmail_id": killmail_id,
        "killed_at": killed_at,
        "solar_system_id": km["solar_system_id"],
        "constellation_id": km["constellation_id"],
        "region_id": km["region_id"],
        "region_name": region_name,
        "system_name": system_name,
        "victim_character_id": km["victim_character_id"],
        "victim_character_name": names.get(km["victim_character_id"]),
        "victim_corporation_id": km["victim_corporation_id"],
        "victim_corporation_name": names.get(km["victim_corporation_id"]),
        "victim_alliance_id": km["victim_alliance_id"],
        "victim_alliance_name": names.get(km["victim_alliance_id"]),
        "victim_ship_type_id": km["victim_ship_type_id"],
        "victim_ship_type_name": km["victim_ship_type_name"],
        "victim_ship_group_name": km["victim_ship_group_name"],
        "victim_ship_category_name": km["victim_ship_category_name"],
        "victim_damage_taken": km["victim_damage_taken"],
        "total_value": float(km["total_value"]) if km["total_value"] else 0.0,
        "hull_value": float(km["hull_value"]) if km["hull_value"] else 0.0,
        "fitted_value": float(km["fitted_value"]) if km["fitted_value"] else 0.0,
        "cargo_value": float(km["cargo_value"]) if km["cargo_value"] else 0.0,
        "drone_value": float(km["drone_value"]) if km["drone_value"] else 0.0,
        "attacker_count": km["attacker_count"],
        "is_npc_kill": bool(km["is_npc_kill"]),
        "is_solo_kill": bool(km["is_solo_kill"]),
        "final_blow_character_name": fb_char_name,
        "final_blow_corporation_name": fb_corp_name,
        "final_blow_ship_type_name": fb_ship_name,
        "attacker_character_ids": list(set(attacker_char_ids)),
        "attacker_corporation_ids": list(set(attacker_corp_ids)),
        "attacker_alliance_ids": list(set(attacker_alliance_ids)),
    }
