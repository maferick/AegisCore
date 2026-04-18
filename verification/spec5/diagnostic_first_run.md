# Spec 5 v0 diagnostic first run — 2026-04-18

Observations from running `battle_role_scoring` v0 seed
(`weight_version = v0_scoring_seed`) against the 8 validation battles.
This document is **Spec 7's starting material** for calibration.

## Structural verification — all pass

| Check                                       | Result |
|---------------------------------------------|--------|
| 12 score rows per char (3 roles × 4 classes)| 665/665 chars ✓ |
| Final score values in `[0, 1]`              | 0 out-of-range ✓ |
| One inference row per char (option C)       | 36/36 single-winner ✓ |
| `confidence_band` values valid              | {high: 24, medium: 10, low: 2} ✓ |
| Confidence in `[0, 1]`                      | 0 out-of-range ✓ |
| Inference row FK integrity                  | 0 orphans ✓ |
| Idempotency (re-run byte-identical)         | `diff` = empty ✓ |

## Outcome summary

| Battle | FC | logi | mainline_dps | Notes |
|--------|----|----|-------------|-------|
| 40365  | 0  | 0  | 1           | Amamake pirate gang — no logi on side |
| 40228  | 0  | 0  | 0           | Aldranette — all silent |
| 40374  | 0  | **4** | 0        | **matches truth set: 4 Scimis** |
| 40541  | 0  | 0  | 1           | Sigma — FC silent (Monitor tied with Drake Navy) |
| 40478  | 0  | 0  | 0           | Atioth bomber + Thrasher wings — all silent |
| 40537  | 0  | **4** | 0        | **matches truth set: 4 Deacons** |
| 40605  | 0  | **12** | 0       | **matches truth set: 12 Scimis** |
| 40553  | 0  | **13** | 1       | **matches truth set: 13 Scimis**; +1 mainline_dps |
| **Total** | **0** | **33** | **3** | |

Logi recall against truth set: **33/33 across 4 battles with logi** — 100%.
Logi precision: no false positives across the other 4 battles.

FC recall against truth set (12 FC labels soft+strong): **0/12**.

## Finding 1 — zero FC assignments across 8 battles

Expected per stance: v0 thresholds + gap are conservative.
`fc_threshold = 0.55`, `fc_gap = 0.15`.

Top-2 fc_score candidates per sub-fleet across the 8 battles were
inspected. Patterns:

- **FC candidates are almost never command-hull pilots.** The top
  fc_score in most sub-fleets is a high-participation DPS pilot
  (Muninn, Nightmare, Brutix Navy, Typhoon, Thrasher, Kikimora).
  This is because `fc_weights_standard` gives a 0.35 weight to
  `degree_centrality` and only a 0.15 hull prior for `command`;
  the behavioral signal swamps the command prior.
- **Gaps are usually < 0.15** when two pilots in the same wing fly
  the same ship (identical structural scores). 40374 sf0 Muninn
  pilots tied at 0.826, 40605 sf0 Nightmare pilots tied at 0.985,
  etc.
- **Known command-hull FCs don't reach top-2.** The Monitor on
  40541 sf1 scores 0.667 in the command-edge path (hull prior 0.60
  + structural 0.067; context bonuses don't trigger because
  `subfleet_has_logi = 0`). Drake Navy Issue pilots in the same
  sub-fleet score 0.701 via standard path and win the candidate
  slot, but tie with each other so no assignment fires.

**Conclusion**: v0 FC scoring is behaviorally-weighted to the point
that it identifies top-damage pilots, not FCs. The command-edge
path, even with its relaxed condition, does not overcome the
standard path for pilots with non-trivial killmail footprint.

## Finding 2 — command-edge path triggered but under-weighted

On 40541 sub_fleet 1, the Monitor pilot **did** enter the
command-edge scoring path (`ship_class_category='command'` AND
`subfleet_dominant_hull_class='other'`). The relaxed condition works
— it's the coefficient values that don't compete.

