"""Transactional write-back of sub-fleet headers + membership rows.

All writes for one invocation land in a single MariaDB transaction;
partial output is never left behind. Both the header and membership
UPSERTs refresh computed_at explicitly so re-runs reflect the current
compute time, not the first insert.
"""

from __future__ import annotations

from datetime import datetime, timezone

import pymysql

from battle_partition.partition import MembershipRow, SubFleetHeader


_UPSERT_SUB_FLEET = """
INSERT INTO battle_sub_fleets
  (battle_id, alliance_id, sub_fleet_id,
   member_count, partition_algo_version,
   source_edge_profile_version, source_algo_profile_version,
   absorbed_orphan_count, computed_at)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
    member_count = VALUES(member_count),
    partition_algo_version = VALUES(partition_algo_version),
    source_edge_profile_version = VALUES(source_edge_profile_version),
    source_algo_profile_version = VALUES(source_algo_profile_version),
    absorbed_orphan_count = VALUES(absorbed_orphan_count),
    computed_at = VALUES(computed_at)
"""

_UPSERT_MEMBERSHIP = """
INSERT INTO battle_character_sub_fleet_membership
  (battle_id, alliance_id, character_id, partition_algo_version,
   sub_fleet_id, membership_share, assignment_method,
   source_edge_profile_version, source_algo_profile_version,
   source_community_id_raw, source_community_size, was_orphan, computed_at)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
    sub_fleet_id = VALUES(sub_fleet_id),
    membership_share = VALUES(membership_share),
    assignment_method = VALUES(assignment_method),
    source_edge_profile_version = VALUES(source_edge_profile_version),
    source_algo_profile_version = VALUES(source_algo_profile_version),
    source_community_id_raw = VALUES(source_community_id_raw),
    source_community_size = VALUES(source_community_size),
    was_orphan = VALUES(was_orphan),
    computed_at = VALUES(computed_at)
"""


def write_partition(
    conn: pymysql.connections.Connection,
    *,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
    edge_profile_version: int,
    algo_profile_version: int,
    sub_fleets: list[SubFleetHeader],
    memberships: list[MembershipRow],
) -> None:
    """Single-transaction write of headers + memberships. Rolls back
    the entire batch on any failure so partial output is impossible."""
    now = datetime.now(timezone.utc).replace(tzinfo=None)

    sf_params = [
        (
            battle_id, alliance_id, sf.sub_fleet_id,
            sf.member_count, partition_algo_version,
            edge_profile_version, algo_profile_version,
            sf.absorbed_orphan_count, now,
        )
        for sf in sub_fleets
    ]

    m_params = [
        (
            battle_id, alliance_id, m.character_id, partition_algo_version,
            m.sub_fleet_id, 1.0, m.assignment_method,
            edge_profile_version, algo_profile_version,
            m.source_community_id_raw, m.source_community_size,
            1 if m.was_orphan else 0, now,
        )
        for m in memberships
    ]

    try:
        with conn.cursor() as cur:
            if sf_params:
                # Sub-fleet headers must land before membership rows
                # because the FK from membership → sub_fleets is
                # validated at INSERT time inside InnoDB's normal
                # foreign-key enforcement.
                cur.executemany(_UPSERT_SUB_FLEET, sf_params)
            if m_params:
                cur.executemany(_UPSERT_MEMBERSHIP, m_params)
        conn.commit()
    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise
