"""MariaDB input loaders for Spec 5.

Reads:
  - battle_character_role_features (Spec 4 output) — the feature vector
  - battle_role_scoring_weights (coefficient table) — weight sets +
    threshold/gap configuration for a given weight_version
  - battle_role_weight_versions — resolve label -> id

Features are loaded once per run and held in memory. Coefficients are
loaded once per run and indexed by coefficient_key for O(1) lookup.
"""

from __future__ import annotations

from dataclasses import dataclass

import pymysql


@dataclass(frozen=True)
class FeatureRow:
    character_id: int
    sub_fleet_id: int
    ship_type_id: int | None
    ship_class_category: str | None
    is_in_subfleet_0: bool
    damage_share: float
    kill_participation_rate: float
    presence_span: float
    early_presence: float
    late_presence: float
    death_order_pct: float
    degree_centrality: float | None
    pagerank: float | None
    subfleet_dominant_hull_class: str | None
    subfleet_hull_class_concentration: float | None
    subfleet_has_logi: bool
    feature_completeness: float


def load_features(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> list[FeatureRow]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT character_id, sub_fleet_id, ship_type_id, ship_class_category, is_in_subfleet_0,
                   damage_share, kill_participation_rate, presence_span,
                   early_presence, late_presence, death_order_pct,
                   degree_centrality, pagerank,
                   subfleet_dominant_hull_class, subfleet_hull_class_concentration,
                   subfleet_has_logi, feature_completeness
              FROM battle_character_role_features
             WHERE battle_id=%s AND alliance_id=%s AND partition_algo_version=%s
            """,
            (battle_id, alliance_id, partition_algo_version),
        )
        rows = cur.fetchall()

    def _fv(x) -> float | None:
        return float(x) if x is not None else None

    return [
        FeatureRow(
            character_id=int(r["character_id"]),
            sub_fleet_id=int(r["sub_fleet_id"]),
            ship_type_id=(int(r["ship_type_id"]) if r["ship_type_id"] is not None else None),
            ship_class_category=(str(r["ship_class_category"]) if r["ship_class_category"] is not None else None),
            is_in_subfleet_0=bool(r["is_in_subfleet_0"]),
            damage_share=float(r["damage_share"]),
            kill_participation_rate=float(r["kill_participation_rate"]),
            presence_span=float(r["presence_span"]),
            early_presence=float(r["early_presence"]),
            late_presence=float(r["late_presence"]),
            death_order_pct=float(r["death_order_pct"]),
            degree_centrality=_fv(r["degree_centrality"]),
            pagerank=_fv(r["pagerank"]),
            subfleet_dominant_hull_class=(str(r["subfleet_dominant_hull_class"]) if r["subfleet_dominant_hull_class"] is not None else None),
            subfleet_hull_class_concentration=_fv(r["subfleet_hull_class_concentration"]),
            subfleet_has_logi=bool(r["subfleet_has_logi"]),
            feature_completeness=float(r["feature_completeness"]),
        )
        for r in rows
    ]


def resolve_weight_version_id(
    conn: pymysql.connections.Connection,
    weight_version: int | None,
    label: str | None,
) -> tuple[int, str]:
    """Return (id, label). If id passed, look up label; else look up by label."""
    with conn.cursor() as cur:
        if weight_version is not None:
            cur.execute(
                "SELECT weight_version, label FROM battle_role_weight_versions WHERE weight_version=%s",
                (weight_version,),
            )
        else:
            cur.execute(
                "SELECT weight_version, label FROM battle_role_weight_versions WHERE label=%s",
                (label,),
            )
        row = cur.fetchone()
    if row is None:
        raise RuntimeError(
            f"weight_version not found (id={weight_version}, label={label})"
        )
    return int(row["weight_version"]), str(row["label"])


def load_coefficients(
    conn: pymysql.connections.Connection,
    weight_version: int,
) -> dict[str, float]:
    """Return coefficient_key -> value for all active rows under the
    given weight_version. Missing keys mean "coefficient not seeded";
    callers default to 0.0."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT coefficient_key, coefficient_value
              FROM battle_role_scoring_weights
             WHERE weight_version=%s AND is_active=1
            """,
            (weight_version,),
        )
        rows = cur.fetchall()
    return {str(r["coefficient_key"]): float(r["coefficient_value"]) for r in rows}


def load_historical_priors(
    conn: pymysql.connections.Connection,
    character_ids: list[int],
) -> dict[tuple[int, str], float]:
    """Load character_role_historical_priors for a set of characters.

    Returns (character_id, role_key) → prior_value. Missing entries
    default to 0.0 at compute time (cold-start pilots contribute 0
    to the historical score class). No error on empty input."""
    if not character_ids:
        return {}
    placeholders = ",".join(["%s"] * len(character_ids))
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT character_id, role_key, prior_value
              FROM character_role_historical_priors
             WHERE character_id IN ({placeholders})
            """,
            character_ids,
        )
        rows = cur.fetchall()
    return {
        (int(r["character_id"]), str(r["role_key"])): float(r["prior_value"])
        for r in rows
    }


def features_exist(
    conn: pymysql.connections.Connection,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
) -> bool:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT 1 FROM battle_character_role_features
             WHERE battle_id=%s AND alliance_id=%s AND partition_algo_version=%s
             LIMIT 1
            """,
            (battle_id, alliance_id, partition_algo_version),
        )
        return cur.fetchone() is not None
