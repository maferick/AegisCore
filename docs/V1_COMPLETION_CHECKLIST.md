# V1 Completion Checklist

Burn-down for v1 operational closure. Goal: trustworthy intelligence
infrastructure. Predictive / recommendation / autonomous / Phase 6
features stay deferred to v2 — see `memory/project_v1_v2_split.md`.

Status legend:
- ☑ done
- ◐ partial / needs follow-up
- ☐ not started

Each section ends with a **Gate** — the criterion that lets the
section flip from ◐ to ☑. Sections without an open Gate can be
considered closed for v1.

---

## 1. Ingestion

- ☑ killmail_stream + killmail_backfill operational
- ☑ market_poller + market_import schedulers operational
- ☑ EVE log uploader (.NET 8 Worker Service) cross-compiled, .zip
  distribution + member README
- ☑ uploader token revoke/rotate UI
- ☑ uploader 422/500 fault path remediated (offset arithmetic,
  base64 transport, closure capture, log_type detection)
- ☐ controlled rollout: trusted FCs + intel + multi-TZ pilots
- ☐ ingest throughput dashboard (events/h split by uploader/source)
- **Gate**: telemetry diversity — at least 5 active uploaders
  spanning ≥3 timezones, sustained 7 days. Ingest throughput
  visible on platform-health for ≥7 days without intervention.

## 2. Parsing

- ☑ EVE log parser (chat / gamelog / notify / motd flavors)
- ☑ showinfo:TYPE//ID rich-text link parser (system, corp, alliance,
  character)
- ☑ dscan link recognition + deferred fetch
- ☑ partial timestamp + reported_count extraction
- ☑ MOTD reclassification + intel hint heuristics retired
- ☑ entity resolver replaces heuristic name extractor
  (`eve_log_entity_resolutions`)
- ☑ dscan.info HTML parsing (no JSON API)
- ☑ parser_drift detector split into current / historical
- ◐ unknown_event_spike threshold tuning under live uploader load
- **Gate**: 14-day sustained period with `current_parser_drift`
  staying ≤ 5% AND `unknown_event_spike` ≤ 8% across all instrumented
  blocs.

## 3. Counter-Intel (Phase 1 / 1.5 / 2 / 2.5)

- ☑ phase1 bloc-agnostic + bloc-relative signals
- ☑ co-fire banding, confidence penalties, ci_render_diagnostics
- ☑ ci_watchlist_entries + portal page
- ☑ phase2 hostile triangulation, cohort, community normalization
- ☑ corp_hopping demoted from primary signal
- **Gate**: closed for v1.

## 4. Operational intelligence (Phase 4.x)

- ☑ phase4 timelines (4.1)
- ☑ entity resolution (4.2A)
- ☑ operational hostile clusters (4.3A)
- ☑ operational incidents + battle linkage (4.3B/C/E)
- ☑ system_operational_activity heatmap (4.3D)
- ☑ operational corridors (4.4C)
- ☑ system_response_times (4.4E)
- ☑ system_threat_surface (4.4F)
- ☑ dscan integration (snapshots, ship classes, cluster/incident promotion)
- ☑ operations timeline page + incident dossier + heatmap
- **Gate**: closed for v1.

## 5. Doctrine analysis (Phase 4.5 / 4.6)

- ☑ operational_force_compositions
- ☑ operational_force_transitions
- ☑ doctrine fingerprint Jaccard match against auto_doctrines
- ☑ alliance_operational_profiles
- ☑ coalition_behavior_comparisons
- ☑ doctrine_evolution_events
- ☑ corridor route classification (staging/reinforcement/etc)
- ☑ operator_operational_fingerprints (non-identity)
- ◐ "defensive" classifier bias when April composition data sparse —
  calibration once dscan coverage thickens
- ◐ coalition footprint metric is global not per-bloc — fix in
  Phase 4.6B SQL once analysts use the comparison view enough to
  notice
- **Gate**: April composition coverage ≥ March; bias calibration
  re-run; per-bloc footprint corrected.

## 6. Governance (Phase 4.8)