Monitor command-edge decomposition on 40541 sf1:
- structural: `0.05 * 0.64 + 0.05 * 0.70 = 0.067`
- temporal: `0.05 * 0 = 0` (presence_span=0; other temporal weights absent)
- hull: `0.60 * command_prior = 0.60`
  (context bonuses 0 — `non_sf0_with_logi` requires subfleet_has_logi=1, false here)
- final: `0.667`

A Drake Navy Issue pilot on the same sub_fleet scores ~`0.70` via
standard path. Monitor loses by 0.03.

**Calibration lever for Spec 7:** either raise the command-edge hull
prior (0.60 → 0.85?), raise context bonus defaults, or give the
standard FC path a lower ceiling for non-command-hull candidates.
None of these are Spec 5 changes.

## Finding 3 — 100% logi recall + zero false positives

The logi scorer works as designed on v0 seed:

- 40374: all 4 Scimis identified, 0 other chars flagged as logi
- 40537: all 4 Deacons identified, 0 other chars flagged
- 40605: all 12 Scimis identified, 0 other chars flagged
- 40553: all 13 Scimis identified, 0 other chars flagged
- 40365, 40228, 40541, 40478: no pilots flagged as logi (truth set
  expects none on these battles)

Confidence on logi rows: avg 0.78 across 33 rows, band distribution
skewed `high` (24 of 33 rows). The behavior-first logi signal is
strong — Scimis/Deacons have characteristic low `damage_share` + high
`kill_participation_rate` + `hull_prior.logi = 0.45` that cleanly
separates them from every other hull category.

**Implication for Spec 7:** logi coefficients may already be "good
enough" for v1 release. FC coefficients are the calibration target.

## Finding 4 — mainline_dps assignments are plausible

3 mainline_dps assignments across 8 battles (40365, 40541, 40553).
Each is a top-damage pilot in their sub-fleet with no close
runner-up. None of the 3 is a known FC per truth set, which suggests
the single-winner option-C resolution is correctly routing each
character to their dominant role.

No false mainline assignments overlapped with logi assignments
(option C guarantees this per-char, but also visible in the data —
no char appears in both counts).

## Finding 5 — zero mainline assignments on 5 of 8 battles

Battles 40228, 40374, 40478, 40537, 40605 produced zero
mainline_dps assignments. Reasons (from the per-sub-fleet diagnostics):

- **Ties**: Muninn lines on 40374, Nightmare lines on 40605, Purifier
  bombers on 40478 sub_fleet 2. Gap = 0, no winner.
- **Below threshold**: small ad-hoc wings on 40374 sub_fleet 2/3
  barely crossed `damage_share` signal.

Mainline "silence" when everyone's doing the same thing is the right
behavior per stance. Whether Spec 7 wants to surface "sub-fleet is
uniformly mainline" as a distinct claim is a future design question.

## Suggested Spec 7 actions (non-prescriptive)

1. **Calibrate FC coefficients** — probably the biggest single
   impact. Options:
   - raise `fc_weights_command_edge.hull_prior.command` from 0.60
   - shift some weight from `fc_weights_standard.degree_centrality`
     toward `fc_weights_standard.hull_prior.command`
   - introduce a per-hull clamp (a non-command hull cannot score above 0.6 on fc)
2. **Lower FC threshold or gap** — but this risks assigning FC to
   the top-damage pilot in every fleet (wrong).
3. **Revisit the command-edge context bonuses** — `non_sf0_with_logi`
   is a fleet-doctrine assumption that doesn't hold on small gang.
   Either add an alternate bonus for "is only command hull in sub-fleet"
   or drop the precondition on having logi.
4. **Accept logi coefficients as v1-ready** — no need to change if
   recall + precision on the 8-battle set is acceptable as-is.

## Determinism spot-check

Re-ran battle 40541 under `weight_version = v0_scoring_seed` and
diffed all 600 score rows (role × class × char). Diff was empty;
re-run byte-identical modulo `updated_at`. Idempotency ✓.

## Replay

```
bash verification/spec5/run_batch.sh
docker compose ... exec -T mariadb ... aegiscore < verification/spec5/semantic_checks.sql
```
