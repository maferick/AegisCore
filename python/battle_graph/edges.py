"""Edge construction from bucketed co-presence, victim overlap, and
phase co-occurrence — all computed in Python. Neo4j only runs graph
algorithms; it never sees raw event data.

Per Spec 2 § 3 the three signals are each normalised to [0,1] by the
"shared-over-max" convention, then combined with the edge-profile
coefficients. Edges under min_edge_weight are dropped.
"""

from __future__ import annotations

from itertools import combinations

from battle_graph.inputs import Battle, PilotEvents
from battle_graph.profiles import EdgeProfile


def _bucket(ts: int, anchor: int, seconds: int) -> int:
    # Floor to the bucket, with the battle's start_time as anchor so
    # re-runs with the same inputs produce identical bucket indices.
    return (ts - anchor) // seconds


def _phases_from_alliance_timeline(
    pilots: dict[int, PilotEvents],
    anchor: int,
    bucket_seconds: int,
    phase_seconds: int,
) -> dict[int, int]:
    """Map bucket_index → phase_index for the alliance side. A phase
    is a contiguous run of buckets where *any* pilot on the side has
    activity, with a gap of at least phase_seconds between phases."""
    buckets: set[int] = set()
    for ev in pilots.values():
        for ts in ev.event_times:
            buckets.add(_bucket(ts, anchor, bucket_seconds))
    if not buckets:
        return {}
    sorted_buckets = sorted(buckets)
    gap_buckets = max(1, phase_seconds // bucket_seconds)
    phase_id = 0
    bucket_to_phase: dict[int, int] = {}
    prev = sorted_buckets[0]
    for b in sorted_buckets:
        if b - prev > gap_buckets:
            phase_id += 1
        bucket_to_phase[b] = phase_id
        prev = b
    return bucket_to_phase


def build_edges(
    battle: Battle,
    pilots: dict[int, PilotEvents],
    edge: EdgeProfile,
) -> list[dict]:
    """Return a list of edge dicts ready for Neo4j write.

    Each dict: {a, b, weight, same_bucket, victim_overlap, phase_cooccur}
    where a < b (undirected canonical order).
    """
    if len(pilots) < 2:
        return []

    anchor = int(battle.start_time.timestamp())

    # Pre-compute bucket / phase / victim sets per pilot.
    bucket_to_phase = _phases_from_alliance_timeline(
        pilots, anchor, edge.bucket_seconds, edge.phase_seconds,
    )
    buckets_of: dict[int, set[int]] = {}
    phases_of: dict[int, set[int]] = {}
    for cid, ev in pilots.items():
        pbuckets = set()
        pphases = set()
        for ts in ev.event_times:
            b = _bucket(ts, anchor, edge.bucket_seconds)
            pbuckets.add(b)
            if b in bucket_to_phase:
                pphases.add(bucket_to_phase[b])
        buckets_of[cid] = pbuckets
        phases_of[cid] = pphases

    # Accumulate shared counts via an inverted-index sweep so we only
    # touch pairs that actually share at least one thing.
    same_bucket_shared: dict[tuple[int, int], int] = {}
    victim_shared: dict[tuple[int, int], int] = {}
    phase_shared: dict[tuple[int, int], int] = {}

    # Inverted: bucket → {pilots}
    by_bucket: dict[int, list[int]] = {}
    for cid, bs in buckets_of.items():
        for b in bs:
            by_bucket.setdefault(b, []).append(cid)
    for _, cids in by_bucket.items():
        cids.sort()
        for a, b in combinations(cids, 2):
            same_bucket_shared[(a, b)] = same_bucket_shared.get((a, b), 0) + 1

    # Inverted: victim → {attacker pilots}
    by_victim: dict[int, list[int]] = {}
    for cid, ev in pilots.items():
        for v in ev.victims:
            by_victim.setdefault(v, []).append(cid)
    for _, cids in by_victim.items():
        cids.sort()
        for a, b in combinations(cids, 2):
            victim_shared[(a, b)] = victim_shared.get((a, b), 0) + 1

    # Inverted: phase → {pilots}
    by_phase: dict[int, list[int]] = {}
    for cid, ps in phases_of.items():
        for p in ps:
            by_phase.setdefault(p, []).append(cid)
    for _, cids in by_phase.items():
        cids.sort()
        for a, b in combinations(cids, 2):
            phase_shared[(a, b)] = phase_shared.get((a, b), 0) + 1

    # Per-pilot denominators for max-normalisation.
    buckets_size: dict[int, int] = {cid: max(1, len(bs)) for cid, bs in buckets_of.items()}
    victims_size: dict[int, int] = {
        cid: max(1, len(ev.victims)) for cid, ev in pilots.items()
    }
    phases_size: dict[int, int] = {cid: max(1, len(ps)) for cid, ps in phases_of.items()}

    candidate_pairs = set(same_bucket_shared) | set(victim_shared) | set(phase_shared)
    edges: list[dict] = []
    for (a, b) in candidate_pairs:
        sb = same_bucket_shared.get((a, b), 0) / max(buckets_size[a], buckets_size[b])
        vo = victim_shared.get((a, b), 0) / max(victims_size[a], victims_size[b])
        pc = phase_shared.get((a, b), 0) / max(phases_size[a], phases_size[b])
        w = (
            edge.same_bucket_coef * sb
            + edge.victim_overlap_coef * vo
            + edge.phase_cooccur_coef * pc
        )
        if w < edge.min_edge_weight:
            continue
        edges.append({
            "a": a,
            "b": b,
            "weight": w,
            "same_bucket": sb,
            "victim_overlap": vo,
            "phase_cooccur": pc,
        })
    # Deterministic order so re-runs write identical Neo4j state.
    edges.sort(key=lambda e: (e["a"], e["b"]))
    return edges