- ☑ alert lifecycle (analyst_status + notes + override + suppression)
- ☑ digest trust surface (per-section confidence + evidence + reliability)
- ☑ narrative traceability (source incident/cluster/dscan/timeline pointers)
- ☑ feedback loop (intel_feedback_events, 8 kinds)
- ☑ auto-suppression engine (5 mechanisms)
- ☑ verified_intelligence_items (pin/note/curate/publish)
- ☑ system_trust_metrics (per-surface tier)
- ◐ trust scores at 0.50 baseline — needs analyst feedback corpus
- ☐ low-trust dimming on downstream surfaces (digest/alert) once
  scores deviate
- **Gate**: ≥ 200 feedback events recorded across ≥ 5 surfaces;
  at least one surface trust_score below 0.50 (signal that the
  scoring is doing real work, not just baseline).

## 7. Observability (Phase 4.9 / 4.9A / 4.9E)

- ☑ intelligence freshness across 12 surfaces (Phase 4.9)
- ☑ compute_run_log + ComputeLog wrapper
- ☑ compute_lane_metrics across 8 lanes
- ☑ system_quality_events (8 detectors)
- ☑ /portal/intelligence/platform-health
- ☑ flagship pipelines instrumented (Phase 4.9A first pass)
- ☑ remaining Phase 4.x / 4.5 / 4.6 / 4.8 pipelines instrumented
- ☑ uninstrumented-lane marker
- ☑ parser_drift split into current / historical
- ☐ retry safety + bounded back-off across compute_run_log entries
  (currently `tries=1` with exception → status='failed', no
  automatic retry)
- ☐ stale_compute_chain detector live-fires after a real missed run
  (currently only fires on hand-stopped pipelines)
- **Gate**: 14-day sustained healthy lane states across all 8 lanes
  with at least 1 successful run per lane per day.

## 8. Retention

- ◐ TTL ladder shipped — 21 retention specs across all data
  surfaces. Source of truth: `python/counter_intel/phase49c_retention.RETENTION`
- ◐ `make ci-phase49c-retention` (+ `--dry-run`) target shipped
- ☑ Dry-run validated 2026-04-27 against bloc 1 corpus: 241
  failed-fetch dscan snapshots eligible, 0 elsewhere (platform
  too young; meaningful sweep starts as `eve_log_events` crosses
  the 90d mark)
- ☑ first live sweep run 2026-04-27: 241 dscan rows dropped, 0
  elsewhere, all specs complete, no errors
- ☑ outbox.processed_at 7-day TTL added to retention spec
  (Storage audit Stage 1 follow-up); second live sweep 2026-04-27
  dropped 2,012 stale processed outbox rows
- ☑ host cron lines installed: retention (15 4 * * *) +
  lane-metrics (0 */6 * * *) + quality-guards (30 */6 * * *) +
  freshness (45 */6 * * *) + sde-auto-update (30 8 * * *)
- ☐ dashboard surfaces verified to show no rows older than the
  configured window
- **Gate**: retention sweep cron in place + first sweep ran
  successfully across all TTL'd tables. Dashboard surfaces show
  no rows older than the configured window.

## 9. Orchestration

- ☑ 8 lanes defined: ingest / parser / graph / operational /
  doctrine / intelligence_generation / governance / maintenance
- ☑ ComputeLog wrapper + lane metrics rollup
- ☑ flock-guarded battle-process-pending (sequential within
  invocation; flock blocks overlapping ticks)
- ☑ orphan reaper for `docker compose run` containers
  (BATTLE_ORPHAN_TTL_MIN, default 30 min)
- ☑ sde-auto-update host script with throttle + lock
- ☑ Neo4j thread budget documented (16 Bolt slots) +
  `neo4j_thread_pressure` detector covers ≥80% utilisation +
  RUNBOOK recipe
- ☑ retry/back-off wired into 13 priority pipelines
  (timelines / fleet-participation / intel-reliability /
  hostile-clusters / incidents / force-comps + transitions /
  threat-surface / alliance-profiles / coalition-comparisons /
  doctrine-evolution / digest / alerts / narratives) — each
  uses appropriate POLICIES entry + CircuitOpenError clean-skip
