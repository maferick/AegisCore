# 0009 — Phase 4 operational event intelligence

Status: scaffolded
Date: 2026-04-26

## Context

Once the Phase 3 EVE log uploader (Windows Worker Service +
`POST /api/eve-log-ingest/chunk`) starts producing rows in
`eve_log_events`, we can derive operational intelligence the
killmail stream cannot see: who was actually in fleet, who reported
intel, when hostile drops landed, who stayed silent before a loss.

Phase 4 adds four classes of derived metrics, all keyed to the
viewer bloc.

## Tables (this commit)

- `ci_fleet_participation_rolling` — per-character fleet attendance
  vs killmail combat presence. Drives the **fleet lurker** signal.
- `ci_intel_reliability_rolling` — intel report rate, confirmation
  rate, false-positive rate, silence-before-loss count, avg delay.
- `ci_session_correlation_edges` — pairwise temporal correlation
  between two pilots based on shared session windows derived from
  `eve_log_events.event_timestamp` clusters.
- `ci_operational_timelines` — reconstructed event chains
  (fleet formup → engagement → response, hostile drop windows,
  self-destruct waves).

Compute jobs are stubs in this commit. The schema lands so the
dossier service can render placeholder evidence sentences and the
calibration spec has stable column names.

## Compute jobs (deferred)

The compute paths land as separate Python modules under
`python/counter_intel/phase4_*` once Phase 3 has produced enough
events to baseline against:

1. `phase4_fleet_participation.py`
   - Input: `eve_log_files.log_type='fleet'` + `eve_log_events`
     where `event_type='fleet_message'` AND
     `eve_log_files.session_started_at` falls inside a session
     window the user actually has telemetry for.
   - Per-character: count distinct fleet sessions attended (chat
     activity present), count combat killmails inside those windows,
     compute `fleet_lurker_score = 1 - (combat_kms / fleet_sessions)`
     when the denominator is meaningful.

2. `phase4_intel_reliability.py`
   - Input: `eve_log_events.event_type='intel_report'` + killmails.
   - Confirmation: an intel report names a hostile pilot and a
     killmail involving that pilot within ±N minutes ±M jumps lands.
   - False positive: report names hostile that never appears in
     killmails / local within ±N minutes.
   - Silence-before-loss: a friendly loss in a system where the
     reporter was online and producing chat lines but did not flag
     the threat in advance.

3. `phase4_session_correlation.py`
   - Input: `eve_log_events.event_timestamp` clustered into per-
     character session windows (gap > 1h splits).
   - For each (a, b) pair active in the same window, count the
     shared minutes. Normalise into a correlation score that
     resists co-active timezones.
   - Spec § Phase 4.3 — synchronized activity, hostile-response
     timing, probable same-operator behavior.

4. `phase4_operational_timelines.py`
   - Input: combined killmail + log events ordered by time + system.
   - Pattern matching for known sequences:
       fleet_formup       — burst of fleet_message events ±N min
                            before first engagement killmail
       hostile_drop       — capital killmail + cyno or capital victim
       self_destruct_wave — cluster of NPC pod kills inside a system
                            within minutes of each other
       response           — first friendly killmail after a hostile
                            arrival event in the same constellation

## Out of scope (Phase 4)

- Stylometry / writing fingerprint — Phase 6 ADR.
- Comms ingestion (Mumble / Discord) — separate spec.
- Realtime alerting — design lands once compute is stable.

## Read path

The Counter-Intel dossier service grows new evidence-rendering
methods that read these tables. Each new signal follows the existing
metadata contract:

- `reason_code`
- `severity`: flag / note / suppressed
- `confidence`: low / medium / high
- `sample_size`: meaningful underlying N
- `raw`: dict of the metric values

Banding remains co-fire — fleet_lurker alone never drives critical;
it drives critical only when paired with another flag.

## Privacy

Raw chat lines may contain sensitive coalition intelligence. The
dossier render must:

- Never echo raw chat content into the operator-facing card. Use
  derived counts and timestamps only.
- Permission-gate raw `eve_log_events` access to coalition
  leadership roles only (separate ABAC ticket).
- Redact PII / private comms when generating verification samples.
