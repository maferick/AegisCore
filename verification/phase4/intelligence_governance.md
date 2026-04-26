# Phase 4.8 — intel governance, trust, analyst controls

Verification snapshot 2026-04-26.

## Schema

Migration `2026_04_26_270000_create_phase48_intel_governance_tables.php`:

- `strategic_alerts` extended with `analyst_status` (new /
  acknowledged / validated / suppressed / false_positive /
  archived), `analyst_notes`, `analyst_confidence_override`,
  `false_positive`, `suppressed_until`, `suppression_reason`,
  `suppression_rule_id`, `reviewed_by_user_id`, `reviewed_at`.
  Indexes on (analyst_status, detected_at) + (suppressed_until).
- `daily_operational_digest` extended with
  `section_confidence_json`, `evidence_summary_json`,
  `source_reliability_json`.
- `incident_narratives` extended with
  `source_incident_ids_json`, `source_cluster_ids_json`,
  `source_dscan_snapshot_ids_json`,
  `source_timeline_event_ids_json`, `source_battle_id`,
  `narrative_confidence`.
- New tables:
  - `intel_feedback_events` — analyst feedback corpus (8 kinds:
    useful / misleading / noisy / duplicate / strategic /
    incorrect_escalation / incorrect_doctrine /
    incorrect_linkage).
  - `intel_alert_suppression_rules` — declarative suppression
    policies (5 kinds + manual_block).
  - `verified_intelligence_items` — analyst-curated layer
    (pinned_incident, curated_summary, strategic_event,
    analyst_note, narrative_override).
  - `system_trust_metrics` — per-surface trust score + tier.

## Compute

`make ci-phase48-alert-suppression VIEWER_BLOC=1`
→ `{"rows_modified": 66}`

| state           | n   | suppression_reason populated |
|-----------------|-----|------------------------------|
| new             | 224 | 0                            |
| archived (stale)| 50  | 50                           |

Stale-archive rule (>30d, no analyst review) collapsed 34 rows;
duplicate-collapse marked another 16. (224 visible alerts down
from 274 raw.)

`make ci-phase48-enrich-digest-trust VIEWER_BLOC=1`
→ `{"digests_written": 1}` — sample row top_incidents tier:
"high", doctrine_evolution tier: "insufficient" (no recent
events in 7d window), avg_reporter_reliability: 0.251.

`make ci-phase48-enrich-narrative-sources VIEWER_BLOC=1 CI_ARGS="--since-hours=4320"`
→ `{"narratives_traced": 300}`. Each narrative now exposes
contributing incident_ids, cluster_ids, dscan_snapshot_ids,
timeline_event_ids, battle_id, plus a narrative_confidence
score derived from evidence breadth.

`make ci-phase48-trust-metrics VIEWER_BLOC=1 CI_ARGS="--window-days 60"`
→ `{"surfaces_written": 7}`.

| surface          | items | useful | suppressed | trust | tier      |
|------------------|-------|--------|------------|-------|-----------|
| alert            | 274   | 0      | 16         | 0.477 | low       |
| digest           | 1     | 0      | 0          | 0.500 | adequate  |
| narrative        | 300   | 0      | 0          | 0.500 | adequate  |
| incident         | 9888  | 0      | 0          | 0.500 | adequate  |
| corridor         | 3144  | 0      | 0          | 0.500 | adequate  |
| alliance_profile | 642   | 0      | 0          | 0.500 | adequate  |
| threat_surface   | 390   | 0      | 0          | 0.500 | adequate  |

Trust formula (0.6×useful_rate + 0.3×(1−fp_rate) +
0.1×(1−suppression_rate)). Zero-feedback baseline = 0.5 minus
0.4×suppression_rate. Alert surface dipped to 0.477 because
suppression evidence already exists; other surfaces sit at
the innocent 0.5 baseline pending feedback.

## Routes

```
GET  /portal/intelligence/alerts        StrategicAlerts (extended)
GET  /portal/intelligence/daily         IntelligenceDigest (extended)
GET  /portal/intelligence/verified      VerifiedIntelligence (new)
GET  /portal/intelligence/trust         TrustOverview (new)
+ existing /fc, /director, /search, /exports, /share/{token}
```

