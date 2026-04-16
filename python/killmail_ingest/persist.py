"""Write killmails + attackers + items to MariaDB.

Single entry point: ingest_killmail(). Idempotent via INSERT ... ON
DUPLICATE KEY UPDATE on the killmails natural PK (killmail_id).
Attackers and items use delete + re-insert since the ESI payload is
the full truth.

All writes happen within the caller's transaction — the caller owns
COMMIT / ROLLBACK.
"""

from __future__ import annotations

from datetime import datetime, timezone

import pymysql

from killmail_ingest.log import get
from killmail_ingest.parse import ParsedKillmail


log = get(__name__)


def ingest_killmail(
    conn: pymysql.connections.Connection,
    km: ParsedKillmail,
) -> bool:
    """Insert one killmail + attackers + items.

    Returns True if new insert, False if updated existing row.
    Must be called inside an open transaction (autocommit=False).
    """
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    killed_at = km.killed_at.strftime("%Y-%m-%d %H:%M:%S")

    with conn.cursor() as cur:
        # 1. Upsert killmail.
        cur.execute(
            """
            INSERT INTO killmails
                (killmail_id, killmail_hash, solar_system_id, constellation_id,
                 region_id, killed_at, victim_character_id, victim_corporation_id,
                 victim_alliance_id, victim_ship_type_id, victim_damage_taken,
                 attacker_count, is_npc_kill, is_solo_kill, war_id,
                 ingested_at, created_at, updated_at)
            VALUES (%s, %s, %s, 0, 0, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                killmail_hash = VALUES(killmail_hash),
                solar_system_id = VALUES(solar_system_id),
                victim_character_id = VALUES(victim_character_id),
                victim_corporation_id = VALUES(victim_corporation_id),
                victim_alliance_id = VALUES(victim_alliance_id),
                victim_ship_type_id = VALUES(victim_ship_type_id),
                victim_damage_taken = VALUES(victim_damage_taken),
                attacker_count = VALUES(attacker_count),
                is_npc_kill = VALUES(is_npc_kill),
                is_solo_kill = VALUES(is_solo_kill),
                war_id = VALUES(war_id),
                updated_at = VALUES(updated_at)
            """,
            (
                km.killmail_id, km.killmail_hash, km.solar_system_id,
                killed_at,
                km.victim_character_id, km.victim_corporation_id,
                km.victim_alliance_id, km.victim_ship_type_id,
                km.victim_damage_taken, km.attacker_count,
                int(km.is_npc_kill), int(km.is_solo_kill), km.war_id,
                now, now, now,
            ),
        )
        # MariaDB: rowcount 1 = insert, 2 = update.
        was_new = cur.rowcount == 1

        # 2. Replace attackers.
        cur.execute("DELETE FROM killmail_attackers WHERE killmail_id = %s", (km.killmail_id,))

        if km.attackers:
            cur.executemany(
                """
                INSERT INTO killmail_attackers
                    (killmail_id, character_id, corporation_id, alliance_id,
                     faction_id, ship_type_id, weapon_type_id, damage_done,
                     is_final_blow, security_status, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                [
                    (
                        km.killmail_id,
                        att.character_id, att.corporation_id, att.alliance_id,
                        att.faction_id, att.ship_type_id, att.weapon_type_id,
                        att.damage_done, int(att.is_final_blow),
                        att.security_status,
                        now, now,
                    )
                    for att in km.attackers
                ],
            )

        # 3. Replace items.
        cur.execute("DELETE FROM killmail_items WHERE killmail_id = %s", (km.killmail_id,))

        if km.items:
            cur.executemany(
                """
                INSERT INTO killmail_items
                    (killmail_id, type_id, flag, quantity_destroyed,
                     quantity_dropped, singleton, slot_category,
                     created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                [
                    (
                        km.killmail_id, item.type_id, item.flag,
                        item.quantity_destroyed, item.quantity_dropped,
                        item.singleton, item.slot_category,
                        now, now,
                    )
                    for item in km.items
                ],
            )

    return was_new
