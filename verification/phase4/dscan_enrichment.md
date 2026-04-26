# Phase 4.4 — dscan enrichment

Date: 2026-04-26

## Fetch validation

Initial fetcher targeted a non-existent JSON API endpoint
(`/api/scans/{id}`). dscan.info v1 has no public JSON API; it serves
an HTML viewer at `/v/{id}` with embedded ship lists.

Rewrote the fetcher to parse HTML:

- `<ul id="ships">` block → `parseShipsFromHtml()` regex-extracts
  `{ship_type_name: count}`
- `<h3 class="panel-title">Ships<span class="badge ...">{N}</span>` →
  `parseTotalShipsBadge()` for authoritative total
- 0-ship results → status = `expired` (revoked snapshots Cloudflare
  serves as 200 with empty viewer)

First controlled run (`--limit=20`):

```
Done. ok=18 fail=2  (2 expired)
```

Sample success rows:
- `210955698bd7` — 154 ships: 70x Hurricane, 32x Ferox, 10x Scythe,
  8x Bellicose, 6x Sabre (Winter Coalition shield BC fleet)
- `f54c33c152ae` — 156 ships: same comp, snapshot moments later
- `2f4c202ed9de` —  25 ships: 7x Atron, 7x Condor, 3x Incursus
  (T1 frigate gang)
- `fb552593667f` —  11 ships: 5x Loki, 2x Panther, 1x Arazu, 1x
  Manticore, 1x Nemesis (covert ops + black ops)

Cache behavior: re-running the artisan against the same row is a
no-op (`fetch_status != pending` excludes), no hammering. Rate limit
respects `--rate-per-min` via `sleep`.

## Schema additions

```
operational_hostile_clusters
  + has_dscan TINYINT
  + dscan_total_ships INT
  + dscan_snapshot_ids_json TEXT

operational_incidents
  + has_dscan TINYINT
  + dscan_total_ships INT

system_threat_surface
  + dscan_score DECIMAL
```

## Quality + severity promotion rules

Cluster quality (after dscan presence detected):

- baseline (existing rules) → tier
- dscan present → tier + 1 step
- dscan ≥ 50 ships → at least `strong`
- dscan ≥ 150 ships → at least `strategic`

Incident severity (Phase 4.3E + dscan additions):

- cluster_quality = `strategic` → incident severity `strategic`
  (was: required 3+ signals)
- cluster_quality = `strong` → at least `tactical`
- has_dscan AND dscan ≥ 150 ships AND has hostile_cluster signal
  → severity `escalation`
- existing rules still apply (full hostile→combat→disengage =
  escalation, etc.)

## Before / after on bloc 1 (90d window)

### Cluster quality distribution

| quality      | before |  after | with_dscan |
|--------------|-------:|-------:|-----------:|
| noisy        |  8,705 |  8,674 |          0 |
| weak         |  1,978 |  2,008 |        103 |
| normal       |    229 |    291 |         88 |
| strong       |    343 |    323 |         28 |
| strategic    |      7 |     59 |         54 |

54 of 59 strategic clusters are dscan-promoted (≥150 ships in
attached snapshot).

### Incident severity distribution

| severity         | before | after |
|------------------|-------:|------:|
| `coalition_level`|      0 |     0 |
| `escalation`     |      0 |     3 |
| `strategic`      |      0 |    54 |
| `tactical`       |     95 |   406 |
| `noise`          |  9,736 | 9,449 |

Strategic + escalation tiers unlocked by:
- cluster-quality severity promotion (Phase 4.4 calibration)
- dscan ≥ 150 ship triggers (3 escalation candidates so far)

### Threat surface (top 5 systems)

| system  | threat | tier      | dscan_score |
|---------|-------:|-----------|------------:|
| H-5GUI  |  10.00 | strategic |         0.0 |
| 4-HWWF  |   8.06 | strategic |         0.0 |
| 7-K5EL  |   6.88 | hot       |         6.3 |
| YMJG-4  |   6.09 | hot       |         6.4 |
| 8TPX-N  |   4.92 | hot       |         0.0 |

H-5GUI / 4-HWWF have no dscan_score because their dscan snapshots
remain in the pending fetch queue. Only ~30 of 432 snapshots have
been fetched at this point — running the fetcher to completion will
fill in their scores.

## Calibration pass after dscan integration

Same session, four tightenings:

1. **`combat_spike` distinct-fingerprint floor 8 → 4.** Previous
   threshold rejected almost every fight on gamelog data because
   tick repeats from the same source share most of the message
   text. New floor produces 2 rows (was 1).

2. **`hostile_report` timeline emission disabled.** Per-event
   timeline rows generated 40,732 entries last pass; the same
   information lives in `operational_hostile_clusters` (Phase
   4.3A) which the incident-fusion layer reads directly. Kept the
   detector function as a stub returning `[]`; reversible via
   `PHASE4_EMIT_HOSTILE_REPORTS=1` env.

3. **Incident severity promotes from cluster quality alone.**
   Single strategic-quality cluster → strategic incident even
   without a paired combat signal. Single strong-quality cluster
   → tactical. (Already shipped in the dscan compute commit
   above; documented here for the calibration record.)

4. **timeline_events.solar_system_id from entity_resolutions.**
   `_load_events_window` LEFT JOINs the resolutions table and
   coalesces `system_name` from there when the event row's own
   `system_name` is NULL. Has no effect on gamelog combat/notify
   rows (no chat resolutions on those event ids); does help when
   the timeline detectors operate on chat-class events in the
   future.

### After calibration counts (bloc 1, 90d window)

| timeline_type        | rows |
|----------------------|-----:|
| fleet_formup         |  119 |
| self_destruct_wave   |   90 |
| crash_symptom        |   90 |
| escalation           |   89 |
| disengagement        |   83 |
| combat_spike         |    2 |
| **(hostile_report)** | 0 (collapsed into clusters) |

Total: 473 timeline rows (was 41,233 — 99% reduction).

| severity         | rows | with_dscan | with_system_id |
|------------------|-----:|-----------:|---------------:|
| `escalation`     |    3 |          3 |              3 |
| `strategic`      |   54 |         49 |             54 |
| `tactical`       |  408 |         29 |            311 |
| `noise`          | 9,423|        184 |          9,256 |

Strategic + escalation tiers populated (was 0). 76% of tactical
incidents now carry `primary_system_id` (was 12%). Strong shift
from "raw line counts" toward "operational stories".

## Backlog

- **Fetch the remaining ~400 pending snapshots.** Bounded at 6/min
  per default rate limit; ~70min wall-clock to drain. Safe to run
  in the background.
- **Snapshot decay.** dscan content is point-in-time. Add a TTL
  field so historical incidents stop using stale dscan as evidence.
- **Cross-reference dscan ship classes against doctrine fingerprint.**
  When a snapshot's top ships match a known hostile alliance's
  doctrine head, the cluster's quality should be lifted further.
  (Tracked under Phase 6.6 doctrine match rate.)
