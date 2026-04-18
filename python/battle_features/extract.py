"""Spec 4 v1 feature extraction.

Computes 15 features per (battle, alliance, sub_fleet, character).
Every formula is documented in docs/spec4_feature_manifest.md — this
module is the authoritative implementation of those definitions; if
the two ever disagree, the manifest is wrong, not this code.

Scope notes, driven by the Spec 4 review (2026-04-18):
  - damage_share is SUB-FLEET relative. Per-sub-fleet sums to 1.0 or 0.0.
  - death_order_pct is SUB-FLEET relative. Never-died or sub-fleet
    with zero deaths → 1.0.
  - early_presence / late_presence are BINARY (0.0 or 1.0), based on
    the char's FIRST (resp. LAST) appearance timestamp falling inside
    the first (resp. last) 20% of the battle span.
  - degree_centrality / pagerank are NULL on small-tier battles (no
    graph computed). Spec 1's NOT NULL DEFAULT 0.0000 was wrong for
    Spec 4 and has been relaxed in the fix-pack migration.
  - ship_class_category is first-class 'other' for hulls outside the
    seed mapping. NULL is reserved for "ship_type_id itself missing".
"""

from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime, timedelta

from battle_features.inputs import (
    AttackerEvent,
    GraphMetric,
    MembershipRow,
    SubFleetHeader,
    VictimEvent,
)


EARLY_WINDOW_FRAC = 0.20
LATE_WINDOW_FRAC = 0.20

# Tie-break order for dominant hull class when two categories tie on
# count inside a sub-fleet.
CATEGORY_TIEBREAK = ("bomber", "command", "logi", "mainline", "tackle", "other")

POPULATED_FEATURES_NORMAL = 15
POPULATED_FEATURES_SMALL_TIER = 13  # drops degree_centrality + pagerank


@dataclass
class FeatureRow:
    character_id: int
    sub_fleet_id: int
    ship_type_id: int | None
    ship_class_category: str | None  # 'other' | 5 real cats | None (no ship_type_id)
    is_in_subfleet_0: bool
    damage_share: float
    kill_participation_rate: float
    presence_span: float
    early_presence: float  # binary: 0.0 or 1.0
    late_presence: float   # binary: 0.0 or 1.0
    death_order_pct: float
    degree_centrality: float | None  # None on small-tier
    pagerank: float | None           # None on small-tier
    subfleet_member_count: int
    subfleet_damage_share_of_side: float
    subfleet_dominant_hull_class: str | None
    subfleet_hull_class_concentration: float | None
    subfleet_has_logi: bool
    feature_completeness: float


@dataclass
class ExtractResult:
    rows: list[FeatureRow]
    small_tier: bool
    unresolvable_ship_type_ids: list[int]
    zero_damage_sub_fleets: list[int]


def _primary_ship(
    char_attacker_events: list[AttackerEvent],
) -> int | None:
    """Mode ship_type_id across char's attacker events. Tie-break by
    lowest ship_type_id. None if the char never appeared as attacker
    with a non-null ship_type_id."""
    counts: Counter[int] = Counter()
    for e in char_attacker_events:
        if e.ship_type_id is not None:
            counts[e.ship_type_id] += 1
    if not counts:
        return None
    max_c = max(counts.values())
    return min(sid for sid, c in counts.items() if c == max_c)


def _dominant_hull_class(
    categories: list[str],
) -> str | None:
    """Plurality category, tie-break by CATEGORY_TIEBREAK order."""
    if not categories:
        return None
    counts: Counter[str] = Counter(categories)
    max_c = max(counts.values())
    winners = [c for c, n in counts.items() if n == max_c]
    if len(winners) == 1:
        return winners[0]
    order = {cat: i for i, cat in enumerate(CATEGORY_TIEBREAK)}
    return min(winners, key=lambda c: order.get(c, 99))


def _classify_ship(
    ship_type_id: int | None,
    hull_map: dict[int, str],
    unresolvable_sink: set[int],
) -> str | None:
    """Look up category for a ship. 'other' when the ship_type_id is
    present but not in the seed mapping (and we record it so callers
    can WARN + feed it into a future seed expansion). None only when
    no ship_type_id was ever observed for the character."""
    if ship_type_id is None:
        return None
    cat = hull_map.get(ship_type_id)
    if cat is None:
        unresolvable_sink.add(ship_type_id)
        return "other"
    return cat


