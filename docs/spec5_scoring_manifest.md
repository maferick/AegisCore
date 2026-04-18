# Spec 5 Scoring Manifest (v0, Uncalibrated)

Authoritative description of how the `battle_role_scoring` worker
computes per-character role scores and writes inference rows. If this
document and the extractor disagree, the extractor (`python/battle_role_scoring/score.py`)
wins.

## Epistemic stance (re-stated)

Spec 5 ships the **scoring substrate**, not calibrated values. v0
coefficients in `weight_version = v0_scoring_seed` are deliberate
guesses chosen to err toward silence (few or zero inference rows).
Any "the system got the FC wrong" observation on v0 is diagnostic
input for Spec 7, not a Spec 5 bug.

The one claim Spec 5 does make: **the scores are computed per the
formulas below**. Verification is structural (schema correctness,
determinism, idempotency) — not accuracy.

## Inputs / outputs

| Input table                               | Purpose                               |
|-------------------------------------------|---------------------------------------|
| `battle_character_role_features`          | 15 features per (char, sub-fleet)     |
| `battle_role_scoring_weights`             | coefficient rows per weight_version   |
| `battle_role_weight_versions`             | label → id resolution                 |

| Output table                              | Writes when                           |
|-------------------------------------------|---------------------------------------|
| `battle_character_role_scores`            | **always** — one row per (char, role, score_class) |
| `battle_character_role_inference`         | **only if** char clears one role's threshold + gap |

## Weight sets (v0 seed)

Five weight sets, all under `weight_version` labelled `v0_scoring_seed`:

| `coefficient_key` prefix              | role         | purpose                                                |
|---------------------------------------|--------------|--------------------------------------------------------|
| `fc_weights_standard`                 | `fc`         | Behavior-first FC scoring                              |
| `fc_weights_command_edge`             | `fc`         | Hull-dominant FC scoring (command-edge condition)      |
| `logi_weights_standard`               | `logi`       | Hull + behavior for logi detection                     |
| `mainline_dps_weights_standard`       | `mainline_dps` | Hull + behavior for DPS detection                    |
| `thresholds_and_gaps_v0`              | (meta)       | Per-role threshold + gap values                        |

### Command-edge FC condition (v0, relaxed)

```
ship_class_category = 'command'
AND subfleet_dominant_hull_class <> 'command'
```

Relaxed from the Spec 5 original (`is_in_subfleet_0=0 AND subfleet_has_logi=1`)
per review so the path actually triggers on realistic fleets where
the FC's own wing isn't command-dominant.

### Coefficient key convention

```
<weight_set_name>.<feature_name>[.<sub_key>]
```

Examples:
- `fc_weights_standard.degree_centrality`
- `logi_weights_standard.hull_prior.bomber`
- `fc_weights_command_edge.context_bonus.non_sf0_with_logi`
- `thresholds_and_gaps_v0.fc_threshold`

Missing coefficients contribute `0.0` — no error. Adding a class is
additive (below).

## Score classes and decomposition

`ACTIVE_CLASSES = ('structural', 'temporal', 'hull')` in `score.py`.

Each class has a pure compute function registered in `COMPUTE_REGISTRY`:

| Class      | Compute                                                |
|------------|--------------------------------------------------------|
| structural | `degree_centrality`, `pagerank`                        |
| temporal   | `presence_span`, `early_presence`, `late_presence`, `death_order_pct`, `damage_share`, `damage_share_inverse`, `kill_participation_rate` |
| hull       | `hull_prior.<category>` + `context_bonus.*` signals    |

`final = clamp01(sum(class_scores for class in ACTIVE_CLASSES))`.

Decomposed per-class scores are **signed**; `final` is clamped to
`[0, 1]`.

### Extending to `historical` (future)

1. Implement `compute_historical_score(feature, coefs, prefix)` in `score.py`.
2. Register in `COMPUTE_REGISTRY`.
3. Append `'historical'` to `ACTIVE_CLASSES`.
4. Seed coefficient rows under a new `weight_version`.

