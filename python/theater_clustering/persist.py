"""DB read + write for theater_clustering.

Division of labour:

  - `load_candidates()` reads the killmail universe the clusterer
    operates on.
  - `persist_clusters()` rebuilds battle_theaters + child tables for
    every UNLOCKED theater in one transaction. Locked theaters are
    untouched.
  - `lock_aged_theaters()` flips `locked_at` on theaters whose
    `end_time` is older than `lock_after_hours` — the publication
    horizon from ADR-0006.

Design: rebuild-in-place for unlocked state. Before each pass we wipe
every unlocked theater and its child rows, then re-insert from the
clustering output. Simpler than diffing; idempotent; safe to re-run.
Locked theaters are excluded from the wipe so their frozen rows
survive untouched.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import pymysql

from theater_clustering.clusterer import Attacker, Cluster, Killmail
from theater_clustering.config import Config
from theater_clustering.log import get


log = get(__name__)


@dataclass
class TheaterStats:
    primary_system_id: int
    region_id: int
    start_time: datetime
    end_time: datetime
    total_kills: int
    total_isk_lost: float
    participant_count: int
    system_count: int
    per_system: dict[int, dict]            # system_id -> {kill_count, isk_lost, first_kill_at, last_kill_at}
    per_participant: dict[int, dict]       # character_id -> pilot metrics row


def load_candidates(
    conn: pymysql.connections.Connection,
    window_hours: int,
) -> tuple[list[Killmail], dict[int, list[Attacker]]]:
    """Fetch the killmail universe for a single clustering pass.

    Candidate pool:
      - killed_at >= now - window_hours
      - enriched_at IS NOT NULL (need region_id / constellation_id /
        total_value to cluster and rollup correctly)
      - NOT a member of a locked theater (exclude rows the publication
        horizon has already frozen)
    """
    cutoff = datetime.now(timezone.utc) - timedelta(hours=window_hours)

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT k.killmail_id, k.solar_system_id, k.constellation_id,
                   k.region_id, k.killed_at, k.total_value,
                   k.victim_character_id, k.victim_damage_taken
            FROM killmails k
            LEFT JOIN battle_theater_killmails btk ON btk.killmail_id = k.killmail_id
            LEFT JOIN battle_theaters bt ON bt.id = btk.theater_id AND bt.locked_at IS NOT NULL
            WHERE k.killed_at >= %s
              AND k.enriched_at IS NOT NULL
              AND k.constellation_id > 0
              AND bt.id IS NULL
            """,
            (cutoff,),
        )
        rows = cur.fetchall() or []

        killmails = [
            Killmail(
                killmail_id=int(r["killmail_id"]),
                solar_system_id=int(r["solar_system_id"] or 0),
                constellation_id=int(r["constellation_id"] or 0),
                region_id=int(r["region_id"] or 0),
                killed_at=r["killed_at"],
                total_value=float(r["total_value"] or 0),
                victim_character_id=int(r["victim_character_id"]) if r["victim_character_id"] else None,
                victim_damage_taken=int(r["victim_damage_taken"] or 0),
            )
            for r in rows
        ]

        if not killmails:
            return [], {}

        kids = [k.killmail_id for k in killmails]
        # pymysql expands IN clauses via tuple substitution; chunk to
        # avoid a gigantic single statement on large windows.
        attackers: dict[int, list[Attacker]] = defaultdict(list)
        chunk = 5000
        for i in range(0, len(kids), chunk):
            batch = kids[i:i + chunk]
            cur.execute(
                """
                SELECT killmail_id, character_id, corporation_id, alliance_id,
                       is_final_blow, damage_done
                FROM killmail_attackers
                WHERE killmail_id IN ({placeholders})
                """.format(placeholders=",".join(["%s"] * len(batch))),
                tuple(batch),
            )
            for a in cur.fetchall() or []:
                attackers[int(a["killmail_id"])].append(Attacker(
                    killmail_id=int(a["killmail_id"]),
                    character_id=int(a["character_id"]) if a["character_id"] else None,
                    corporation_id=int(a["corporation_id"]) if a["corporation_id"] else None,
                    alliance_id=int(a["alliance_id"]) if a["alliance_id"] else None,
                    final_blow=bool(a["is_final_blow"]),
                    damage_done=int(a["damage_done"] or 0),
                ))

    return killmails, attackers


