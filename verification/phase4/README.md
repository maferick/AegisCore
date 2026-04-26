# Phase 4 — log-derived operational analytics

Implementation lands ahead of telemetry. Verification artefacts live
here as the uploader rolls out and real events flow.

## Schema

Migration `2026_04_26_130000_phase4_drop_scaffold_create_log_derived.php`
drops the unused `ci_*_rolling` Phase 4 placeholder tables and
creates the four log-derived tables this phase produces:

- `operational_timeline_events`     §4.1 (10 timeline_type values)
- `fleet_presence_windows`          §4.2 (5 derived_role values)
- `intel_reliability_profiles`      §4.3
- `session_correlation_edges`       §4.4

## Compute

`python/counter_intel/phase4.py` — four idempotent passes. Each
accepts a window (since-hours or window-end + window-days) and
UPSERTs by the unique key on its target table. All four functions
support `dry_run=True` (only `run_timelines` exposes it via CLI for
now; others can be added once the data shape is stable).

CLI subcommands and make targets:

```
make ci-phase4-timelines             VIEWER_BLOC=N CI_ARGS="--since-hours=168"
make ci-phase4-fleet-participation   VIEWER_BLOC=N CI_ARGS="--since-hours=168"
make ci-phase4-intel-reliability     VIEWER_BLOC=N CI_ARGS="--window-end=YYYY-MM-DD --window-days=30"
make ci-phase4-session-correlation   VIEWER_BLOC=N CI_ARGS="--window-end=YYYY-MM-DD --window-days=30"
```

## Health check

```
php artisan counter-intel:phase4-status [--bloc=N]
```

Prints per-table row counts, latest timestamps, breakdown by enum.
First run output (no events ingested yet) is recorded in
`first_run_empty.txt`.

## Render

The Counter-Intel dossier on the character lookup card and the
`/portal/counter-intel` overview page both grow Phase 4 sections
that render only when the relevant tables have rows.

Privacy rule per ADR-0009:
- Derived counts + structured event_summary strings only.
- Raw chat content never reaches the dossier or dashboard.
- Raw `eve_log_events.raw_line` access stays gated to the audited
  `/admin/eve-log/events` admin page.

## Calibration backlog

Once events flow:

1. Tune `TIMELINE_CLUSTER_MINUTES` (default 5) against real form-up
   patterns — likely too narrow for AU-TZ ops where chat rate is
   slower.
2. Tune `MIN_INTEL_REPORTS_*` floors against real reporter cadence
   in your bloc's intel channel.
3. Tune `SESSION_BUCKET_MINUTES` (default 5) — narrow buckets
   surface tighter "synchronised activity" but raise the
   false-positive rate on coincidental TZ overlap.
4. Add `silence_before_hostiles` + `repeated_hostile_overlap`
   compute (currently zeros — placeholders kept for the column shape).
5. Replace the heuristic `_extract_hostile_names_from_intel` with
   a proper esi_entity_names join.

Document each calibration change in `verification/phase4/<change>.md`.
