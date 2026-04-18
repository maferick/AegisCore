# Spec 7 v1 — calibration via historical priors

First pass of the calibrated scoring layer. Historical priors added
as a new `score_class`; new weight_version `v1_calibrated_seed`
cloned from v0 + 0.15 FC / 0.10 logi / 0.15 mainline historical
coefficients.

## Commands

| Command | Purpose |
|---------|---------|
| `battle:refresh-priors` | Nightly — recompute character_role_historical_priors from the last 90 days |
| `battle:evaluate-calibration --weight-version=N` | Score inference accuracy vs attestations, write battle_role_calibration_runs row per role |
| `battle:promote-weight-version N --roles=logi` | Flip is_default + ui_state to 'production' for passing roles |

## Evaluation results on the 8 validation battles

| Role | Truth source | Correct | Total | Score | Threshold | Passed |
|------|-------------|---------|-------|-------|-----------|--------|
| fc | donor attestations (Spec 6) | 0 | 12 | 0.00 | 0.75 | no |
| logi | ship_class_category='logi' derived | 33 | 33 | F1=1.00 | 0.80 | **YES** |
| mainline_dps | top damage_share per sub-fleet | 1 | 19 | 0.05 | 0.60 | no |

**Logi promoted to `production` ui_state.** FC + mainline stay `beta`
until next calibration pass lifts accuracy.

## FC gap

Monitor pilot on 40553 scores 0.8125 under v1 weights (command-edge
path + 0.15 * 0.75 historical prior = +0.1125 boost over v0). Tied-ish
with Claymore co-FCs at 0.79. Gap = 0.0225 < fc_gap=0.15 → still
silent. Co-FC tie is the blocker; next Spec 7.x iteration should
either lower fc_gap or raise the command-edge hull prior further.

## Mainline gap

The calibration uses top-damage_share per sub-fleet as a weak
proxy for the "true" mainline anchor. Most sub-fleets don't get a
mainline assignment at all (gap requirement), so 1/19 is expected
until mainline thresholds are tuned.

## Schema changes

- `character_role_historical_priors` — nightly-refreshed priors
- `battle_role_calibration_runs` — per-evaluation accuracy audit
- CHECK on `battle_role_scoring_weights.score_class` + on
  `battle_character_role_scores.score_class` relaxed to admit
  `'historical'` (forward-compatible, no row rewrites)
