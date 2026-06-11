# Phase A — spy detection signal expansion (sample-gate relaxation + low_contribution)

date: 2026-05-01
trigger: dossier rendered Bakkanta one as `clean` despite raw anomaly row showing 75% asymmetric outbound on 3 shared days and 100% hostile community on 3 graph neighbours; both signals dropped by sample-size gates that demanded ≥5 / ≥20.

## Scope

Three changes to `app/app/Domains/CounterIntel/Services/CounterIntelDossierService.php` `phase1Signals()`:

1. `asymmetric_pair` battles_min: 5 → 3 (low confidence below 5).
2. `community_mismatch` neighbor_min: 20 → 5 (low confidence below 50, unchanged).
3. New phase1 signal `low_contribution` from existing `avg_damage_share` feature column.

ADR 0011 paper trail: `calibration_proposals` rows (baseline_ref=`phase-a-spy-signals`).

ADR 0013 binding: each new fire still ships the six binding fields (confidence, evidence text, source refs via `raw`, sample_size, freshness via dossier render diag, why-strengthened via banding co-fire promotion).

## Expected behaviour

### Bakkanta one (character_id=2124244672)

Pre-change render: `band=clean`, `flag_count=0`, `note_count=0`.

Post-change, with refreshed anomaly row (currently being computed by `scripts/ci-daily-pipeline.sh`), the dossier should fire two new signals:

- `asymmetric_pair` (note severity, low confidence) — 75% outbound vs 14% inbound on 3 shared days with `Bakkanta Aviai Odunen`.
- `community_mismatch` (note severity, low confidence) — 100% hostile-tagged community on 3 graph neighbours.

Aggregate band: `note_only` (2 signals → elevated → confidence-low demotion → note_only).

### Cohort effect

Lowering sample gates expands the renderable signal pool. Risk: false-positive inflation on small-sample characters. Mitigation: confidence='low' below historical thresholds + ADR 0013 demotion ladder collapses 2-signal `elevated` to `note_only` when confidence is low.

Verify post-deploy:

```sql
-- band distribution before/after, viewer_bloc=1, current window
SELECT rendered_band, COUNT(*) AS n
  FROM ci_render_diagnostics
 WHERE rendered_on = CURDATE() AND viewer_bloc_id = 1
 GROUP BY rendered_band
 ORDER BY FIELD(rendered_band,'critical','high','elevated','note_only','clean','insufficient_history');
```

If `note_only` pool grows by >3× pre-change baseline OR `elevated` grows by >50%, revisit gates.

### Bakkanta one expected SQL spot-check

```sql
SELECT rendered_band, raw_band, flag_count, note_count, evidence_summary
  FROM ci_render_diagnostics
 WHERE character_id=2124244672 AND viewer_bloc_id=1
 ORDER BY rendered_on DESC LIMIT 3;
```

After today's pipeline + dossier re-render, expect band ∈ {`note_only`, `elevated`} with non-zero `note_count` and the new signal codes (`asymmetric_pair`, `community_mismatch`, possibly `low_contribution` if his attacker count grows past 5).

## Out of scope (Phase B, separate spec)

- Operational proximity (system co-presence pre-event)
- Reaction-timing correlation (login event ingestion)
- Event-triggered activity (strategic event marker source)
- Multi-signal corroboration tightening beyond existing co-fire pass

## Files touched

- `app/app/Domains/CounterIntel/Services/CounterIntelDossierService.php` (3 edits)
- `calibration_proposals` (3 INSERT, baseline_ref=`phase-a-spy-signals`, status=`adopted`)
- `verification/ci-phase-a-spy-signals/README.md` (this file)
