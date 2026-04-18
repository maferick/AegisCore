# Spec 7 — bulk aggregation report (2026-04-18)

Ran Specs 2→3→4→5 across 150 candidate `(battle_id, alliance_id)` pairs
(actual: 90 successful + ~60 skipped due to theaters re-clustering
away from the original candidate ids between candidate-selection and
batch execution).

## Dataset after batch

| Metric | Value |
|--------|-------|
| Distinct battles with features | 21 |
| Distinct (battle, alliance) pairs | 90 |
| Feature rows | 5 929 |
| Qualifying characters (≥2 battles) | 1 021 |
| Historical prior rows | 3 063 (3 per char × 1 021) |
| Inference rows under v1_calibrated_seed | 776 |

## Role assignment breakdown

| Role | Inference rows | Truth n | Correct | Accuracy/F1 | Threshold | Passed |
|------|---------------:|--------:|--------:|------------:|----------:|:------:|
| **logi** | **127** | 127 | 127 | F1=1.0000 | 0.80 | ✅ |
| **tackle** | **485** | 491 | 485 | F1=0.9939 | 0.70 | ✅ |
| **bomber** | **86** | 86 | 86 | F1=1.0000 | 0.80 | ✅ |
| fc | 37 | 12 | 2 | 0.1667 | 0.75 | ❌ |
| command | 28 | 78 | 28 | 0.3590 | 0.60 | ❌ |
| mainline_dps | 13 | 152 | 5 | 0.0329 | 0.60 | ❌ |

**3 roles promoted to production** (logi, tackle, bomber) = **698 live
inference rows** on battle reports. 3 roles remain **beta** (fc, command,
mainline_dps) = 78 rows rendered but gated for future calibration.

## Historical prior distribution

| Role | Rows | Avg prior | Max prior |
|------|-----:|----------:|----------:|
| fc | 1021 | 0.029 | 0.750 (Monitor pilot) |
| logi | 1021 | 0.109 | 0.900 |
| mainline_dps | 1021 | 0.290 | 0.806 |

FC priors are right-skewed: most pilots have near-zero FC prior
because command-hull usage is rare. The 0.75 max is Monitor pilot
93444333 (verified truth-set FC).

## Calibration gaps (input for Spec 7.x)

- **FC at 0.17**: Monitor override works cleanly but non-Monitor
  command-hull FCs (Damnation, Claymore, Stork, Bifrost, Pontifex,
  Vulture, Eos) still don't clear threshold+gap. Fix options:
  - lower `fc_gap` from 0.15 to 0.05 (risk: false positives)
  - raise `fc_weights_standard.hull_prior.command` from 0.15 → 0.30
  - expand `GUARANTEED_FC_SHIP_TYPE_IDS` to include Damnation/Claymore
    when the pilot is the only command-hull in the sub-fleet
- **Command at 0.36**: ties between command-hull pilots on
  degree_centrality block assignment. Need a deterministic tie-break
  (e.g. lowest character_id) + lower `command_gap`.
- **Mainline at 0.03**: gap requirement too restrictive for big fleets
  where Nightmares / Muninns tie. Set-membership model (like logi)
  may fit better than single-winner.

All three gaps are **tuning decisions**, not bugs. The scoring
substrate is computing the right values — v0/v1 coefficients simply
don't differentiate well enough yet.

## What's live after this pass

- `v1_calibrated_seed` is `is_default = 1`
- Battle reports auto-pick v1 inference via `BattleRoleInferenceLoader`
- 698 inference rows in `ui_state='production'` (logi, tackle, bomber)
- 78 rows in `ui_state='beta'` (fc, command, mainline_dps)
- Nightly priors refresh scheduled at 03:15 UTC
- `/admin/fc-attestations` shows 12 seeded truth-set attestations
  available for FC calibration iteration

## Next cycle hints

- Lower `fc_gap` / expand Monitor-style override set → FC pass
- Add tie-break rule + lower `command_gap` → command pass
- Switch mainline to set-membership OR raise mainline hull prior → mainline pass
- Ewar role: add 'ewar' category to `ship_class_category_mapping`
  seed + CHECK, seed ~20 ewar hulls, add `ewar_weights_standard`