def extract(
    *,
    memberships: list[MembershipRow],
    sub_fleet_headers: list[SubFleetHeader],
    graph_metrics: list[GraphMetric],
    attacker_events: list[AttackerEvent],
    victim_events: list[VictimEvent],
    hull_category_map: dict[int, str],
) -> ExtractResult:
    # -- base indices ------------------------------------------------
    member_ids = {m.character_id for m in memberships}
    sub_fleet_by_char = {m.character_id: m.sub_fleet_id for m in memberships}
    graph_by_char = {g.character_id: g for g in graph_metrics}

    side_attacker = [e for e in attacker_events if e.character_id in member_ids]
    side_victim = [e for e in victim_events if e.character_id in member_ids]

    # -- battle span (union of all theater events) ------------------
    all_times: list[datetime] = [e.killed_at for e in attacker_events]
    all_times.extend(e.killed_at for e in victim_events)
    if all_times:
        battle_start = min(all_times)
        battle_end = max(all_times)
    else:
        battle_start = battle_end = datetime.utcnow()
    battle_duration = (battle_end - battle_start).total_seconds()
    early_cutoff = battle_start + timedelta(seconds=battle_duration * EARLY_WINDOW_FRAC)
    late_cutoff = battle_start + timedelta(seconds=battle_duration * (1 - LATE_WINDOW_FRAC))

    # -- side-level aggregates (kill_participation denominator) -----
    side_kill_ids = {e.killmail_id for e in side_attacker}
    total_side_kills = len(side_kill_ids)
    total_side_damage = sum(e.damage_done for e in side_attacker)

    # -- char-indexed event maps ------------------------------------
    by_char_attacker: dict[int, list[AttackerEvent]] = defaultdict(list)
    for e in side_attacker:
        by_char_attacker[e.character_id].append(e)
    by_char_victim: dict[int, list[VictimEvent]] = defaultdict(list)
    for e in side_victim:
        by_char_victim[e.character_id].append(e)

    # -- small-tier detection --------------------------------------
    small_tier = bool(graph_metrics) and all(
        g.skip_reason is not None for g in graph_metrics
    )

    # -- per-sub-fleet max for graph normalization -----------------
    max_degree_by_sub: dict[int, float] = defaultdict(float)
    max_pagerank_by_sub: dict[int, float] = defaultdict(float)
    if not small_tier:
        for m in memberships:
            g = graph_by_char.get(m.character_id)
            if g is None:
                continue
            if g.weighted_degree_raw is not None and g.weighted_degree_raw > max_degree_by_sub[m.sub_fleet_id]:
                max_degree_by_sub[m.sub_fleet_id] = g.weighted_degree_raw
            if g.pagerank_raw is not None and g.pagerank_raw > max_pagerank_by_sub[m.sub_fleet_id]:
                max_pagerank_by_sub[m.sub_fleet_id] = g.pagerank_raw

    # -- per-char damage + kill set --------------------------------
    per_char_damage: dict[int, int] = defaultdict(int)
    for e in side_attacker:
        per_char_damage[e.character_id] += e.damage_done

    per_char_kill_ids: dict[int, set[int]] = defaultdict(set)
    for e in side_attacker:
        per_char_kill_ids[e.character_id].add(e.killmail_id)

    per_char_primary_ship: dict[int, int | None] = {
        cid: _primary_ship(evts) for cid, evts in by_char_attacker.items()
    }

    # -- per-sub-fleet members list + aggregates --------------------
    members_by_sub: dict[int, list[int]] = defaultdict(list)
    for m in memberships:
        members_by_sub[m.sub_fleet_id].append(m.character_id)
    header_by_sub = {sf.sub_fleet_id: sf for sf in sub_fleet_headers}

    sub_damage: dict[int, int] = {}
    for sub_id, cids in members_by_sub.items():
        sub_damage[sub_id] = sum(per_char_damage.get(c, 0) for c in cids)

    zero_damage_sub_fleets = [sid for sid, d in sub_damage.items() if d == 0]

    # -- per-sub-fleet death ordering (Spec 4 review change) -------
    # Rank is 0-indexed position of a member's FIRST death among all
    # side-victim events where the victim is in that sub-fleet. Ties
    # broken by (killed_at, killmail_id, character_id).
    deaths_by_sub: dict[int, list[VictimEvent]] = defaultdict(list)
    for v in side_victim:
        sub = sub_fleet_by_char.get(v.character_id)
        if sub is None:
            continue
        deaths_by_sub[sub].append(v)
    for sub_id, lst in deaths_by_sub.items():
        lst.sort(key=lambda v: (v.killed_at, v.killmail_id, v.character_id))
    death_rank_by_char: dict[int, int] = {}
    sub_death_count: dict[int, int] = {}
    for sub_id, lst in deaths_by_sub.items():
        seen: set[int] = set()
        for idx, v in enumerate(lst):
            if v.character_id not in seen:
                death_rank_by_char[v.character_id] = idx
                seen.add(v.character_id)
        sub_death_count[sub_id] = len(seen)

    # -- hull categorization (single pass, collects unresolvables) --
    unresolvable: set[int] = set()
    per_char_category: dict[int, str | None] = {}
    for cid in member_ids:
        ship = per_char_primary_ship.get(cid)
        per_char_category[cid] = _classify_ship(ship, hull_category_map, unresolvable)

    # -- per-sub-fleet hull aggregates -----------------------------
    sub_dom_class: dict[int, str | None] = {}
    sub_concentration: dict[int, float | None] = {}
    sub_has_logi: dict[int, bool] = {}
    for sub_id, cids in members_by_sub.items():
        cats: list[str] = []
        for c in cids:
            cat = per_char_category.get(c)
            if cat is not None:
                cats.append(cat)
        dom = _dominant_hull_class(cats)
        sub_dom_class[sub_id] = dom
        mc = header_by_sub[sub_id].member_count if sub_id in header_by_sub else len(cids)
        if dom is not None and mc > 0:
            sub_concentration[sub_id] = Counter(cats).get(dom, 0) / mc
        else:
            sub_concentration[sub_id] = None
        sub_has_logi[sub_id] = "logi" in cats

    # -- one row per member ----------------------------------------
    out: list[FeatureRow] = []
    for m in memberships:
        cid = m.character_id
        sub_id = m.sub_fleet_id
        g = graph_by_char.get(cid)

        # damage_share (sub-fleet relative; Spec 4 review change)
        denom = sub_damage.get(sub_id, 0)
        if denom > 0:
            damage_share = per_char_damage.get(cid, 0) / denom
        else:
            damage_share = 0.0

        # kill_participation_rate (side-scoped, unchanged)
        if total_side_kills > 0:
            kpr = len(per_char_kill_ids.get(cid, set())) / total_side_kills
        else:
            kpr = 0.0

        # presence_span (fractional, battle-scoped)
        char_times: list[datetime] = [e.killed_at for e in by_char_attacker.get(cid, [])]
        char_times.extend(v.killed_at for v in by_char_victim.get(cid, []))
        if char_times and battle_duration > 0:
            c_first = min(char_times)
            c_last = max(char_times)
            presence_span = (c_last - c_first).total_seconds() / battle_duration
        else:
            presence_span = 0.0

        # early / late presence (binary; Spec 4 review change)
        if char_times:
            first_ts = min(char_times)
            last_ts = max(char_times)
            early = 1.0 if first_ts <= early_cutoff else 0.0
            late = 1.0 if last_ts >= late_cutoff else 0.0
        else:
            early = 0.0
            late = 0.0

        # death_order_pct (sub-fleet scoped; Spec 4 review change)
        n_deaths = sub_death_count.get(sub_id, 0)
        if n_deaths == 0:
            death_order_pct = 1.0
        elif cid in death_rank_by_char:
            if n_deaths == 1:
                # sole casualty — no rank distribution to place them in;
                # died first by definition → 0.0
                death_order_pct = 0.0
            else:
                death_order_pct = death_rank_by_char[cid] / (n_deaths - 1)
        else:
            death_order_pct = 1.0  # survived — placed "after" all deaths

        # degree_centrality / pagerank (None on small-tier or missing)
        if small_tier or g is None or g.weighted_degree_raw is None:
            degree_norm: float | None = None if small_tier else 0.0
        else:
            m_max = max_degree_by_sub.get(sub_id, 0.0)
            degree_norm = g.weighted_degree_raw / m_max if m_max > 0 else 0.0

        if small_tier or g is None or g.pagerank_raw is None:
            pagerank_norm: float | None = None if small_tier else 0.0
        else:
            p_max = max_pagerank_by_sub.get(sub_id, 0.0)
            pagerank_norm = g.pagerank_raw / p_max if p_max > 0 else 0.0

        # per-sub-fleet denormalized
        sf_member_count = header_by_sub[sub_id].member_count if sub_id in header_by_sub else len(members_by_sub[sub_id])
        if total_side_damage > 0:
            sf_damage_share = sub_damage.get(sub_id, 0) / total_side_damage
        else:
            sf_damage_share = 0.0

        completeness = (
            POPULATED_FEATURES_SMALL_TIER / 15.0 if small_tier
            else POPULATED_FEATURES_NORMAL / 15.0
        )

        out.append(FeatureRow(
            character_id=cid,
            sub_fleet_id=sub_id,
            ship_type_id=per_char_primary_ship.get(cid),
            ship_class_category=per_char_category.get(cid),
            is_in_subfleet_0=(sub_id == 0),
            damage_share=_clamp01(damage_share),
            kill_participation_rate=_clamp01(kpr),
            presence_span=_clamp01(presence_span),
            early_presence=early,
            late_presence=late,
            death_order_pct=_clamp01(death_order_pct),
            degree_centrality=_clamp01(degree_norm) if degree_norm is not None else None,
            pagerank=_clamp01(pagerank_norm) if pagerank_norm is not None else None,
            subfleet_member_count=sf_member_count,
            subfleet_damage_share_of_side=_clamp01(sf_damage_share),
            subfleet_dominant_hull_class=sub_dom_class.get(sub_id),
            subfleet_hull_class_concentration=(
                _clamp01(sub_concentration[sub_id]) if sub_concentration.get(sub_id) is not None else None
            ),
            subfleet_has_logi=sub_has_logi.get(sub_id, False),
            feature_completeness=round(completeness, 4),
        ))

    return ExtractResult(
        rows=out,
        small_tier=small_tier,
        unresolvable_ship_type_ids=sorted(unresolvable),
        zero_damage_sub_fleets=sorted(zero_damage_sub_fleets),
    )


def _clamp01(x: float) -> float:
    if x < 0:
        return 0.0
    if x > 1:
        return 1.0
    return x