- ☑ platform-health top-retry-pipelines panel (24h: pipeline,
  reason, retries, runs, success %)
- ☑ retry/back-off policy: phase49d_retry.py with RetryClass
  (transient / contention / rate_limit / permanent /
  malformed_input), RetryPolicy + 5 default policies (compute
  / parser / graph / dscan_fetch / uploader_ingest), exponential
  back-off + jitter + cap, per-class budgets so permanent +
  malformed never retry
- ☑ circuit breaker: compute_circuit_state per (lane, pipeline);
  5 consecutive failures in 10min opens for 5min cooldown
  (×2 on re-open, capped 30min); half-open + close cycle;
  emits `circuit_open` system_quality_event
- ☑ ComputeLog persists retry_count + retry_reason +
  circuit_state to compute_run_log on exit
- ☑ platform-health: lane table shows retries column +
  circuit count badge; new ⚡ Open circuits panel
- ☑ RUNBOOK retry section: normal vs degradation behavior,
  open-circuit recipe, manual-close procedure, retry-storm
  suppression
- ☑ neo4j thread starvation incident response captured in
  RUNBOOK + script flock + reaper guards
- **Gate**: zero "insufficient threads" responses from Neo4j
  for ≥ 7 days under live cron load.

## 10. Platform-health correctness

- ☑ uninstrumented-lane marker prevents healthy/0 false positives
- ☑ parser_drift split eliminates false criticals
- ☑ surface health derived from freshness distribution, not raw rates
- ◐ alert pulse / incident pulse use "last 24h" hardcoded — should
  parametrise window selector
- ☑ `incident_explosion` retuned 2026-04-27 against bloc-1 baseline
  (178/d mean, 392/d max → ratio 4×/6×/10× + abs floor 200)
- ☑ `doctrine_mismatch_explosion` retuned 2026-04-27 (baseline 64%
  miss → trigger floor 70%, severities at 70/80/90)
- ☑ `neo4j_thread_pressure` detector added — proxies long-running
  graph/operational compute_run_log rows against documented 16-slot
  Bolt cap
- ☑ runbook recipe added for `neo4j_thread_pressure`
- **Gate**: zero open critical events on platform-health for
  ≥ 7 days that turned out to be false on review.

## 11. ABAC / authorization

- ☑ MarketHubAccessPolicy enforces ADR-0005 intersection rule
- ☑ User::isAdmin gate on raw log access
- ☑ ABAC audit trail for parser failure queue
- ☑ bloc-scoping on every intelligence portal page
- ☑ share-token route bloc-gated (not just token-gated)
- ☑ intel_audit_log table + App\Services\IntelAuditLog helper
  (resolves actor user/alliance/bloc at write-time, captures
  IP + user_agent, redacts secret-named keys, append-only)
- ☑ wired into: alert setStatus / saveNotes, verified_item
  create/togglePin/publish/delete, dossier recordFeedback +
  pinIncident, export generate + share view/download
- ☑ retention rule added: 365-day TTL on intel_audit_log
- **Gate**: every analyst-mutable surface writes to an audit table
  on change; spot-check via 10 random alert lifecycle changes
  matched against audit rows.

## 12. Exports / sharing

- ☑ intel_export_artifacts table + 5 artifact kinds
- ☑ markdown + JSON export bodies
- ☑ shareable share_token route, bloc-scoped
- ☐ export retention sweep (covered under §8)
- ☐ rate-limit on `generate` button (currently single-click; could
  spam if a Livewire action loop misbehaves)
- **Gate**: rate limit + retention sweep both in place.

## 13. Freshness

- ☑ freshness_state on 12 surfaces
- ☑ source_window_start / source_window_end columns
- ☑ Python compute pass + PHP read-time helper
- ☑ TTL ladder per surface, shared between Python + PHP
- ☑ pills wired on 8+ pages
- ☑ TTL ladder centralised: `python/counter_intel/intel_ttl.json`
  (canonical) mirrored to `app/config/intel_ttl.json`. Both
  sides load at startup. `make verify-ttl-config` enforces
  byte-identical equality and exits 1 on drift. Python loads
  via importlib path; PHP loads via `config_path('intel_ttl.json')`
  with first-call cache + fallback constants.
