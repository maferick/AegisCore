"""Load battle-local inputs from MariaDB.

Spec 2 treats the battle_theaters + battle_theater_killmails tables as
the canonical "battle" surface — battle_id passed to the job is a
theater id. Pilots on an alliance-side are distinct character_ids whose
alliance_id matches the requested side on any killmail in the theater.

Everything returned here is Python-native; no Neo4j involvement yet.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime

import pymysql


@dataclass(frozen=True)
class Battle:
    battle_id: int
    start_time: datetime
    end_time: datetime
    killmail_ids: tuple[int, ...]


@dataclass
class PilotEvents:
    character_id: int
    # Unix-epoch seconds for every kill the pilot was on the field for,
    # as attacker or victim. Deduplicated per killmail_id.
    event_times: list[int] = field(default_factory=list)
    # Victim character_ids the pilot was on the attacker side for.
    victims: set[int] = field(default_factory=set)


def load_battle(conn: pymysql.connections.Connection, battle_id: int) -> Battle | None:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, start_time, end_time FROM battle_theaters WHERE id=%s",
            (battle_id,),
        )
        bt = cur.fetchone()
        if bt is None:
            return None
        cur.execute(
            "SELECT killmail_id FROM battle_theater_killmails WHERE theater_id=%s",
            (battle_id,),
        )
        km_ids = tuple(int(r["killmail_id"]) for r in cur.fetchall())
    return Battle(
        battle_id=int(bt["id"]),
        start_time=bt["start_time"],
        end_time=bt["end_time"],
        killmail_ids=km_ids,
    )


def load_pilots_for_side(
    conn: pymysql.connections.Connection,
    battle: Battle,
    alliance_id: int,
) -> dict[int, PilotEvents]:
    """Return {character_id: PilotEvents} for every pilot on this
    alliance-side inside the battle. "On side" = appeared as attacker
    OR victim with this alliance_id on any killmail in the theater."""
    if not battle.killmail_ids:
        return {}

    kmids_placeholders = ",".join(["%s"] * len(battle.killmail_ids))
    pilots: dict[int, PilotEvents] = {}

    with conn.cursor() as cur:
        # Attacker rows — one event per (killmail, attacker).
        cur.execute(
            f"""
            SELECT a.character_id,
                   UNIX_TIMESTAMP(k.killed_at) AS ts,
                   k.victim_character_id
            FROM killmail_attackers a
            JOIN killmails k ON k.killmail_id = a.killmail_id
            WHERE a.killmail_id IN ({kmids_placeholders})
              AND a.alliance_id = %s
              AND a.character_id IS NOT NULL
            """,
            (*battle.killmail_ids, alliance_id),
        )
        for r in cur.fetchall():
            cid = int(r["character_id"])
            ev = pilots.setdefault(cid, PilotEvents(character_id=cid))
            ev.event_times.append(int(r["ts"]))
            if r["victim_character_id"] is not None:
                ev.victims.add(int(r["victim_character_id"]))

        # Victim rows — a pilot who only died on this side still counts.
        cur.execute(
            f"""
            SELECT k.victim_character_id AS character_id,
                   UNIX_TIMESTAMP(k.killed_at) AS ts
            FROM killmails k
            WHERE k.killmail_id IN ({kmids_placeholders})
              AND k.victim_alliance_id = %s
              AND k.victim_character_id IS NOT NULL
            """,
            (*battle.killmail_ids, alliance_id),
        )
        for r in cur.fetchall():
            cid = int(r["character_id"])
            ev = pilots.setdefault(cid, PilotEvents(character_id=cid))
            ev.event_times.append(int(r["ts"]))

    return pilots
