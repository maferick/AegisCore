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
    quiet_split_seconds: int = 1200,
    cooldown_seconds: int = 600,
    continuity_floor: float = 0.2,
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
    #    Before emitting, chronologically walk each cluster and split
    #    whenever consecutive killmails are farther apart than
    #    quiet_split_seconds. A fleet going quiet for 20+ min almost
    #    always means the op ended and a new one later is a distinct
    #    event, even if the union-find proximity window bridged it.
    #
    #    TiDi compensation: at ≥1000-pilot fights the server applies
    #    time dilation (CCP's mechanic — slows gameplay when systems
    #    overload, down to 10% realtime). Under heavy TiDi one kill
    #    can take 5-10 minutes, so the natural gap between killmails
    #    stretches. Scale the split threshold with cluster participant
    #    count to avoid splitting legitimate Keepstar/supercap fights.
    kms_by_id = {km.killmail_id: km for km in killmails}
    raw_clusters: list[Cluster] = []
    for _root, member_ids in uf.groups().items():
        # Pre-count unique participants across the whole union-find
        # cluster so TiDi scaling uses the full-fight size, not a
        # subset.
        all_participants = set()
        for kid in member_ids:
            km = kms_by_id[kid]
            if km.victim_character_id:
                all_participants.add(km.victim_character_id)
            for a in attackers_by_killmail.get(kid, ()):
                if a.character_id:
                    all_participants.add(a.character_id)
        threshold = _tidi_scaled_split(quiet_split_seconds, len(all_participants))

        sub_clusters = _split_on_rate_and_continuity(
            member_ids,
            kms_by_id,
            attackers_by_killmail,
            hard_cap_seconds=threshold,
            cooldown_seconds=cooldown_seconds,
            continuity_floor=continuity_floor,
        )
        for cluster in sub_clusters:
            if len(cluster.participant_character_ids) >= min_participants:
                raw_clusters.append(cluster)

    return raw_clusters


def _tidi_scaled_split(base_seconds: int, participant_count: int) -> int:
    """Return quiet-split threshold scaled for TiDi-heavy fights.

    Breakpoints are empirical: most EVE fights stay under 500 pilots
    and see ~zero TiDi, so base (1200s) is fine. 500-1000 pilots
    triggers moderate TiDi (50-25% realtime); double. 1000-2000 pilots
    sees heavy TiDi (25-10%); triple. 2000+ (Keepstar / World War
    Bee territory) sustains 10% TiDi — one kill per 5-10 min is
    normal, so fold an extra-wide window."""
    if participant_count >= 2000:
        return base_seconds * 6   # 120 min baseline @ 1200s default
    if participant_count >= 1000:
        return base_seconds * 3   # 60 min baseline
    if participant_count >= 500:
        return base_seconds * 2   # 40 min baseline
    return base_seconds


def _split_on_rate_and_continuity(
    member_ids: list[int],
    kms_by_id: dict[int, Killmail],
    attackers_by_killmail: dict[int, list[Attacker]],
    hard_cap_seconds: int,
    cooldown_seconds: int,
    continuity_floor: float,
) -> list[Cluster]:
    """Walk members chronologically. Split on either:

      * a hard-cap silence (gap > hard_cap_seconds) — TiDi-scaled upper
        bound, matches legacy behaviour for edge cases like Keepstar
        fights.
      * an early-exit "fight died" signal: cooldown ≥ cooldown_seconds
        AND attacker-alliance+corp jaccard between the last 15-min-of-
        activity window and the most recent 15-min window < floor.

    Attacker-side jaccard only: victim-side churn is dominated by
    whoever died and is noisy. Crew continuity = attacker
    alliances + corps, alliance-weighted 2:1 over corps.
    """
    if not member_ids:
        return []
    ordered = sorted(member_ids, key=lambda kid: kms_by_id[kid].killed_at)
    clusters: list[Cluster] = []
    current = Cluster()
    last_ts = None
    last_active_ts = None
    for kid in ordered:
        km = kms_by_id[kid]
        should_split = False
        if last_ts is not None:
            gap = (km.killed_at - last_ts).total_seconds()
            if gap > hard_cap_seconds:
                should_split = True
            elif gap > cooldown_seconds and last_active_ts is not None:
                # Decline zone — check continuity. Freeze prev-window at
                # last_active_ts: [last_active_ts - 15m, last_active_ts].
                # "now" window = [km.killed_at - 15m, km.killed_at].
                prev_alli, prev_corp = _attackers_in_window(
                    current.killmail_ids, kms_by_id, attackers_by_killmail,
                    lo_ts=last_active_ts.timestamp() - 900,
                    hi_ts=last_active_ts.timestamp(),
                )
                now_alli, now_corp = _attackers_in_window(
                    current.killmail_ids | {kid}, kms_by_id, attackers_by_killmail,
                    lo_ts=km.killed_at.timestamp() - 900,
                    hi_ts=km.killed_at.timestamp(),
                )
                jac = _jaccard_weighted(prev_alli, now_alli, prev_corp, now_corp)
                if jac < continuity_floor:
                    should_split = True
        if should_split:
            if current.killmail_ids:
                clusters.append(current)
            current = Cluster()
            last_active_ts = None
        current.killmail_ids.add(kid)
        if km.victim_character_id:
            current.participant_character_ids.add(km.victim_character_id)
        for a in attackers_by_killmail.get(kid, ()):
            if a.character_id:
                current.participant_character_ids.add(a.character_id)
        last_ts = km.killed_at
        last_active_ts = km.killed_at
    if current.killmail_ids:
        clusters.append(current)
    return clusters


def _attackers_in_window(
    killmail_ids: set[int] | frozenset[int],
    kms_by_id: dict[int, Killmail],
    attackers_by_killmail: dict[int, list[Attacker]],
    lo_ts: float,
    hi_ts: float,
) -> tuple[set[int], set[int]]:
    alli: set[int] = set()
    corp: set[int] = set()
    for kid in killmail_ids:
        km = kms_by_id.get(kid)
        if km is None:
            continue
        ts = km.killed_at.timestamp()
        if ts < lo_ts or ts > hi_ts:
            continue
        for a in attackers_by_killmail.get(kid, ()):
            if a.alliance_id:
                alli.add(a.alliance_id)
            if a.corporation_id:
                corp.add(a.corporation_id)
    return alli, corp


def _jaccard_weighted(
    prev_alli: set[int], now_alli: set[int],
    prev_corp: set[int], now_corp: set[int],
) -> float:
    inter_a = len(prev_alli & now_alli)
    union_a = len(prev_alli | now_alli)
    inter_c = len(prev_corp & now_corp)
    union_c = len(prev_corp | now_corp)
    num = 2 * inter_a + inter_c
    den = 2 * max(union_a, 1) + max(union_c, 1)
    return num / den if den > 0 else 0.0
