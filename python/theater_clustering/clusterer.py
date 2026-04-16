"""Core clustering algorithm — union-find over (constellation, time proximity).

See docs/adr/0006-battle-theater-reports.md § 3. Each pass:

  1. Load candidate killmails (last `window_hours`, enriched, not in a
     locked theater).
  2. Sort by constellation + killed_at.
  3. Union-find: merge two killmails whose constellation matches and
     whose time-delta is ≤ `proximity_seconds`.
  4. Drop clusters with < `min_participants` unique character_ids.

The clusterer is pure data-in / data-out. It does not touch the DB.
Persistence + lock-horizon logic live in `persist.py`.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass, field
from datetime import datetime
from typing import Iterable


@dataclass
class Killmail:
    killmail_id: int
    solar_system_id: int
    constellation_id: int
    region_id: int
    killed_at: datetime
    total_value: float
    victim_character_id: int | None
    victim_damage_taken: int


@dataclass
class Attacker:
    killmail_id: int
    character_id: int | None
    corporation_id: int | None
    alliance_id: int | None
    final_blow: bool
    damage_done: int


@dataclass
class Cluster:
    killmail_ids: set[int] = field(default_factory=set)
    participant_character_ids: set[int] = field(default_factory=set)

    def absorb(self, other: "Cluster") -> None:
        self.killmail_ids.update(other.killmail_ids)
        self.participant_character_ids.update(other.participant_character_ids)


class _UnionFind:
    """Classic union-find with path compression. Keyed on killmail_id."""

    def __init__(self, keys: Iterable[int]) -> None:
        self._parent: dict[int, int] = {k: k for k in keys}

    def find(self, k: int) -> int:
        parent = self._parent
        # iterative path compression — avoids recursion depth on long
        # chains of chronologically adjacent killmails in a single fight
        root = k
        while parent[root] != root:
            root = parent[root]
        while parent[k] != root:
            parent[k], k = root, parent[k]
        return root

    def union(self, a: int, b: int) -> None:
        ra, rb = self.find(a), self.find(b)
        if ra != rb:
            self._parent[ra] = rb

    def groups(self) -> dict[int, list[int]]:
        out: dict[int, list[int]] = defaultdict(list)
        for k in self._parent:
            out[self.find(k)].append(k)
        return out


def cluster_killmails(
    killmails: list[Killmail],
    attackers_by_killmail: dict[int, list[Attacker]],
    proximity_seconds: int,
    min_participants: int,
) -> list[Cluster]:
    """Return the set of clusters meeting the min-participants threshold.

    Clusters are order-independent — the caller decides how to sort them
    for display / persistence.
    """
    if not killmails:
        return []

    # 1. Union-find seeded with every candidate killmail as its own
    #    singleton.
    uf = _UnionFind(km.killmail_id for km in killmails)

    # 2. Sort by (constellation_id, killed_at). Within a constellation
    #    sweep a sliding window; any two killmails whose timestamps are
    #    within proximity_seconds get unioned. This is O(n log n) sort
    #    + O(n) per-constellation linear scan because the window's tail
    #    advances monotonically.
    sorted_kms = sorted(killmails, key=lambda km: (km.constellation_id, km.killed_at))

    window_start = 0  # index into sorted_kms
    for i in range(len(sorted_kms)):
        km_i = sorted_kms[i]
        # Advance window_start past any killmails outside the proximity
        # window, or in a different constellation.
        while window_start < i:
            km_w = sorted_kms[window_start]
            same_constellation = km_w.constellation_id == km_i.constellation_id
            in_window = (km_i.killed_at - km_w.killed_at).total_seconds() <= proximity_seconds
            if same_constellation and in_window:
                break
            window_start += 1

        # Every killmail still in [window_start, i-1] with the same
        # constellation unions with i.
        for j in range(window_start, i):
            km_j = sorted_kms[j]
            if km_j.constellation_id != km_i.constellation_id:
                continue
            # Proximity already guaranteed by the advance above when
            # constellations match.
            uf.union(km_i.killmail_id, km_j.killmail_id)

    # 3. Build cluster payloads and apply the participant threshold.
    kms_by_id = {km.killmail_id: km for km in killmails}
    raw_clusters: list[Cluster] = []
    for _root, member_ids in uf.groups().items():
        cluster = Cluster()
        for kid in member_ids:
            cluster.killmail_ids.add(kid)
            km = kms_by_id[kid]
            if km.victim_character_id:
                cluster.participant_character_ids.add(km.victim_character_id)
            for a in attackers_by_killmail.get(kid, ()):
                if a.character_id:
                    cluster.participant_character_ids.add(a.character_id)
        if len(cluster.participant_character_ids) >= min_participants:
            raw_clusters.append(cluster)

    return raw_clusters
