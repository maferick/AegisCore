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
ROLE_TACKLE = "tackle"
ROLE_BOMBER = "bomber"
ROLE_COMMAND = "command"
ROLES = (ROLE_FC, ROLE_LOGI, ROLE_MAINLINE, ROLE_TACKLE, ROLE_BOMBER, ROLE_COMMAND)

# Set-membership roles: every char that clears threshold gets the role.
# Single-winner roles: only the top char (after gap check) gets the role.
SET_MEMBERSHIP_ROLES = {ROLE_LOGI, ROLE_TACKLE, ROLE_BOMBER, ROLE_MAINLINE}
SINGLE_WINNER_ROLES = {ROLE_FC, ROLE_COMMAND}

# Hulls that the role-scorer unconditionally assigns FC to, bypassing
# threshold + gap. Rationale: a Monitor applies zero damage and is
# effectively invulnerable, so it has near-zero behavioral signal —
# the only reason to fly one is to command a fleet. Multiple Monitors
# in one sub-fleet all get FC (co-FC semantics).
#
# Extend this set when another structurally-silent command hull with
# equally strong prior intent ships (e.g. future monitor variants).
GUARANTEED_FC_SHIP_TYPE_IDS: set[int] = {
    45534,  # Monitor
}

# Hull-category → guaranteed role. These categories are seeded from the
# SDE ship group in ship_class_category_mapping and capture tactical
# function the scorer cannot infer from per-kill behavior alone:
#   logi   → reps don't produce killmail damage rows, so pure armor/
#            shield-logistics hulls score poorly on behavior.
#   bomber → stealth bombers torp from stealth; outside of sub-fleet
#            concentration priors their individual signature looks
#            indistinguishable from low-damage DPS.
#   command → command ships exist to boost/project; their signature is
#             fleet presence, not per-kill damage or reps.
# Hulls with genuinely ambiguous tactical function (tackle has inty +
# dictor + ewar + AF frig all mixed) are NOT overridden here — behavior
# still has to earn the tag.
HULL_CATEGORY_GUARANTEED_ROLE: dict[str, str] = {
    "logi": ROLE_LOGI,
    "bomber": ROLE_BOMBER,
    "command": ROLE_COMMAND,
}

# Default set of score classes that sum into `final`. Extending this
# list is how future specs add new components (e.g. 'historical').
ACTIVE_CLASSES = ("structural", "temporal", "hull", "historical")

# Active weight-set name per role. FC can take a conditional override
# via pick_fc_weight_set().
WEIGHT_SET_STANDARD: dict[str, str] = {
    ROLE_FC: "fc_weights_standard",
    ROLE_LOGI: "logi_weights_standard",
    ROLE_MAINLINE: "mainline_dps_weights_standard",
    ROLE_TACKLE: "tackle_weights_standard",
    ROLE_BOMBER: "bomber_weights_standard",
    ROLE_COMMAND: "command_weights_standard",
}
WEIGHT_SET_FC_COMMAND_EDGE = "fc_weights_command_edge"


@dataclass
class ScoreDecomposition:
    structural: float
    temporal: float
    hull: float
    historical: float
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


def compute_historical_score(
    f: FeatureRow,
    coefs: dict[str, float],
    prefix: str,
    prior: float = 0.0,
) -> float:
    """Spec 7 historical component. One coefficient per weight set:
    `<prefix>.historical_prior`. Multiplied by the pilot's prior for
    the role being scored (looked up at call time from the priors
    dict threaded into score_battle). Cold-start pilots with no prior
    contribute 0.
    """
    return coef(coefs, f"{prefix}.historical_prior") * prior


# Registry of compute functions. The historical function takes an
# extra `prior` kwarg; callers use compute_role_decomposition which
# knows about the priors dict.
COMPUTE_REGISTRY: dict[str, Callable[..., float]] = {
    "structural": compute_structural_score,
    "temporal": compute_temporal_score,
    "hull": compute_hull_score,
    "historical": compute_historical_score,
}


def compute_role_decomposition(
    f: FeatureRow,
    coefs: dict[str, float],
    weight_set_prefix: str,
    active_classes: tuple[str, ...] = ACTIVE_CLASSES,
    prior: float = 0.0,
) -> ScoreDecomposition:
    """Compute all active score classes + final for one (character, role).
    `prior` is the per-char per-role historical prior; only consumed by
    compute_historical_score. Pass 0.0 for cold-start pilots."""
    per_class: dict[str, float] = {}
    for cls in active_classes:
        fn = COMPUTE_REGISTRY.get(cls)
        if fn is None:
            per_class[cls] = 0.0
            continue
        if cls == "historical":
            per_class[cls] = fn(f, coefs, weight_set_prefix, prior)
        else:
            per_class[cls] = fn(f, coefs, weight_set_prefix)
    raw_final = sum(per_class.values())
    return ScoreDecomposition(
        structural=per_class.get("structural", 0.0),
        temporal=per_class.get("temporal", 0.0),
        hull=per_class.get("hull", 0.0),
        historical=per_class.get("historical", 0.0),
        final=clamp01(raw_final),
    )


# ---------------------------------------------------------------------
# Thresholds + gaps are stored as coefficient rows under
# coefficient_key = 'thresholds_and_gaps_v0.<name>'.
# ---------------------------------------------------------------------