No schema change required — `chk_bcrs_score_class` was relaxed to
admit `'historical'` by migration `2026_04_18_030000_spec5_schema_prep`.

## Hull priors

Each role weight set declares a prior per hull category. The prior
applied to a character is the one matching `ship_class_category`.
Uncategorized characters (no `ship_type_id` ever observed) are
treated as `other` for prior lookup.

## Context bonuses

Applied conditionally inside `compute_hull_score`:

- `context_bonus.non_sf0_with_logi` — adds when char is `is_in_subfleet_0 = 0`
  AND `subfleet_has_logi = 1`
- `context_bonus.mixed_composition` — adds when `subfleet_hull_class_concentration < 0.7`
- `context_bonus.is_in_subfleet_0` — adds when `is_in_subfleet_0 = 1`

## Assignment (§7)

Single-winner per character (option C, chosen during Spec 5 review):

- **FC**: top candidate per sub-fleet; assign iff `top >= fc_threshold`
  AND `(top - second) >= fc_gap`
- **mainline_dps**: same pattern with `mainline_threshold` + `mainline_gap`
- **logi**: set-membership — all chars with `logi_score >= logi_threshold`
  are assigned logi, no gap requirement
- **Single winner**: for each character, pick the role with highest
  score among roles they qualified for. One inference row per char.

## Confidence

```
# FC, mainline_dps
confidence = clamp01(0.50 * top + 0.25 * (top - second) + 0.25 * feature_completeness)

# logi (no second; distance from threshold substitutes)
confidence = clamp01(0.50 * score + 0.25 * (score - logi_threshold) + 0.25 * feature_completeness)
```

Bands: `>= 0.80 → high`, `0.62–0.79 → medium`, `< 0.62 → low`.

## Concurrency

Three advisory locks (same sha1 convention as Specs 2–4):

| Lock                    | Prefix | Hash input                                      |
|-------------------------|--------|-------------------------------------------------|
| `graph_metrics_lock_key`| `bg_`  | `battle_graph:{b}:{a}:{ev}:{av}`                |
| `partition_lock_key`    | `bp_`  | `battle_partition:{b}:{a}:{pv}`                 |
| `scoring_lock_key`      | `bs_`  | `battle_scoring:{b}:{a}:{pv}:{wv}`              |

The scoring lock includes `weight_version` so two runs under
different weight versions can proceed in parallel.

## Idempotency

Score UPSERT key: `(battle_id, alliance_id, sub_fleet_id, character_id,
partition_algo_version, weight_version, role_key, score_class)`.

Inference UPSERT key: `(battle_id, alliance_id, sub_fleet_id, character_id,
partition_algo_version, weight_version)`.

Re-running under the same weight_version produces byte-identical
values except `computed_at`. `partition_algo_version` is part of
both PKs (fixed by Spec 5 schema prep migration, mirroring the Spec 3
fix on `battle_sub_fleets`).

## First-run observations (2026-04-18)

See `verification/spec5/diagnostic_first_run.md` for the full report.
Short version:

- **0 FC assignments** across 8 battles — expected per stance.
  The FC discriminator problem is a v0 calibration gap, not a bug.
- **100% logi recall** on 4 battles with logi pilots in the truth set
  (4/4 on 40374, 4/4 on 40537, 12/12 on 40605, 13/13 on 40553).
- **3 mainline_dps assignments** across 8 battles, each plausible.
- Behavior-dominant FC scoring ranks mainline DPS pilots above
  command-ship FCs; known calibration issue for Spec 7.

## Files

| File                                          | Purpose                                |
|-----------------------------------------------|----------------------------------------|
| `python/battle_role_scoring/score.py`         | Compute functions + assignment logic   |
| `python/battle_role_scoring/persist.py`       | UPSERT SQL                             |
| `python/battle_role_scoring/cli.py`           | Orchestrator + three-lock protocol     |
| `app/database/migrations/2026_04_18_030000_*` | Schema prep (PK rebuilds + CHECK)      |
| `app/database/migrations/2026_04_18_030001_*` | v0 seed (coefficients)                 |
| `verification/spec5/`                         | Verification artifacts                 |