## §4.8A — alert lifecycle UI

Alert card now shows `analyst_status` chip + lifecycle action
buttons:
- validate → status=validated + records `useful` feedback
- acknowledge → status=acknowledged
- suppress 7d → status=suppressed, suppressed_until=now+7d,
  records `noisy` feedback
- false positive → status=false_positive, false_positive=1,
  records `misleading` feedback
- archive → dismissed_at=now(), status=archived

Inline notes editor (`<details>`) saves to `analyst_notes`.
Suppression metadata row surfaces reason + reviewed_at.

## §4.8B — digest trust surface

Each section header in `/portal/intelligence/daily` now shows a
confidence badge:
`conf [tier] · [score]`. Source-reliability strip above the
metric cards exposes `avg_reporter_reliability`,
`reporter_count`, severity_distribution, with the disclaimer
"narratives are summaries, not certainty — verify before
acting."

## §4.8C — narrative traceability

Narrative panel in incident dossier renders:
- confidence chip from `narrative_confidence`
- `<details>` "why did the system say this?" expanding to
  source counts + cluster IDs + dscan snapshot links.

## §4.8D — analyst feedback loop

Per-incident feedback widget on dossier — 8 buttons (one per
feedback_kind). Each click inserts into `intel_feedback_events`
and surfaces the count next to the button.

Feedback also inserts implicitly via alert lifecycle setStatus
(validated→useful, suppressed→noisy, false_positive→misleading).

## §4.8E — auto-suppression

5 mechanisms in `phase4_governance.run_alert_suppression`:
1. Duplicate collapse (same alert_kind +
   primary_alliance/system + corridor within 6h → suppressed
   until +24h).
2. Corridor spam (≥10 deployment_migration alerts on same
   from_system in 7d → keep top transition_count, suppress
   rest).
3. Low-confidence incident filter (incident.confidence =
   insufficient + alert.severity = watch → suppressed +14d).
4. Stale escalation decay (>30d, no analyst review → archived).
5. Persistent intel_alert_suppression_rules (declarative
   per-bloc rules with target_kind + system/alliance/corridor
   filters + active_until).

## §4.8F — verified intelligence layer

`/portal/intelligence/verified` lets analysts:
- create pinned_incident / curated_summary / strategic_event /
  analyst_note / narrative_override items
- mark strategic_significance (low / medium / high /
  coalition_level)
- pin / unpin / publish / delete
- attach related_incident_id and related_alert_id

Pin button on every incident dossier inserts a
pinned_incident (idempotent — toggles existing record).

Verified items render at the top of the incident dossier so
human-curated judgments outrank automated narratives.

## §4.8G — trust dashboard

`/portal/intelligence/trust` surfaces:
- Per-surface trust scoreboard (8 columns: items, useful, fp,
  suppressed, overrides, trust score, tier).
- Feedback histogram (last 60d, by surface × kind).
- Alert lifecycle distribution.
- Verified intelligence corpus counts.
- Active suppression rules.

## Idempotency

All five governance computes UPSERT on stable keys; re-running
the same args refreshes rather than duplicates.
- suppression: only flips `new` rows to `suppressed`; never
  deletes.
- trust metrics: UNIQUE (bloc, surface, window_end,
  window_days).

## Caveats

1. Trust scores at baseline 0.5 across most surfaces because
   no analyst feedback exists yet. The system is now ready to
   collect it.
2. Suppression-vs-archive precedence: the stale-archive rule
   runs first against a 30-day cutoff and absorbed 16
   duplicate-flagged rows that would otherwise show up as
   `suppressed`. This is acceptable because archive carries
   `suppression_reason` and is reversible by changing the
   alert's analyst_status manually.
3. Per-bloc trust score does not yet drive UI dimming
   downstream. It's an analyst-facing metric; integrating
   "low trust → grey out" on the digest/alert surfaces is a
   follow-up.
4. Verified intelligence is single-creator — no review/approve
   queue. Analysts trust each other within a bloc.
5. No autonomous schedules. All 4 governance computes remain
   manual `make` targets per the user's directive.
