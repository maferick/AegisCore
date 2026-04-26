# Phase 4.9B follow-up — parser drift cleanup, full instrumentation,
# materialization audit, uninstrumented-lane marker, SDE auto-update.

Verification snapshot 2026-04-27.

## 1. Parser drift split

`make eve-log-retry-parse-errors` returned `Replaying 0 parse_error
rows` — backlog already in `retried/dismissed/reparsed_ok` status,
not `open`. Detector was conflating the two.

Migration `2026_04_27_030000_split_parser_drift_detector.php`:
- extends `system_quality_events.detector` enum with
  `current_parser_drift` + `historical_parser_backlog`
- reclassifies any open `parser_drift` row as
  `historical_parser_backlog` severity=info

`phase49e_quality_guards._detect_parser_drift` now scopes:
- **current_parser_drift** — only `eve_log_parse_errors.status='open'`,
  thresholds 5/10/20% rate against successful events 24h
- **historical_parser_backlog** — retried/dismissed/reparsed_ok,
  always info severity. Triggers when ≥10× event count to surface
  visibility but never escalates platform health.

Re-run: `incident_explosion=0, corridor_explosion=0,
doctrine_mismatch_explosion=0, impossible_fleet_size=0,
duplicate_narrative_loop=0, stale_compute_chain=0,
parser_drift_split=1, unknown_event_spike=0`. Open critical
events: 0 (down from 1).

## 2. Full pipeline instrumentation

Added `with ComputeLog(...)` to every remaining cli.py handler:

| handler                              | lane                   |
|--------------------------------------|------------------------|
| phase4-timelines                     | parser                 |
| phase4-fleet-participation           | operational            |
| phase4-intel-reliability             | operational            |
| phase4-hostile-clusters              | operational            |
| phase4-incidents                     | operational            |
| phase4-system-activity               | operational            |
| phase4-corridors                     | operational            |
| phase4-response-times                | operational            |
| phase4-session-correlation           | graph                  |
| phase45-force-compositions           | doctrine               |
| phase45-force-transitions            | doctrine               |
| phase46-alliance-profiles            | doctrine               |
| phase46-coalition-comparisons        | doctrine               |
| phase46-doctrine-evolution           | doctrine               |
| phase46-route-pressure               | operational            |
| phase46-operator-fingerprints        | doctrine               |
| phase48-enrich-digest-trust          | governance             |
| phase48-enrich-narrative-sources     | governance             |

Combined with the original Phase 4.9A pass, every Python compute
entry point now writes a compute_run_log row on entry/exit. Lanes
that legitimately have no entries (e.g. `ingest`) signal that
nothing is reporting in — see §4.

## 3. Materialization audit

EXPLAIN against every dashboard query (StrategicAlerts,
IntelligenceDigest, FcTactical, DirectorStrategic,
OperationsHeatmap, OperationalSearch, OperationsIncidentDossier,
TrustOverview, PlatformHealth, VerifiedIntelligence).

Hot-path findings + fixes (migration
`2026_04_27_040000_phase49b_dashboard_indexes.php`):

| target table                    | query                      | finding                       | fix                                                                |
|---------------------------------|----------------------------|-------------------------------|--------------------------------------------------------------------|
| strategic_alerts                | open-status filter + sort  | full table scan + filesort    | composite (viewer_bloc, dismissed_at, status, severity, detected)  |
| doctrine_evolution_events       | magnitude sort             | full scan + filesort          | (viewer_bloc, magnitude)                                           |
| alliance_operational_profiles   | top-by-incident on window  | filesort                      | (viewer_bloc, window_end, incident_count)                          |
| compute_run_log                 | recent runs                | filesort fallback             | (compute_started_at)                                               |

Post-migration EXPLAIN: `doctrine_evolution_events` now uses
`idx_dee_magnitude` with `range` access. `strategic_alerts` still
chooses full scan because the table has only 274 rows — under
optimizer's index threshold; will start using
`idx_sa_dashboard_open` automatically as the corpus grows.