- **Gate**: TTL config defined once, consumed by both Python +
  PHP at startup. No drift possible between sides — verified by
  Make target.

## 14. Calibration

- ☑ ADR 0011 — v1 calibration policy: what gets calibrated,
  how (capture → analyse → propose → adopt), when (one-off
  on analyst complaint, quarterly sweep, major data shifts),
  authority + audit (no autonomous calibration; recalibration
  commits include verification/calibration/<date>_<reason>.md)
- ☑ baseline snapshot 2026-04-27:
  `verification/calibration/baseline_2026_04_27.json`
  captures bloc-1 incident/cluster/corridor counts, doctrine
  match rate, alert distribution, trust scores at snapshot
  time, all detector thresholds active. Future recalibrations
  diff against this.
- ☐ collect 200+ analyst feedback events spanning all 7
  instrumented surfaces (gate for trust-weight recalibration)
- ☐ first quarterly recalibration sweep (90 days post-freeze)
- ☐ recalibrate operational_style classifier when April force
  composition coverage thickens
- **Gate**: ADR + baseline shipped. Quarterly cadence kicks in
  90d post-freeze. Trust-weight recalibration gated on the
  200-event / 5-surface corpus criterion.

## 15. UX

- ☑ FC tactical view + director strategic view
- ☑ daily digest reader + window switcher
- ☑ strategic alerts board with lifecycle controls
- ☑ incident dossier with narrative + traceability + feedback widget
- ☑ verified intelligence layer page
- ☑ trust overview + platform health pages
- ☑ operational search across 8 entity types
- ☐ mobile / narrow viewport audit (most pages use grid templates
  that don't gracefully collapse on phones)
- ☐ keyboard shortcuts for common analyst actions (ack alert,
  pin incident, advance window)
- **Gate**: usable on tablet (≥768px viewport); analyst workflow
  exercised by ≥3 different humans for ≥1 week each.

## 16. Documentation

- ☑ verification/phase4/ docs for every phase (4.1 → 4.9B)
- ☑ ADR-0001 → 0006 covering placement, ESI, market, hub overlay
- ☑ AGENTS.md plane boundary rules — refreshed with V1 closure section
- ☑ CLAUDE.md project brief — refreshed with V1 closure status
- ☑ docs/RETENTION.md — TTL ladder + sweep operations + restore
  procedure
- ☑ docs/RUNBOOK.md — recipe per quality_event detector + lane state
  + known incidents (orphans, parser drift, thread starvation, SDE
  rollback, partial backups, retry/circuit breaker triage)
- ☑ docs/CONTRACTS.md — refreshed with V1 operational contracts
  + V1 closure references
- ☑ docs/adr/0011-v1-calibration-policy.md (was 0007 in original
  plan; bumped because 0007-0010 were already taken)
- ☑ docs/ROADMAP.md — Phase 4 + V1/V2 boundary documented
- ☑ docs/ADR-market-orders-partitioning.md — proposed for v2
- ☑ verification/storage/db_storage_audit.md
- ☑ verification/calibration/baseline_2026_04_27.json
- **Gate**: runbook covers every quality_event detector + every
  lane state transition with a known recipe.

---

## Operational validation (cross-cutting)

Use the platform during real coalition events. After each event,
walk through:

- did the system surface the right things?
- what was noisy?
- what was useful?
- what was late?
- what was missing?

File observations as `intel_feedback_events` rows + verified
intelligence items where appropriate. After 5+ events, summarise
into a calibration report (feeds §14).

---

## Burn-down rules

1. **No new feature surfaces in v1.** Every PR until v1 closure
   either burns down a checklist item or fixes a regression.
2. **Each ☐ → ◐ requires** a verification doc note describing
   what shipped + what remains.
3. **Each ◐ → ☑ requires** the section's Gate criterion to have
   been observed live for the stated window.
4. **No autonomous schedules added** beyond what's already
   documented (per Phase 4.8 directive).

When every section is ☑, file a closure note + start v2 entry
review against the criteria in `memory/project_v1_v2_split.md`.