def compute_stats(
    cluster: Cluster,
    kms_by_id: dict[int, Killmail],
    attackers_by_killmail: dict[int, list[Attacker]],
) -> TheaterStats:
    """Materialise the rollup columns from a cluster's raw killmail set.

    Pilot metrics follow ADR-0006 § 1 exactly:
      - kills: any appearance in the attacker list (one +1 per
        killmail, not per row — zero-damage EWAR counts).
      - final_blows: subset of kills with final_blow=True.
      - damage_done: sum of the pilot's attacker rows' damage_done.
      - damage_taken: damage_taken from the victim row when the pilot
        is the victim.
      - deaths: victim count.
      - isk_lost: sum of killmail total_value where pilot is victim.
    """
    kms = [kms_by_id[kid] for kid in cluster.killmail_ids]
    kms.sort(key=lambda km: km.killed_at)

    total_kills = len(kms)
    total_isk = sum(km.total_value for km in kms)
    start_time = kms[0].killed_at
    end_time = kms[-1].killed_at

    # Per-system rollup.
    per_system: dict[int, dict] = {}
    for km in kms:
        row = per_system.setdefault(km.solar_system_id, {
            "kill_count": 0,
            "isk_lost": 0.0,
            "first_kill_at": km.killed_at,
            "last_kill_at": km.killed_at,
        })
        row["kill_count"] += 1
        row["isk_lost"] += km.total_value
        if km.killed_at < row["first_kill_at"]:
            row["first_kill_at"] = km.killed_at
        if km.killed_at > row["last_kill_at"]:
            row["last_kill_at"] = km.killed_at

    # Primary system = the one with the most kills (tiebreak: most ISK).
    primary_system_id = max(
        per_system.items(),
        key=lambda kv: (kv[1]["kill_count"], kv[1]["isk_lost"]),
    )[0]
    region_id = next(km.region_id for km in kms if km.solar_system_id == primary_system_id)

    # Per-participant rollup. Walk every killmail exactly once for the
    # victim stats and every attacker row once for the attacker stats.
    participants: dict[int, dict] = {}

    def ensure(char_id: int, corp_id: int | None, alliance_id: int | None) -> dict:
        row = participants.get(char_id)
        if row is None:
            row = participants[char_id] = {
                "corporation_id": corp_id,
                "alliance_id": alliance_id,
                "kills": 0,
                "final_blows": 0,
                "damage_done": 0,
                "damage_taken": 0,
                "deaths": 0,
                "isk_lost": 0.0,
                "first_seen_at": None,
                "last_seen_at": None,
            }
        else:
            # Keep the most recently-seen non-null affiliation — we
            # effectively take the LAST observation across the theater's
            # killmails, which is fine for "what corp were they in during
            # this fight" given the killmails are within hours of each
            # other.
            if corp_id is not None:
                row["corporation_id"] = corp_id
            if alliance_id is not None:
                row["alliance_id"] = alliance_id
        return row

    def stamp(row: dict, ts: datetime) -> None:
        if row["first_seen_at"] is None or ts < row["first_seen_at"]:
            row["first_seen_at"] = ts
        if row["last_seen_at"] is None or ts > row["last_seen_at"]:
            row["last_seen_at"] = ts

    for km in kms:
        # Victim side.
        if km.victim_character_id:
            row = ensure(km.victim_character_id, None, None)
            row["deaths"] += 1
            row["damage_taken"] += km.victim_damage_taken
            row["isk_lost"] += km.total_value
            stamp(row, km.killed_at)

        # Attacker side. Track which pilots appear on THIS killmail so
        # each gets exactly +1 kill, regardless of how many attacker
        # rows they occupy (a pilot can legitimately be on a mail once).
        seen_on_this_mail: set[int] = set()
        for a in attackers_by_killmail.get(km.killmail_id, ()):
            if a.character_id is None:
                continue
            row = ensure(a.character_id, a.corporation_id, a.alliance_id)
            row["damage_done"] += a.damage_done
            if a.character_id not in seen_on_this_mail:
                row["kills"] += 1
                seen_on_this_mail.add(a.character_id)
            if a.final_blow:
                row["final_blows"] += 1
            stamp(row, km.killed_at)

    return TheaterStats(
        primary_system_id=primary_system_id,
        region_id=region_id,
        start_time=start_time,
        end_time=end_time,
        total_kills=total_kills,
        total_isk_lost=total_isk,
        participant_count=len(participants),
        system_count=len(per_system),
        per_system=per_system,
        per_participant=participants,
    )