Live-join survey: every page already reads from materialized
tables; no expensive cross-store joins (Neo4j / OpenSearch /
InfluxDB are isolated). LIKE searches in OperationalSearch
(`timeline_summary LIKE '%term%'`) intentionally allow leading
wildcard since users search incident bodies — bloc filter
already cuts the scan to ≤ 9888 rows for bloc 1. Acceptable.

## 4. Platform health uninstrumented marker

`PlatformHealth::getViewData()` now joins `compute_run_log` to
identify lanes with zero historical entries. Those lanes display
`not instrumented` in grey rather than `healthy/0 throughput`.
Blade row collapses the metric columns into a single italicized
explanation cell so the operator immediately sees the difference
between "lane working but quiet" and "lane has nothing reporting."

After the full instrumentation pass plus a fresh
`make ci-phase49a-lane-metrics`:

| lane                     | state             |
|--------------------------|-------------------|
| ingest                   | not_instrumented  |
| parser                   | not_instrumented  |
| graph                    | not_instrumented  |
| operational              | not_instrumented  |
| doctrine                 | not_instrumented  |
| intelligence_generation  | healthy           |
| governance               | not_instrumented  |
| maintenance              | healthy           |

(`not_instrumented` for many lanes here because the rollup ran
before the new instrumented pipelines fired — they'll flip to
`healthy` automatically as soon as the first `make ci-phase4-*`
runs through the wrapper.)

## 5. SDE auto-update

`scripts/sde-auto-update.sh` (executable, 4KB):

1. `make sde-check` — refresh sde_version_checks row
2. read latest row → check `is_bump_available`
3. if bumped + last import older than 23h → `make sde-import`
4. on success → `make neo4j-sync-universe` (best-effort, non-fatal)
5. file-locked single-instance, logs to
   `scripts/log/sde-auto-update.log`

Make target: `make sde-auto-update`. Recommended host cron:

```
30 8 * * *  /opt/AegisCore/scripts/sde-auto-update.sh \
    >> /opt/AegisCore/scripts/log/sde-auto-update.log 2>&1
```

Override env vars:
- `AEGIS_SDE_AUTO_IMPORT=0` dry-run (check + report only)
- `AEGIS_SDE_FORCE=1` bypass 23h throttle
- `AEGIS_SDE_SKIP_NEO4J=1` skip neo4j-sync-universe step

Why host cron + not scheduler container: the scheduler image has
no docker socket; `sde-import` shells out to `docker compose run`
which the container can't do. Documented inline in the Makefile +
script header.

Dry-run output:

```
[…Z] running make sde-check
SDE version check result:
  pinned   : 56ed861baa9b181fbeb3c28548d7f9fa-10
  upstream : bd8449f83999959b623891f1447dbfa4-10
  bump     : YES
[…Z] AEGIS_SDE_AUTO_IMPORT=0 — bump available but auto-import disabled.
```

User must opt into the auto-import path by either:
- leaving `AEGIS_SDE_AUTO_IMPORT` unset (default enables it), or
- setting `AEGIS_SDE_AUTO_IMPORT=1` explicitly in cron.

## Caveats

1. The `not_instrumented` state requires a fresh
   `make ci-phase49a-lane-metrics` run after the first invocation
   of any newly-wrapped pipeline. Until then the lane will report
   the rollup from the last run.
2. SDE auto-import is destructive in the sense that a major SDE
   schema change could break downstream importers. The script
   does NOT roll back. CCP SDE bumps are additive in practice
   (new types, no column drops) but operators should monitor
   `scripts/log/sde-auto-update.log` after the first auto-bump.
3. `current_parser_drift` runs cross-cutting (no bloc scope) and
   is hardcoded to a 5/10/20% threshold ladder. If a single-bloc
   parser regression spikes only one chat channel's lines that
   sit below the global cutoff, the detector won't fire — fine
   for v1; may want per-bloc parser variants later.
