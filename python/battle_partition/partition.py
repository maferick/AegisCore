"""Deterministic partition rule.

Given a list of GraphMetric rows and a PartitionRule, produce:
  - sub-fleet header records (battle_sub_fleets writes)
  - per-character membership records
Every decision is reproducible from these inputs alone.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from battle_partition.inputs import GraphMetric
from battle_partition.profiles import PartitionRule


ASSIGN_LOUVAIN = "louvain_community"
ASSIGN_ORPHAN = "orphan_absorbed"
ASSIGN_SMALL_TIER = "small_tier_single_fleet"


@dataclass
class SubFleetHeader:
    sub_fleet_id: int
    member_count: int
    absorbed_orphan_count: int = 0


@dataclass
class MembershipRow:
    character_id: int
    sub_fleet_id: int
    assignment_method: str
    source_community_id_raw: int | None = None
    source_community_size: int | None = None
    was_orphan: bool = False


@dataclass
class PartitionResult:
    sub_fleets: list[SubFleetHeader] = field(default_factory=list)
    memberships: list[MembershipRow] = field(default_factory=list)
    # Diagnostic counts, not persisted; used by the CLI for logging.
    promoted_community_count: int = 0
    orphan_community_count: int = 0
    orphan_pilot_count: int = 0


def _is_small_tier(metrics: list[GraphMetric]) -> bool:
    return bool(metrics) and all(m.skip_reason == "below_min_pilots" for m in metrics)


def partition(metrics: list[GraphMetric], rule: PartitionRule) -> PartitionResult:
    """Apply the partition rule. Returns the header + membership record
    lists ready for persist.py to write inside a single transaction."""
    result = PartitionResult()
    if not metrics:
        return result

    # Small-tier path: every metric row carries skip_reason.
    if _is_small_tier(metrics):
        char_ids = sorted(m.character_id for m in metrics)
        result.sub_fleets.append(SubFleetHeader(
            sub_fleet_id=0,
            member_count=len(char_ids),
            absorbed_orphan_count=0,
        ))
        for cid in char_ids:
            result.memberships.append(MembershipRow(
                character_id=cid,
                sub_fleet_id=0,
                assignment_method=ASSIGN_SMALL_TIER,
            ))
        return result

    # Group metrics by raw community id, tracking size + min char id
    # (for deterministic tie-break) in a single pass.
    from collections import defaultdict
    by_community: dict[int, list[GraphMetric]] = defaultdict(list)
    for m in metrics:
        if m.community_id_raw is None:
            # Pilots with a null community in a non-small-tier battle
            # are data bugs from Spec 2; treat as their own orphan
            # singleton so they still land in sub-fleet 0.
            by_community[-(m.character_id)].append(m)
            continue
        by_community[m.community_id_raw].append(m)

    communities: list[tuple[int, int, int, list[GraphMetric]]] = []
    # Tuple: (community_id_raw, size, min_character_id, members)
    for comm_id, members in by_community.items():
        size = len(members)
        min_cid = min(m.character_id for m in members)
        communities.append((comm_id, size, min_cid, members))

    promoted = [c for c in communities if c[1] >= rule.min_community_size]
    orphans = [c for c in communities if c[1] < rule.min_community_size]
    result.promoted_community_count = len(promoted)
    result.orphan_community_count = len(orphans)
    orphan_pilot_count = sum(c[1] for c in orphans)
    result.orphan_pilot_count = orphan_pilot_count

    # Empty-promoted-set case: every community below threshold.
    if not promoted:
        total = sum(c[1] for c in communities)
        result.sub_fleets.append(SubFleetHeader(
            sub_fleet_id=0,
            member_count=total,
            absorbed_orphan_count=total,
        ))
        # Preserve original community id for diagnostics. Membership
        # rows are sorted by character_id for deterministic insert
        # order (not correctness, but makes diffs clean).
        flat: list[MembershipRow] = []
        for comm_id, _size, _min_cid, members in communities:
            for m in members:
                flat.append(MembershipRow(
                    character_id=m.character_id,
                    sub_fleet_id=0,
                    assignment_method=ASSIGN_ORPHAN,
                    source_community_id_raw=m.community_id_raw,
                    source_community_size=m.community_size,
                    was_orphan=True,
                ))
        flat.sort(key=lambda r: r.character_id)
        result.memberships.extend(flat)
        return result

    # Normal path. Rank promoted communities by (size DESC, min_cid ASC).
    promoted.sort(key=lambda c: (-c[1], c[2]))

    sub_fleet_by_comm: dict[int, int] = {}
    for rank, (comm_id, _size, _min_cid, _members) in enumerate(promoted):
        sub_fleet_by_comm[comm_id] = rank

    for rank, (_comm_id, size, _min_cid, _members) in enumerate(promoted):
        absorbed = orphan_pilot_count if rank == 0 else 0
        result.sub_fleets.append(SubFleetHeader(
            sub_fleet_id=rank,
            member_count=size + absorbed,
            absorbed_orphan_count=absorbed,
        ))

    flat_members: list[MembershipRow] = []
    for comm_id, _size, _min_cid, members in promoted:
        sub_fleet_id = sub_fleet_by_comm[comm_id]
        for m in members:
            flat_members.append(MembershipRow(
                character_id=m.character_id,
                sub_fleet_id=sub_fleet_id,
                assignment_method=ASSIGN_LOUVAIN,
                source_community_id_raw=m.community_id_raw,
                source_community_size=m.community_size,
                was_orphan=False,
            ))
    for _comm_id, _size, _min_cid, members in orphans:
        for m in members:
            flat_members.append(MembershipRow(
                character_id=m.character_id,
                sub_fleet_id=0,
                assignment_method=ASSIGN_ORPHAN,
                source_community_id_raw=m.community_id_raw,
                source_community_size=m.community_size,
                was_orphan=True,
            ))
    flat_members.sort(key=lambda r: r.character_id)
    result.memberships.extend(flat_members)

    return result
