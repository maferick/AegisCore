# CI Phase 1 — First-run diagnostic

## Run

- Window: 2026-04-19 (90d)
- Candidates: 204,211
- Updated: 204,211 in 10m19s on prod-sized data
- Bloc-relative: bloc=1 (WinterCo), window 2026-04-20, 17,561 candidates

## Coverage

| Field | Filled | % of total |
|---|---:|---:|
| dormancy_max_gap_days | 184,359 | 90.3% |
| pod_survival_rate | 177,643 | 87.0% |
| battle_only_score | 60,501 | 29.6% |

`battle_only_score` is gated by `killmails_attacker >= 10`, so the
gap is expected — most rolling-window candidates do not clear that
floor.

## Flag-fire rates (current dossier thresholds)

| Signal | Population | Rate |
|---|---:|---:|
| corp_hopping flag (distinct >=4 AND min_tenure <=30) | 96,589 | **47.3% — too loose** |
| battle_only flag (score >= 0.75) | 7,969 | 3.9% |
| dormancy_strategic flag (gap >=180 AND days_to_corp <=30) | 3,170 | 1.6% |

## Observations to feed Phase 2 calibration

1. **`corp_tenure_min_days = 0` is dominant for "0d shortest" rows.**
   ESI's `character_corporation_history` returns multiple rows for the
   same corp per character with `start_date` very close to the previous
   `end_date`; sometimes `start_date == end_date` to the second. The
   raw `min` will see those as 0d tenures.

   Fix candidates:
   - Require `corp_tenure_min_days >= 1` to skip the noise.
   - Pre-compute "shortest non-zero tenure" alongside.
   - Coalesce duplicate consecutive corp_id rows during the read.

2. **corp_hopping flag at 47% population is a calibration failure.**
   The signal needs to compare against cohort baseline. Spec §7.1
   calls for "Normalize against the correct cohort: same alliance,
   same role, same activity band, same TZ".

   Phase 2 tightening proposals (try in order):
   - Bump `distinct_corps_all_time` floor to 6 (keeps corp_hopping
     signal at distinct >=4 as a *note* rather than a flag).
   - Add stdev gate: only flag when `corp_tenure_stdev_days < 200`
     (regular cadence, vs random churn).
   - Eventually, normalize against alliance-cohort distribution.

3. **`battle_only_score` looks reasonable.**
   3.9% population rate, signals correlate with FC / logi-only /
   purpose-built characters. Worth seeding the ground-truth set with
   a sample to confirm.

4. **`pod_survival_rate >= 0.95` is a weak signal** as expected —
   most active pilots ratting in null-sec routinely escape pods. The
   spec already calls this "weak alone, useful in combination". The
   render code only emits the corresponding `note` (never `flag`),
   which is correct.

5. **Asymmetric pair signal slow to compute.** ~30s per character
   on the multi-join SQL across `killmail_attackers` + hostile-alliance
   filter. For 17K rows × 30s ≈ 145min worst case. Worth optimizing:
   pre-aggregate the per-pair distinct-day counts into a temp table
   per bloc before iterating.

## What NOT to change in Phase 1

Per `CLAUDE.md`: "v0 coefficient tuning: never during implementation,
even if first-run looks wrong." All threshold tuning above is logged
here for the Phase 2 calibration spec.