@dataclass(frozen=True)
class Thresholds:
    # Per-role threshold + gap. gap only used for single-winner roles.
    fc_threshold: float
    fc_gap: float
    logi_threshold: float
    mainline_threshold: float
    mainline_gap: float
    tackle_threshold: float
    bomber_threshold: float
    command_threshold: float
    command_gap: float

    def threshold_for(self, role: str) -> float:
        return {
            ROLE_FC: self.fc_threshold,
            ROLE_LOGI: self.logi_threshold,
            ROLE_MAINLINE: self.mainline_threshold,
            ROLE_TACKLE: self.tackle_threshold,
            ROLE_BOMBER: self.bomber_threshold,
            ROLE_COMMAND: self.command_threshold,
        }.get(role, 1.0)

    def gap_for(self, role: str) -> float:
        return {
            ROLE_FC: self.fc_gap,
            ROLE_MAINLINE: self.mainline_gap,
            ROLE_COMMAND: self.command_gap,
        }.get(role, 0.0)


def load_thresholds(coefs: dict[str, float]) -> Thresholds:
    return Thresholds(
        fc_threshold=coef(coefs, "thresholds_and_gaps_v0.fc_threshold"),
        fc_gap=coef(coefs, "thresholds_and_gaps_v0.fc_gap"),
        logi_threshold=coef(coefs, "thresholds_and_gaps_v0.logi_threshold"),
        mainline_threshold=coef(coefs, "thresholds_and_gaps_v0.mainline_threshold"),
        mainline_gap=coef(coefs, "thresholds_and_gaps_v0.mainline_gap"),
        tackle_threshold=coef(coefs, "thresholds_and_gaps_v0.tackle_threshold"),
        bomber_threshold=coef(coefs, "thresholds_and_gaps_v0.bomber_threshold"),
        command_threshold=coef(coefs, "thresholds_and_gaps_v0.command_threshold"),
        command_gap=coef(coefs, "thresholds_and_gaps_v0.command_gap"),
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
    priors: dict[tuple[int, str], float] | None = None,
) -> ScoringResult:
    thresholds = load_thresholds(coefs)
    scores: list[ScoreRow] = []
    priors = priors or {}

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

            prior_val = priors.get((f.character_id, role), 0.0)
            decomp = compute_role_decomposition(f, coefs, prefix, active_classes, prior_val)
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "structural", decomp.structural))
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "temporal", decomp.temporal))
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "hull", decomp.hull))
            if "historical" in active_classes:
                scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "historical", decomp.historical))
            scores.append(ScoreRow(f.character_id, f.sub_fleet_id, role, "final", decomp.final))
            finals[role] = decomp.final

        final_by_char[f.character_id] = finals

    # Group characters by sub-fleet for assignment.
    by_sub: dict[int, list[int]] = defaultdict(list)
    for cid, sf in sub_fleet_by_char.items():
        by_sub[sf].append(cid)

    # Per-sub-fleet assignment: generic across single-winner vs
    # set-membership roles.
    # winners[char_id] = (role, primary_score, second_best_score)
    winners: dict[int, tuple[str, float, float]] = {}
    per_sub_diag: list[dict] = []

    for sf, cids in sorted(by_sub.items()):
        diag: dict[str, object] = {"sub_fleet_id": sf, "member_count": len(cids)}

        # per-char qualifications: list of (role, primary, second) tuples
        quals_by_char: dict[int, list[tuple[str, float, float]]] = defaultdict(list)

        for role in ROLES:
            thr = thresholds.threshold_for(role)
            if role in SET_MEMBERSHIP_ROLES:
                qualifying = [c for c in cids if final_by_char[c][role] >= thr]
                for c in qualifying:
                    quals_by_char[c].append((role, final_by_char[c][role], thr))
                diag[f"{role}_count_above_threshold"] = len(qualifying)
            else:
                ranked = sorted(cids, key=lambda c: final_by_char[c][role], reverse=True)
                top = final_by_char[ranked[0]][role] if ranked else 0.0
                second = final_by_char[ranked[1]][role] if len(ranked) > 1 else 0.0
                gap = thresholds.gap_for(role)
                assigned = None
                if top >= thr and (top - second) >= gap:
                    assigned = ranked[0]
                    quals_by_char[assigned].append((role, top, second))
                diag[f"{role}_top_score"] = round(top, 4)
                diag[f"{role}_gap_to_second"] = round(top - second, 4)
                diag[f"{role}_assigned"] = assigned is not None

        for c, quals in quals_by_char.items():
            quals.sort(key=lambda t: t[1], reverse=True)
            role, primary, second = quals[0]
            winners[c] = (role, primary, second)

        per_sub_diag.append(diag)

    # Hull-category overrides (logi / bomber / command). Applied before
    # the Monitor FC override so the latter still has final say for
    # Monitor pilots (Monitor hulls are tagged 'command' in the mapping).
    for f in features:
        cat = f.ship_class_category or ""
        forced = HULL_CATEGORY_GUARANTEED_ROLE.get(cat)
        if forced is None:
            continue
        forced_score = final_by_char[f.character_id].get(forced, 0.0)
        winners[f.character_id] = (forced, forced_score, 0.0)

    # Monitor override: any pilot flying a GUARANTEED_FC_SHIP_TYPE_IDS
    # hull gets FC unconditionally, replacing any other role the
    # single-winner rule would have picked. Multiple Monitor pilots in
    # the same sub-fleet all get FC (co-FC semantics) — this is the
    # only path that bypasses the gap requirement.
    for f in features:
        if f.ship_type_id in GUARANTEED_FC_SHIP_TYPE_IDS:
            fc_score = final_by_char[f.character_id][ROLE_FC]
            winners[f.character_id] = (ROLE_FC, fc_score, 0.0)

    # Materialize inference rows.
    inferences: list[InferenceRow] = []
    for cid, (role, primary, second) in winners.items():
        fc = completeness_by_char[cid]
        if role in SET_MEMBERSHIP_ROLES:
            # set-membership confidence uses distance-from-threshold in
            # place of gap-to-second, because there is no "second best".
            conf = _logi_confidence(primary, thresholds.threshold_for(role), fc)
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