def persist_clusters(
    conn: pymysql.connections.Connection,
    clusters: list[Cluster],
    kms_by_id: dict[int, Killmail],
    attackers_by_killmail: dict[int, list[Attacker]],
) -> tuple[int, int]:
    """Wipe unlocked theaters and re-insert from the cluster output.

    Returns (theaters_written, participants_written).
    """
    stats_by_cluster = [
        (c, compute_stats(c, kms_by_id, attackers_by_killmail))
        for c in clusters
    ]

    with conn.cursor() as cur:
        # Wipe every unlocked theater and its child rows. The pivot /
        # system / participant tables cascade on theater deletion, so
        # the one DELETE is enough.
        cur.execute("DELETE FROM battle_theaters WHERE locked_at IS NULL")

        theaters_written = 0
        participants_written = 0

        for cluster, s in stats_by_cluster:
            cur.execute(
                """
                INSERT INTO battle_theaters
                  (primary_system_id, region_id, start_time, end_time,
                   total_kills, total_isk_lost, participant_count,
                   system_count, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                """,
                (
                    s.primary_system_id, s.region_id,
                    s.start_time, s.end_time,
                    s.total_kills, s.total_isk_lost,
                    s.participant_count, s.system_count,
                ),
            )
            theater_id = cur.lastrowid
            theaters_written += 1

            # Pivot rows.
            cur.executemany(
                "INSERT INTO battle_theater_killmails (theater_id, killmail_id) VALUES (%s, %s)",
                [(theater_id, kid) for kid in cluster.killmail_ids],
            )

            # System rollups.
            cur.executemany(
                """
                INSERT INTO battle_theater_systems
                  (theater_id, solar_system_id, kill_count, isk_lost,
                   first_kill_at, last_kill_at, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, NOW(), NOW())
                """,
                [
                    (theater_id, sid, row["kill_count"], row["isk_lost"],
                     row["first_kill_at"], row["last_kill_at"])
                    for sid, row in s.per_system.items()
                ],
            )

            # Participant rollups.
            cur.executemany(
                """
                INSERT INTO battle_theater_participants
                  (theater_id, character_id, corporation_id, alliance_id,
                   kills, final_blows, damage_done, damage_taken,
                   deaths, isk_lost, first_seen_at, last_seen_at,
                   created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                """,
                [
                    (
                        theater_id, char_id,
                        row["corporation_id"], row["alliance_id"],
                        row["kills"], row["final_blows"],
                        row["damage_done"], row["damage_taken"],
                        row["deaths"], row["isk_lost"],
                        row["first_seen_at"], row["last_seen_at"],
                    )
                    for char_id, row in s.per_participant.items()
                ],
            )
            participants_written += len(s.per_participant)

        conn.commit()
        return theaters_written, participants_written


def lock_aged_theaters(
    conn: pymysql.connections.Connection,
    lock_after_hours: int,
) -> int:
    """Flip `locked_at` on theaters whose end_time has aged past the
    publication horizon. Snapshot generation is deferred to a separate
    step (the snapshot_json column can be populated lazily by the Laravel
    read path the first time a locked theater is requested)."""
    cutoff = datetime.now(timezone.utc) - timedelta(hours=lock_after_hours)
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE battle_theaters
               SET locked_at = NOW()
             WHERE locked_at IS NULL
               AND end_time < %s
            """,
            (cutoff,),
        )
        locked = cur.rowcount or 0
        conn.commit()
        return locked
