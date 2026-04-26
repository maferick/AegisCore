# Phase 4.7 — analyst workflow + intelligence production

Verification snapshot 2026-04-26.

## Schema

Migration `2026_04_26_260000_create_phase47_intelligence_workflow_tables.php`:

- `daily_operational_digest` per (viewer_bloc, digest_date,
  window_kind ∈ today / last_24h / last_7d). Markdown narrative
  + structured JSON sections (top incidents, doctrine evolution,
  coalition movement, new corridors, unusual compositions,
  emerging operators, response anomalies, top threat systems).
- `strategic_alerts` — 8 alert kinds × 4 severity tiers. Stable
  composite key prevents duplicate generation. ack/dismiss
  workflow per row.
- `incident_narratives` — one human-readable markdown blurb per
  (incident, generator_version).
- `intel_export_artifacts` — share_token (40-char) keyed
  shareable export. Markdown + JSON bodies. 30-day TTL.

## Compute

- `make ci-phase47-daily-digest VIEWER_BLOC=1 CI_ARGS="--window last_7d"`
  → 1 digest row, 1418 incidents in window (1306 noise / 97
  tactical / 15 strategic), 12 top incident picks.
- `make ci-phase47-strategic-alerts VIEWER_BLOC=1 CI_ARGS="--lookback-days 60"`
  → 274 alerts written. Distribution:
  - sudden_doctrine_shift: 16 watch + 22 elevated
  - capital_escalation: 1 watch + 2 elevated
  - hostile_deployment_migration: 199 watch + 27 elevated
  - corridor_pressure_spike: 3 elevated
  - large_strategic_cluster: 2 elevated + 2 urgent (FDZ4-A
    957- and 788-ship Maelstrom strategic clusters)
- `make ci-phase47-incident-narratives VIEWER_BLOC=1 CI_ARGS="--since-hours=2160 --limit=300"`
  → 300 narratives written.

## Routes

```
GET /portal/intelligence/daily       IntelligenceDigest
GET /portal/intelligence/alerts      StrategicAlerts (ack/dismiss livewire)
GET /portal/intelligence/fc          FcTactical
GET /portal/intelligence/director    DirectorStrategic
GET /portal/intelligence/search      OperationalSearch
GET /portal/intelligence/exports     IntelligenceExports
GET /portal/intel/share/{token}      IntelExportShareController (40-char regex)
```

All bloc-scoped on every result set. Share-token route is
viewer-bloc gated server-side: a token from bloc 1 returns 403
for a viewer in bloc 3 even with the URL.

## §4.7C — narrative renderer

Sample output (incident #39497):

> **NOISE** hostile contact in **F-NMX6**, opening at 2026-04-26
> 13:36:19. 1 hostile cluster(s) fed the incident; 1 unique
> reporter signature(s) across them. Linked to battle theater
> #1449403.

Narrative pulls from clusters + force compositions + battle
linkage. Renders inside the existing `/portal/operations/incidents/{id}`
dossier above the Severity Reasoning panel.

## §4.7D — view splits

- **FC tactical** (`/portal/intelligence/fc`): 6h default
  window. Active threats, recent strong clusters, open
  urgent/elevated alerts, hot reinforcement/staging/escalation
  corridors, hottest systems, fastest-response systems.
- **Director strategic** (`/portal/intelligence/director`):
  30d default. Heat-tier strip, coalition behavior table,
  alliance profile table, severity stacked-bar trend, doctrine
  evolution, deployment migrations.

## §4.7E — operational search

Single search box resolves the term against:
- system names (operational_incidents)
- alliance names (alliance_operational_profiles)
- doctrine canonical names (auto_doctrines)
- ship type names (ref_item_types — combat hull groups only)
- corridor endpoints
- character names (operator_operational_fingerprints)
- timeline summaries
- battle theater id (numeric exact match)

Returns up to 15 hits per category, bloc-scoped.

## §4.7F — exports

5 artifact kinds:
- operational_report (severity counts + top incidents + open alerts)
- strategic_summary (coalitions + alliances + doctrine events)
- corridor_map (top 80 corridors)
- incident_timeline (chronological window dump)
- doctrine_evolution_report (events by magnitude)

2 formats: markdown / json. Body persisted; share_token URL
serves the body inline or as attachment (`?dl=1`).

## Idempotency

- digest: ON DUPLICATE KEY (bloc, date, window_kind) → re-runs
  refresh same row.
- alerts: composite UNIQUE on (bloc, alert_kind, detected_at,
  related_incident_id, related_corridor_id,
  related_doctrine_event_id, primary_alliance_id) — re-running
  the evaluator does not duplicate.
- narratives: UNIQUE (incident_id, generator_version). Bumping
  generator_version forks history without overwriting prior.
- exports: not idempotent by design — each generate creates a
  new token + immutable body so analysts can compare versions.

## Caveats

1. Digest "today" / "last_24h" both use today UTC — only
   meaningful if compute runs near end-of-day.
2. `hostile_deployment_migration` alerts dominate (199 watch).
   Threshold (≥5 transits, ≥3 chars) is loose; calibration pass
   is a follow-up.
3. Coalition footprint metric on the comparison table still
   reads global distinct systems (Phase 4.6B carry-over).
4. No predictive AI / autonomous triage. Alerts surface what
   already happened; analysts decide what to do.
