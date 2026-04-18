"""Spec 5 v0 scoring: extensible per-score-class compute functions.

Each role's per-class score is produced by a pure function of
(FeatureRow, coefficient dict, weight-set-name prefix). `final` is the
clamped sum of every populated class under the active weight_version.
Missing coefficient rows for a class contribute 0.0 — no error — so
new score classes can be added by registering a compute function and
seeding coefficient rows without any caller change.

The active class list is declared as ACTIVE_CLASSES. Adding
`'historical'` here + implementing `compute_historical_score()` is the
only code change needed to activate a future historical component.
The CHECK on battle_character_role_scores.score_class already admits
'historical' after the Spec 5 schema prep migration.

Score decomposition rows are always written. Inference (§7) is:
  - FC, mainline_dps: top candidate per sub-fleet, threshold + gap
  - logi: all characters above threshold (set-membership), no gap
  - single winner per character (option C): one inference row per
    character whose highest-scoring clearing role is recorded as
    primary_role_key.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass
from typing import Callable

from battle_role_scoring.inputs import FeatureRow


# Categories used as hull-prior sub-keys. 'none' is the special case
# when a character has no ship_class_category at all (no ship_type_id
# ever observed). We treat that as 'other' for prior lookup per Spec 5
# §Minor 6 clarification — uncategorized = other.
HULL_CATEGORIES = ("logi", "bomber", "command", "tackle", "mainline", "other")

ROLE_FC = "fc"
ROLE_LOGI = "logi"
ROLE_MAINLINE = "mainline_dps"
ROLES = (ROLE_FC, ROLE_LOGI, ROLE_MAINLINE)

# Default set of score classes that sum into `final`. Extending this
# list is how future specs add new components (e.g. 'historical').
ACTIVE_CLASSES = ("structural", "temporal", "hull")

# Active weight-set name per role. FC can take a conditional override
# via pick_fc_weight_set().
WEIGHT_SET_STANDARD: dict[str, str] = {
    ROLE_FC: "fc_weights_standard",
    ROLE_LOGI: "logi_weights_standard",
    ROLE_MAINLINE: "mainline_dps_weights_standard",
}
WEIGHT_SET_FC_COMMAND_EDGE = "fc_weights_command_edge"


@dataclass
class ScoreDecomposition:
    structural: float
    temporal: float
    hull: float
    final: float  # clamped [0, 1]


def clamp01(x: float) -> float:
    if x < 0:
        return 0.0
    if x > 1:
        return 1.0
    return x


def coef(coefs: dict[str, float], key: str) -> float:
    """Coefficient lookup with 0-default. Missing => no contribution."""
    return coefs.get(key, 0.0)


def pick_fc_weight_set(f: FeatureRow) -> str:
    """Spec 5 §5, condition relaxed per review:
      ship_class_category = 'command'
      AND subfleet_dominant_hull_class <> 'command'
    """
    if f.ship_class_category == "command" and f.subfleet_dominant_hull_class != "command":
        return WEIGHT_SET_FC_COMMAND_EDGE
    return WEIGHT_SET_STANDARD[ROLE_FC]


def _hull_cat_key(f: FeatureRow) -> str:
    """Category to use for hull_prior lookup. Uncategorized -> 'other'."""
    if f.ship_class_category in HULL_CATEGORIES:
        return f.ship_class_category  # type: ignore[return-value]
    return "other"


# ---------------------------------------------------------------------
# Per-class compute functions. Each takes (FeatureRow, coefs, prefix)
# and returns a signed float. Sign is significant; hull components can
# be negative. `final` clamping happens once at the caller.
# ---------------------------------------------------------------------

def compute_structural_score(f: FeatureRow, coefs: dict[str, float], prefix: str) -> float:
    s = 0.0
    if f.degree_centrality is not None:
        s += coef(coefs, f"{prefix}.degree_centrality") * f.degree_centrality
    if f.pagerank is not None:
        s += coef(coefs, f"{prefix}.pagerank") * f.pagerank
    return s


def compute_temporal_score(f: FeatureRow, coefs: dict[str, float], prefix: str) -> float:
    s = 0.0
    s += coef(coefs, f"{prefix}.presence_span") * f.presence_span
    s += coef(coefs, f"{prefix}.early_presence") * f.early_presence
    s += coef(coefs, f"{prefix}.late_presence") * f.late_presence
    s += coef(coefs, f"{prefix}.death_order_pct") * f.death_order_pct
    s += coef(coefs, f"{prefix}.damage_share") * f.damage_share
    s += coef(coefs, f"{prefix}.damage_share_inverse") * (1.0 - f.damage_share)
    s += coef(coefs, f"{prefix}.kill_participation_rate") * f.kill_participation_rate
    return s


def compute_hull_score(f: FeatureRow, coefs: dict[str, float], prefix: str) -> float:
    """Hull prior (always the one matching the char's category) plus
    any context_bonus.* contributions that the weight set declares."""
    s = 0.0
    cat = _hull_cat_key(f)
    s += coef(coefs, f"{prefix}.hull_prior.{cat}")

    # Context bonuses. Each coefficient is applied only if the named
    # context condition holds on the feature row.
    if not f.is_in_subfleet_0 and f.subfleet_has_logi:
        s += coef(coefs, f"{prefix}.context_bonus.non_sf0_with_logi")
    if f.subfleet_hull_class_concentration is not None and f.subfleet_hull_class_concentration < 0.7:
        s += coef(coefs, f"{prefix}.context_bonus.mixed_composition")
    if f.is_in_subfleet_0:
        s += coef(coefs, f"{prefix}.context_bonus.is_in_subfleet_0")
    return s


# Registry of compute functions. Future classes (e.g. 'historical')
# register here. Classes absent from the registry contribute 0.
COMPUTE_REGISTRY: dict[str, Callable[[FeatureRow, dict[str, float], str], float]] = {
    "structural": compute_structural_score,
    "temporal": compute_temporal_score,
    "hull": compute_hull_score,
}


def compute_role_decomposition(
    f: FeatureRow,
    coefs: dict[str, float],
    weight_set_prefix: str,
    active_classes: tuple[str, ...] = ACTIVE_CLASSES,
) -> ScoreDecomposition:
    """Compute all active score classes + final for one (character, role)."""
    per_class: dict[str, float] = {}
    for cls in active_classes:
        fn = COMPUTE_REGISTRY.get(cls)
        per_class[cls] = fn(f, coefs, weight_set_prefix) if fn is not None else 0.0
    raw_final = sum(per_class.values())
    return ScoreDecomposition(
        structural=per_class.get("structural", 0.0),
        temporal=per_class.get("temporal", 0.0),
        hull=per_class.get("hull", 0.0),
        final=clamp01(raw_final),
    )


# ---------------------------------------------------------------------
# Thresholds + gaps are stored as coefficient rows under
# coefficient_key = 'thresholds_and_gaps_v0.<name>'.
# ---------------------------------------------------------------------

@dataclass(frozen=True)
class Thresholds:
    fc_threshold: float
    fc_gap: float
    logi_threshold: float
    mainline_threshold: float
    mainline_gap: float


def load_thresholds(coefs: dict[str, float]) -> Thresholds:
    return Thresholds(
        fc_threshold=coef(coefs, "thresholds_and_gaps_v0.fc_threshold"),
        fc_gap=coef(coefs, "thresholds_and_gaps_v0.fc_gap"),
        logi_threshold=coef(coefs, "thresholds_and_gaps_v0.logi_threshold"),
        mainline_threshold=coef(coefs, "thresholds_and_gaps_v0.mainline_threshold"),
        mainline_gap=coef(coefs, "thresholds_and_gaps_v0.mainline_gap"),
    )


# ---------------------------------------------------------------------
# Outputs
# ---------------------------------------------------------------------

@dataclass
class ScoreRow:
    character_id: int
    sub_fleet_id: int
    role_key: str
    score_class: str  # 'structural' | 'temporal' | 'hull' | 'final' | future
    score_value: float


@dataclass
class InferenceRow:
    character_id: int
    sub_fleet_id: int
    primary_role_key: str
    primary_score: float
    second_best_score: float
    confidence: float
    confidence_band: str  # 'high' | 'medium' | 'low'


@dataclass
class ScoringResult:
    scores: list[ScoreRow]
    inferences: list[InferenceRow]
    per_sub_fleet_diagnostics: list[dict]


def _band(confidence: float) -> str:
    if confidence >= 0.80:
        return "high"
    if confidence >= 0.62:
        return "medium"
    return "low"


def _confidence(top: float, second: float, feature_completeness: float) -> float:
    return clamp01(
        0.50 * top + 0.25 * (top - second) + 0.25 * feature_completeness
    )


def _logi_confidence(score: float, threshold: float, feature_completeness: float) -> float:
    return clamp01(
        0.50 * score + 0.25 * (score - threshold) + 0.25 * feature_completeness
    )


def score_battle(
    features: list[FeatureRow],
    coefs: dict[str, float],
    active_classes: tuple[str, ...] = ACTIVE_CLASSES,
) -> ScoringResult:
    thresholds = load_thresholds(coefs)
    scores: list[ScoreRow] = []

    # Per-character, per-role: compute decomposed scores.
    # final_by_char[char_id][role] = ScoreDecomposition
    final_by_char: dict[int, dict[str, float]] = {}
    completeness_by_char: dict[int, float] = {}
    sub_fleet_by_char: dict[int, int] = {}
    features_by_char: dict[int, FeatureRow] = {}

    for f in features:
        features_by_char[f.character_id] = f
        sub_fleet_by_char[f.character_id] = f.sub_fleet_id
        completeness_by_char[f.character_id] = f.feature_completeness
        finals: dict[str, float] = {}

        for role in ROLES:
            if role == ROLE_FC:
                prefix = pick_fc_weight_set(f)
            else:
                prefix = WEIGHT_SET_STANDARD[role]

            decomp = compute_role_decomposition(f, coefs, prefix, active_classes)
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "structural", decomp.structural))
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "temporal", decomp.temporal))
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "hull", decomp.hull))
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "final", decomp.final))
            finals[role] = decomp.final

        final_by_char[f.character_id] = finals

    # Group characters by sub-fleet for assignment.
    by_sub: dict[int, list[int]] = defaultdict(list)
    for cid, sf in sub_fleet_by_char.items():
        by_sub[sf].append(cid)

    # Compute per-role per-sub-fleet assignment candidates.
    # FC: top clears threshold AND top-second >= gap
    # mainline_dps: same
    # logi: all >= threshold
    # Single-winner option (C): each char gets at most one inference row
    # using their highest-scoring clearing role.

    # winners[char_id] = (role, primary_score, second_best_score)
    winners: dict[int, tuple[str, float, float]] = {}
    per_sub_diag: list[dict] = []

    for sf, cids in sorted(by_sub.items()):
        # sort candidates per role by final score desc
        fc_ranked = sorted(cids, key=lambda c: final_by_char[c][ROLE_FC], reverse=True)
        ml_ranked = sorted(cids, key=lambda c: final_by_char[c][ROLE_MAINLINE], reverse=True)

        fc_top = final_by_char[fc_ranked[0]][ROLE_FC] if fc_ranked else 0.0
        fc_second = final_by_char[fc_ranked[1]][ROLE_FC] if len(fc_ranked) > 1 else 0.0
        fc_assigned_cid: int | None = None
        if fc_top >= thresholds.fc_threshold and (fc_top - fc_second) >= thresholds.fc_gap:
            fc_assigned_cid = fc_ranked[0]

        ml_top = final_by_char[ml_ranked[0]][ROLE_MAINLINE] if ml_ranked else 0.0
        ml_second = final_by_char[ml_ranked[1]][ROLE_MAINLINE] if len(ml_ranked) > 1 else 0.0
        ml_assigned_cid: int | None = None
        if ml_top >= thresholds.mainline_threshold and (ml_top - ml_second) >= thresholds.mainline_gap:
            ml_assigned_cid = ml_ranked[0]

        logi_winners = [
            c for c in cids
            if final_by_char[c][ROLE_LOGI] >= thresholds.logi_threshold
        ]

        # Single-winner pick: for each character, choose the role with
        # highest score among the roles they qualified for.
        for c in cids:
            qualifications: list[tuple[str, float, float]] = []
            if c == fc_assigned_cid:
                qualifications.append((ROLE_FC, fc_top, fc_second))
            if c in logi_winners:
                qualifications.append((ROLE_LOGI, final_by_char[c][ROLE_LOGI], thresholds.logi_threshold))
            if c == ml_assigned_cid:
                qualifications.append((ROLE_MAINLINE, ml_top, ml_second))
            if not qualifications:
                continue
            qualifications.sort(key=lambda t: t[1], reverse=True)
            role, primary, second = qualifications[0]
            winners[c] = (role, primary, second)

        per_sub_diag.append({
            "sub_fleet_id": sf,
            "member_count": len(cids),
            "fc_top_score": round(fc_top, 4),
            "fc_gap_to_second": round(fc_top - fc_second, 4),
            "fc_assigned": fc_assigned_cid is not None,
            "logi_count_above_threshold": len(logi_winners),
            "mainline_top_score": round(ml_top, 4),
            "mainline_gap_to_second": round(ml_top - ml_second, 4),
            "mainline_assigned": ml_assigned_cid is not None,
        })

    # Materialize inference rows.
    inferences: list[InferenceRow] = []
    for cid, (role, primary, second) in winners.items():
        fc = completeness_by_char[cid]
        if role == ROLE_LOGI:
            conf = _logi_confidence(primary, thresholds.logi_threshold, fc)
        else:
            conf = _confidence(primary, second, fc)
        inferences.append(InferenceRow(
            character_id=cid,
            sub_fleet_id=sub_fleet_by_char[cid],
            primary_role_key=role,
            primary_score=round(primary, 4),
            second_best_score=round(second, 4),
            confidence=round(conf, 4),
            confidence_band=_band(conf),
        ))

    return ScoringResult(
        scores=scores,
        inferences=inferences,
        per_sub_fleet_diagnostics=per_sub_diag,
    )
