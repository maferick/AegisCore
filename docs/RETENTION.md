# Retention policy

V1 ladder. Single source of truth in
`python/counter_intel/phase49c_retention.RETENTION`. Operators
should treat this doc as advisory; the Python tuple is binding.

## Run

Preview (recommended first time):

```
make ci-phase49c-retention CI_ARGS="--dry-run"
```

Sweep:

```
make ci-phase49c-retention
```

Each invocation writes a `compute_run_log` row in lane=maintenance
pipeline=phase49c-retention so the platform-health dashboard
shows the run + duration + rows deleted.

## Cron (host)

Pre-approved cadence — apply when v1 §8 gate is being closed:

```
15 4 * * *  cd /opt/AegisCore && make ci-phase49c-retention >> scripts/log/retention.log 2>&1
```

Reasoning: 04:15 UTC. After 04:00 standings sync + 03:30 hub
catchments, before 05:00 classification sweep. Off-peak for EU
+ US.

## TTL ladder

Sweep order (child → parent):

| table                              | ts column           | days | predicate                                     |
|------------------------------------|---------------------|------|-----------------------------------------------|
| outbox                             | processed_at        | 7    | processed_at IS NOT NULL                      |
| compute_run_log                    | compute_started_at  | 30   | all                                           |
| system_quality_events              | resolved_at         | 90   | resolved_at IS NOT NULL                       |
| intel_export_artifacts             | expires_at          | 0    | expires_at < NOW()                            |
| intel_feedback_events              | created_at          | 180  | all                                           |
| system_trust_metrics               | computed_at         | 90   | all                                           |
| operational_force_transitions      | computed_at         | 365  | all                                           |
| operational_force_compositions     | computed_at         | 365  | all                                           |
| operational_incidents              | start_at            | 365  | all                                           |
| operational_hostile_clusters       | start_at            | 365  | all                                           |
| operational_corridors              | last_seen_at        | 365  | all                                           |
| system_operational_activity        | activity_date       | 180  | all                                           |
| daily_operational_digest           | digest_date         | 90   | all                                           |
| incident_narratives                | computed_at         | 365  | all                                           |
| strategic_alerts                   | detected_at         | 180  | archived OR false_positive OR dismissed       |
| doctrine_evolution_events          | computed_at         | 365  | all                                           |
| verified_intelligence_items        | created_at          | 365  | pinned = 0                                    |
| eve_log_parse_errors               | updated_at          | 30   | status IN (retried, dismissed, reparsed_ok)   |
| eve_log_dscan_snapshots            | last_seen_at        | 60   | fetch_status = 'success'                      |
| eve_log_dscan_snapshots            | last_seen_at        | 7    | fetch_status != 'success'                     |
| eve_log_events                     | event_timestamp     | 90   | all                                           |
| eve_log_entity_resolutions         | created_at          | 90   | all                                           |

Notes:
- **Open quality_events**, **open / suppressed / acknowledged
  alerts**, and **open parse_errors** never auto-delete. Operators
  resolve / dismiss / archive them first.
- **Pinned verified_intelligence_items** never auto-delete.
- **operational_incidents linked to a battle_theater** are kept
  even past 365d via FK constraint — the parent battle holds the
  long-form record, so dropping the incident row breaks the
  forward link from battle dossiers.
  *(Today the FK is informational only. v1 closure check: confirm
  the 365d cutoff doesn't actually orphan any battles.)*

## Sizes (2026-04-27)

Pre-sweep snapshot for impact estimate:

| table                          | rows    | bytes      |
|--------------------------------|---------|------------|
| eve_log_events                 | 215,939 | 125 MB     |
| eve_log_parse_errors           | 115,218 | 41 MB      |
| operational_incidents          | 9,625   | 6.5 MB     |
| operational_hostile_clusters   | 11,138  | 4.8 MB     |
| operational_force_compositions | 42      | 0.4 MB     |
| eve_log_dscan_snapshots        | 432     | 0.1 MB     |

Total candidate footprint <175 MB. First sweep should reclaim
nothing material because the platform is < 90 days old; sweep
becomes meaningful once `eve_log_events` crosses the 90d mark.

## Restore

If a sweep drops rows that turn out to be needed, restore from
`backups/mariadb/aegiscore_<timestamp>.sql.gz` via:

```
gunzip -c backups/mariadb/aegiscore_<ts>.sql.gz | docker exec -i mariadb mariadb -uaegiscore -paegiscore aegiscore_restore
```

Then `INSERT INTO aegiscore.<table> SELECT * FROM aegiscore_restore.<table> WHERE ...` to surgically pull back only the rows you need.

## v1 §8 gate

Section closes when:

1. `make ci-phase49c-retention CI_ARGS="--dry-run"` returns sane
   counts on a populated production-equivalent dataset.
2. First live sweep ran successfully across all TTL'd tables.
3. Dashboard surfaces show no rows older than the configured window.
4. Host cron line installed and observed firing once daily for
   ≥ 7 days without flock contention.
