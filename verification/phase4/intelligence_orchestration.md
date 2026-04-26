# Phase 4.9A + 4.9E — compute orchestration + platform observability

Verification snapshot 2026-04-27.

## Schema

Migration `2026_04_27_020000_create_phase49a_compute_orchestration.php`:

- `compute_run_log` — per-invocation traceability. Fields:
  `lane`, `pipeline`, `viewer_bloc_id`, `compute_version`,
  `status` (running / succeeded / failed / aborted),
  `compute_started_at`, `compute_finished_at`,
  `compute_duration_ms`, `source_row_count`,
  `generated_row_count`, `error_message`, `args_json`,
  `stats_json`. Indexes on lane + pipeline + status + bloc.
- `compute_lane_metrics` — per-lane rolling rollup. Fields:
  `pending_jobs`, `running_jobs`, `succeeded_24h`,
  `failed_24h`, `avg_duration_ms`, `p95_duration_ms`,
  `oldest_pending_seconds`, `throughput_per_hour`,
  `lane_state` (healthy / degraded / backlogged / starved /
  failed). UNIQUE on lane.
- `system_quality_events` — detector findings. 8 detector kinds
  × 4 severity tiers, ack/resolve workflow per row, UNIQUE
  on (detector, viewer_bloc_id, window_start, window_end) so
  re-runs upsert.

8 lanes: ingest, parser, graph, operational, doctrine,
intelligence_generation, governance, maintenance.

## ComputeLog wrapper

`python/counter_intel/phase49a_orchestration.ComputeLog` is a
context manager that:

```python
with ComputeLog(conn, lane='intelligence_generation',
                pipeline='phase47-daily-digest', viewer_bloc_id=1,
                args={'window': 'last_7d'}) as r:
    stats = run_daily_digest(...)
    r.set_generated_rows(1)
    r.set_stats(stats)
```

- writes a row with status='running' on entry (committed
  immediately so a long-running pipeline can be observed
  mid-flight)
- on exit without exception: status='succeeded',
  compute_finished_at, compute_duration_ms, source/generated
  row counts, stats_json
- on exit with exception: status='failed', error_message
  (truncated 500 chars). Does not suppress.

## Instrumented pipelines

Wrapper applied to flagship pipelines via `cli.py` handlers:
- `phase4-threat-surface` (lane: operational)
- `phase47-daily-digest` (lane: intelligence_generation)
- `phase47-strategic-alerts` (lane: intelligence_generation)
- `phase47-incident-narratives` (lane: intelligence_generation)
- `phase48-alert-suppression` (lane: governance)
- `phase48-trust-metrics` (lane: governance)
- `phase49-freshness` (lane: maintenance)
- `phase49a-lane-metrics` (lane: maintenance, self-instrumented)
- `phase49e-quality-guards` (lane: maintenance)

Other phases run uninstrumented; their lane state shows as
`healthy` with 0 throughput. Adding instrumentation is one
line per handler.

## Lane metrics first run

`make ci-phase49a-lane-metrics` after a short workload:

| lane                    | state   | run | succ24h | fail24h | p95 ms | tput/h |
|-------------------------|---------|-----|---------|---------|--------|--------|
| ingest                  | healthy | 0   | 0       | 0       | —      | 0.00   |
| parser                  | healthy | 0   | 0       | 0       | —      | 0.00   |
| graph                   | healthy | 0   | 0       | 0       | —      | 0.00   |
| operational             | healthy | 0   | 0       | 0       | —      | 0.00   |
| doctrine                | healthy | 0   | 0       | 0       | —      | 0.00   |
| intelligence_generation | healthy | 0   | 1       | 0       | 4393   | 0.04   |
| governance              | healthy | 0   | 0       | 0       | —      | 0.00   |
| maintenance             | healthy | 1   | 1       | 0       | 98     | 0.04   |

Lane state derivation:
- failed: any failures, no successes
- starved: oldest running > 1h
- backlogged: ≥4 running concurrently
- degraded: failed/(succ+failed) ≥ 0.20
- healthy: otherwise

## Quality guards

8 detectors:

| detector                     | scope          | trigger                     |
|------------------------------|----------------|-----------------------------|
| incident_explosion           | per-bloc       | 24h count ≥ 3× 7d daily mean (≥50 floor) |
| corridor_explosion           | per-bloc       | new corridors 7d ≥ 5× prior 7d (≥50 floor) |
| doctrine_mismatch_explosion  | per-bloc       | ≥30% comps with doctrine_match_pct < 0.30 over 14d |
| impossible_fleet_size        | per-bloc       | composition reports >2500 ships in 48h |
| duplicate_narrative_loop     | per-bloc       | ≥10 narratives identical body in 24h |
| stale_compute_chain          | per-bloc       | flagship pipeline last run > 36h ago |
| parser_drift                 | cross-cutting  | eve_log_parse_errors > 5% of events 24h |
| unknown_event_spike          | cross-cutting  | event_type='unknown' > 8% of events 24h |

First run: `parser_drift` fired critical (112,076 parse errors
in 24h vs 7,121 successful events — backlog from earlier
parser regressions). Other 7 detectors clean.

## /portal/intelligence/platform-health

Dashboard layout:

- Top pulse strip: events 24h, parse error rate, unknown event
  rate, alerts 24h, incidents 24h. Color-coded against
  thresholds (red ≥ 5% parse errors, orange ≥ 8% unknown).
- Compute lanes table: state, running, succ/fail 24h, avg/p95
  durations, oldest pending, throughput per hour.
- Surface health table: per-surface (alert / digest / narrative
  / incident / corridor / force_composition / alliance_profile
  / threat_surface / doctrine_evolution). Health derived from
  freshness_state distribution:
  - failed: ≥95% expired
  - stale: <5% fresh AND ≥50% expired
  - degraded: ≥50% expired
  - backlogged: <30% fresh
  - healthy: otherwise
- Recent compute runs table: last 40, with started, lane,
  pipeline, status, duration, source→generated row counts.
- Open quality events panel with ack/resolve buttons.
- Long-running jobs (status='running' for >15 minutes) — shows
  with red border-left when present.

## Idempotency

- compute_run_log: append-only on enter, single UPDATE on exit.
- compute_lane_metrics: UPSERT on UNIQUE lane.
- system_quality_events: UPSERT on (detector, viewer_bloc_id,
  window_start, window_end). Re-running a detector with the
  same window updates severity + summary instead of duplicating.

## Caveats

1. Most lanes show 0 throughput because most pipelines are not
   yet instrumented (Phase 4.1-4.6, Phase 1-3, ingest, parser,
   graph). Adding `with ComputeLog(...)` to each cli.py handler
   is mechanical follow-up work.
2. `parser_drift` first-run fired critical against historical
   parse errors — the count is real but the rate is misleading
   because backlog parse-errors carry their original
   `created_at`. Consider re-running detectors after a
   `make eve-log-retry-parse-errors` clean-up.
3. No autonomous schedules. Lane metrics + quality guards
   remain manual `make` targets per the platform's standing
   directive.
4. compute_lane_metrics treats `running_jobs` as the primary
   pending signal; full pending-queue depth tracking would
   require a queue table outside the run-log. Acceptable for
   v1 since most pipelines are make-target driven, not queued.
