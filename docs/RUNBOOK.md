# AegisCore Operational Runbook

When X happens, do Y. Each section names the symptom, where to
verify, and the recipe. Cross-references files + commands so an
on-call operator can act without re-reading the architecture.

If a recipe ends up being run, **append a brief outcome note** to
the section so the next operator gets the freshest signal.

---

## Quick triage

```
make ps                 # what's running
make logs               # tail every service
docker ps -a            # include exited
make sde-check          # SDE drift status
```

Surfaces:
- `/portal/intelligence/platform-health` — lane states + open quality events + recent runs
- `/portal/intelligence/trust` — feedback + suppression rates per surface
- `/portal/intelligence/alerts` — open strategic alerts

---

## Quality event detectors → recipes

Every detector defined in `python/counter_intel/phase49e_quality_guards.py`.

### `current_parser_drift` (severity warning / elevated / critical)

**Symptom:** open `eve_log_parse_errors` exceed 5 / 10 / 20 % of
24h successful events.

**Verify:**
```sql
SELECT status, COUNT(*) FROM eve_log_parse_errors
 WHERE created_at >= NOW() - INTERVAL 24 HOUR
 GROUP BY status;
```

**Recipe:**
1. Sample 5 unresolved errors:
   `SELECT id, reason, raw_line FROM eve_log_parse_errors WHERE status='open' ORDER BY id DESC LIMIT 5;`
2. Identify the new line variant the parser doesn't recognise.
3. Patch `app/app/Domains/EveLogs/Services/Parser*.php` (chat /
   gamelog / notify, depending on flavour).
4. Replay errors:
   `make eve-log-retry-parse-errors ELOG_RETRY_ARGS="--limit=10000"`
5. Re-run the guard:
   `make ci-phase49e-quality-guards`
6. If detector still fires, the patch missed a case — repeat from step 1.

### `historical_parser_backlog` (info only)

Auto-info. Triggers when retried/dismissed/reparsed_ok rows >
10× event count in 24h. Acknowledge on the platform-health page.
Never escalates platform health — it just notes a recent bulk
replay.

### `incident_explosion`

**Symptom:** 24h incidents ≥ 3× the prior 7-day daily mean.

**Verify:**
```sql
SELECT DATE(start_at) d, COUNT(*) n FROM operational_incidents
 WHERE viewer_bloc_id = 1 AND start_at >= NOW() - INTERVAL 7 DAY
 GROUP BY d ORDER BY d DESC;
```

**Recipe:**
1. Are clusters from one uploader spiking? Check
   `eve_log_files` for the most recent ~50 uploads — if a single
   uploader produced 90% of the 24h volume, isolate them.
2. Are auto-incidents from real combat? Cross-check
   `kills.killsineve.online` for the system list during the
   window.
3. Real spike → no action. Record in
   `verified_intelligence_items` as a `strategic_event`.
4. Bogus spike → hostile_clusters needs the noise filter
   tightened. Re-run `phase4-hostile-clusters` with stricter
   thresholds, or suppress via `intel_alert_suppression_rules`
   if isolated to a few systems.

### `corridor_explosion`

**Symptom:** new corridors 7d ≥ 5× prior 7d.

**Recipe:**
1. Check whether `phase4-corridors` ran with a wider
   `--since-hours` than usual — if so, expected.
2. Otherwise: the cluster character co-fingerprint is matching
   too loosely. Check `evidence_json.transit_seconds_samples` on
   recent corridors.
3. Tighten `PHASE4_CORRIDOR_TRANSITION_GAP_SEC` env var; re-run
   `phase4-corridors`.

### `doctrine_mismatch_explosion`

**Symptom:** ≥ 30 % of force_compositions over 14d have
`doctrine_match_pct < 0.30` or NULL doctrine.

**Recipe:**
1. List unmatched compositions:
   `SELECT id, ship_breakdown_json FROM operational_force_compositions WHERE doctrine_match_pct < 0.30 ORDER BY snapshot_at DESC LIMIT 10;`
2. Look at top-3 hulls. If a recognised meta exists, run
   `php artisan battle:compute-auto-doctrines` to refresh
   `auto_doctrines`.
3. If genuine off-meta (smallgang / new doctrine), no action —
   doctrine library will catch up next compute.

### `impossible_fleet_size`

**Symptom:** any composition reports > 2500 ships in 48h.

**Recipe:**
1. Pull the offending row:
   `SELECT * FROM operational_force_compositions WHERE ship_total > 2500 ORDER BY id DESC LIMIT 5;`
2. Examine `ship_breakdown_json`. If it's clearly duplicated
   (same hull repeated 100s of times), parser regression in
   `EveLogFetchDscanCommand::parseShipsFromHtml`.
3. Manually delete the bogus rows, patch the parser, re-run
   `phase45-force-compositions --since-hours=72`.

### `duplicate_narrative_loop`

**Symptom:** ≥ 10 narratives share identical body in 24h.

**Recipe:**
1. Find the offending body:
   `SELECT MD5(narrative_md), COUNT(*) FROM incident_narratives WHERE computed_at >= NOW() - INTERVAL 24 HOUR GROUP BY MD5(narrative_md) HAVING COUNT(*) >= 10;`
2. Inspect a sample narrative. If it's literally the
   "no clusters" template fall-through, the upstream incidents
   are missing data — investigate
   `operational_incidents.timeline_summary` on those rows.
3. If the renderer itself is looping, patch
   `phase4_workflow.py:_render_narrative` and re-run
   `phase47-incident-narratives`.

### `stale_compute_chain`

**Symptom:** flagship pipeline last run > 36h ago.

**Recipe:**
1. Identify the pipeline name from the event.
2. Run it manually:
   `make ci-<pipeline>` (e.g. `make ci-phase47-daily-digest`).
3. If it errors, investigate the underlying compute. If it
   succeeds, the stale event auto-resolves on next
   `make ci-phase49e-quality-guards`.
4. If the pipeline is supposed to run on cron, verify the
   host crontab; install via the ops cron list at the bottom
   of this doc.

### `neo4j_thread_pressure`

**Symptom:** ≥ 80 % of Bolt thread slots held by long-running
(>5min) graph/operational pipelines.

**Configured cap:** `server.bolt.thread_pool_max_size = 16`
(Neo4j config). 13+ long-running jobs = warning; 16+ = critical.

**Recipe:**
1. Identify the long-running rows:
   `SELECT pipeline, viewer_bloc_id, compute_started_at FROM compute_run_log WHERE status='running' AND compute_started_at <= NOW() - INTERVAL 5 MINUTE AND lane IN ('graph','operational') ORDER BY compute_started_at;`
2. For each row: is the underlying container still active?
   `docker ps --filter "name=<pipeline_prefix>"`
3. If the container exited but compute_run_log row was never
   updated, mark as failed manually:
   `UPDATE compute_run_log SET status='aborted', compute_finished_at=NOW(), error_message='manual_close' WHERE id=...;`
4. If the container is still running but stuck (no log progress),
   force-rm. See "Neo4j thread starvation" below for the full
   recipe.

**Prevention:** flock on host cron (battle-process-pending);
orphan reaper at script start; serial pipeline within an
invocation; do not run `make ci-phase4-projection` /
`make ci-phase4-similarity` in parallel — they're heavy graph
projections.

### `unknown_event_spike`

**Symptom:** event_type='unknown' > 8 % of events 24h.

**Recipe:**
1. Sample unknowns:
   `SELECT id, raw_line FROM eve_log_events WHERE event_type='unknown' AND event_timestamp >= NOW() - INTERVAL 1 HOUR LIMIT 20;`
2. Identify the new line variant. Same fix path as
   `current_parser_drift`.

---

## Lane state recipes

### `not_instrumented`

**Symptom:** `compute_lane_metrics.lane_state = 'not_instrumented'`.

**Cause:** No `compute_run_log` rows for this lane ever.

**Recipe:**
- Expected for `ingest`, `parser`, `graph` until those lanes'
  CLI handlers grow `with ComputeLog(...)` wrappers (Phase 4.9B
  did the second pass; non-counter_intel pipelines are still
  uninstrumented).
- For unexpected `not_instrumented`: check that the operator
  actually ran a make target that hits the lane.

### `failed`

**Symptom:** at least one `compute_run_log` row in the last 24h
with status='failed', and 0 successes.

**Recipe:**
1. Inspect:
   `SELECT pipeline, error_message, compute_started_at FROM compute_run_log WHERE lane='X' AND status='failed' ORDER BY id DESC LIMIT 5;`
2. Most failures: connectivity (mariadb / Neo4j drop). Restart
   the dependency, re-run the pipeline.
3. If repeating: the stats_json captured the error context;
   start there.

### `starved`

**Symptom:** oldest running job in the lane is > 1 hour old.

**Recipe:**
- Specifically about the `graph` lane: see the Neo4j thread
  starvation recipe below.
- For other lanes: kill the stuck container, identify why it
  stalled (deadlock on a row, FK conflict, stuck HTTP request),
  re-run.

### `backlogged`

**Symptom:** ≥ 4 concurrent running jobs.

**Recipe:**
- Whitelist the case: are these intentional parallel runs from
  battle-process-pending? Cap concurrency in
  `scripts/battle-process-pending.sh` (BATTLE_LIMIT env var).
- Otherwise: someone fired off a make-target loop. Identify
  the parent process and stop it.

### `degraded`

**Symptom:** failed/(succ+failed) ≥ 0.20 over 24h.

**Recipe:**
- Investigate the most-failing pipeline:
  `SELECT pipeline, COUNT(*) FROM compute_run_log WHERE status='failed' AND compute_started_at >= NOW() - INTERVAL 24 HOUR GROUP BY pipeline ORDER BY 2 DESC;`
- Patch the underlying issue, re-run.

---

## Known incidents + recipes

### Neo4j thread starvation ("51N38: insufficient threads")

**Symptom:** Neo4j returns `Neo.TransientError.General.OutOfMemoryError`
or `51N38: There are insufficient threads available for executing the current task.`

**Cause:** too many concurrent graph projections running. Most
common path: `battle-process-pending` fires multiple cron ticks
overlapping (no flock), each spawning a battle_graph + theater_clustering
+ neo4j-sync container, all hitting the same Neo4j thread pool.

**Recipe:**
1. List orphan battle_graph runs:
   `docker ps --filter "name=battle_graph-run" --format '{{.Names}} {{.Status}}'`
2. If oldest is more than 30 min old, force-rm them:
   `docker ps --filter "name=battle_graph-run" -q | xargs -r docker rm -f`
3. Verify Neo4j responsive:
   `docker exec b6a8defb08c5_neo4j cypher-shell -u neo4j -p $NEO4J_PASSWORD --format plain "RETURN 1"`
4. Confirm script flock guard in
   `scripts/battle-process-pending.sh` is active (added 2026-04-26).

**Prevention:** the script's flock guard blocks overlapping
ticks. The orphan reaper at script start force-rms any
battle_graph container older than `BATTLE_ORPHAN_TTL_MIN`
(default 30 min). Don't bypass either guard without a reason.

**Past incident:** 2026-04-26 — 16 stuck containers from
overlapping cron ticks. All threads held. Root cause: no flock
on host cron, no orphan reaper.

### outbox_relay exited on mariadb drop

**Symptom:** `outbox_relay` container in Exited state, last log
shows "Lost connection to MySQL server during query" /
"Can't connect to MySQL server".

**Cause:** mariadb restarted (config change, OOM, manual
restart) and the relay's retry loop hit `tries=1` exhaustion.

**Recipe:**
```
docker compose -f infra/docker-compose.yml --env-file .env up -d outbox_relay
```

Verify within 10 seconds:
```
docker logs outbox_relay --tail=5
```
Should show `connected to mariadb (outbox)` and
`connected to influxdb`.

**Prevention follow-up:** raise relay's reconnect tolerance.
v1 deferred — operator will notice via outbox depth growth.

### SDE import: FK constraint failure on DELETE

**Symptom:** `make sde-import` fails with
`Cannot delete or update a parent row: a foreign key constraint fails`.

**Cause:** Phase 4+ intel tables added FKs into ref_*.
The importer's wipe-and-reload strategy fails when child rows
exist.

**Recipe:** patched 2026-04-27 — loader now wraps DELETE+INSERT
with `SET FOREIGN_KEY_CHECKS=0/1`. Just re-run:
```
make sde-import
```

**Caveat:** if a future SDE bump REMOVES a type that intel
rows reference, the FK row is left dangling. Monitor surfaces
post-bump for missing-item-type rendering.

### Backup file at 01:1 GB instead of 23 GB

**Symptom:** a `backups/mariadb/aegiscore_*.sql.gz` file is
much smaller than its peers (e.g. 1 GB vs 23 GB).

**Cause:** mariadb-dump was killed mid-stream (host reboot,
disk full, OOM).

**Recipe:**
1. Inspect the cron log:
   `tail -50 /opt/AegisCore/backups/mariadb/cron.log`
2. Confirm mariadb is healthy now: `make ps | grep mariadb`
3. Re-run the backup manually: `make backup`
4. Once the new file is full size, drop the partial:
   `rm /opt/AegisCore/backups/mariadb/aegiscore_<partial_ts>.sql.gz`

### MariaDB InnoDB config change

**NEVER `docker compose restart mariadb`** after changing
buffer_pool / log_file_size / flush_method. Use:

```
make safe-restart-mariadb
```

This stops writers, takes a backup, performs a clean InnoDB
shutdown, restarts, verifies. Past incident (2026-04-16) lost
7.7M killmails when this was bypassed.

---

## SDE bump

When `make sde-check` reports `bump=YES`:

1. Latest backup must be < 24h:
   `ls -la backups/mariadb/ | tail`
2. `make sde-auto-update` (or with overrides — see
   `scripts/sde-auto-update.sh` header)
3. Watch for FK guard kick-in: log line should contain
   `SET FOREIGN_KEY_CHECKS = 0` followed by `ref_* tables cleared`
4. Post-import, check intel surfaces render new ship types:
   - Visit `/portal/intelligence/search` and search a recently-added
     ship name (CCP patch notes will list new types).
   - If missing: a downstream dependency on the SDE didn't pick
     up the change. Most common culprit:
     `app/config/aegiscore.php` cached config — `php artisan config:clear`.

---

## Retry / circuit breaker

`python/counter_intel/phase49d_retry.py` ships shared retry +
circuit-breaker primitives. Pipelines can opt into retries by
wrapping their compute call in `retry(fn, policy, conn=,
lane=, pipeline=, run_log=)`.

### Normal retry behavior

Background retries are **expected** in these cases — operators
do not need to act:

- **transient** (~ a few per day): mariadb / Neo4j connection
  blip during a deploy, network hiccup. Single attempt usually
  succeeds on retry.
- **contention** (rare): a parallel sweep + recompute hit the
  same row's lock window. The 1-second back-off resolves it.
- **rate_limit** (dscan): ESI 429 / dscan rate-limit response.
  Honored with bounded back-off.

### When retries indicate degradation

If `compute_lane_metrics` shows retry counts that exceed
**~10% of the lane's runs in 24h**, investigate:

1. Identify the noisy pipeline:
   `SELECT pipeline, COUNT(*) AS runs, SUM(retry_count) AS total_retries, SUM(retry_count > 0) AS retried_runs FROM compute_run_log WHERE compute_started_at >= NOW() - INTERVAL 24 HOUR GROUP BY pipeline ORDER BY total_retries DESC;`
2. Correlate the retry_reason distribution:
   `SELECT pipeline, retry_reason, COUNT(*) FROM compute_run_log WHERE retry_count > 0 AND compute_started_at >= NOW() - INTERVAL 24 HOUR GROUP BY pipeline, retry_reason ORDER BY 3 DESC;`
3. **transient** dominant → check the dependency that's flaking
   (mariadb load, Neo4j thread pressure, network).
4. **contention** dominant → check `SHOW ENGINE INNODB STATUS`
   for lock waits; consider whether two pipelines run in parallel
   that shouldn't.
5. **rate_limit** dominant → upstream (CCP / dscan.info) is
   throttling. Reduce dscan fetch rate via env var, retry will
   re-stabilise.
6. **permanent** / **malformed_input** appearing in retry_reason
   counts at all means the classifier needs a fix — those
   classes have budget=0 and shouldn't be retried.

### Circuit breaker — when operators should intervene

Configured: 5 consecutive failures within 10 minutes opens the
circuit for 5 minutes (×2 cooldown on re-open, capped at 30 min).

**Open circuit** = `compute_circuit_state.state = 'open'`. The
detector emits a `circuit_open` quality event at severity
`elevated`. Surfaced on `/portal/intelligence/platform-health`
in the **⚡ Open circuits** panel.

**Recipe when a circuit opens:**

1. Read the open-circuits panel. Note `lane`, `pipeline`,
   `last_failure_reason`.
2. Pull the last 5 failed runs of that pipeline:
   `SELECT id, compute_started_at, error_message, retry_count, retry_reason FROM compute_run_log WHERE pipeline=? AND status='failed' ORDER BY id DESC LIMIT 5;`
3. Three patterns:
   - All `transient`: dependency outage. Wait for cooldown,
     restart dependency, the next half-open attempt validates.
   - All `contention`: parallel pipeline collision. Check the
     host cron — fix the schedule so the collisions don't
     happen, then close the circuit manually:
     `UPDATE compute_circuit_state SET state='closed', consecutive_failures=0 WHERE lane=? AND pipeline=?;`
   - All `permanent`: code bug. Patch the underlying compute,
     the circuit will close on the next successful manual run.
4. **Manual circuit close** is safe but should only happen after
   you've fixed the root cause. Use:
   `UPDATE compute_circuit_state SET state='closed', consecutive_failures=0, window_failures=0, opened_at=NULL, cooldown_until=NULL WHERE lane=? AND pipeline=?;`
   Then re-run the pipeline manually to confirm.

### Suppress retry storms

If a single pipeline is producing retry noise (e.g. a downstream
service known to be flaky for the next hour), you can preempt
the circuit by setting cooldown manually:

```sql
INSERT INTO compute_circuit_state
  (lane, pipeline, state, opened_at, cooldown_until)
VALUES
  ('graph', 'phase4-projection', 'open',
   NOW(), NOW() + INTERVAL 1 HOUR)
ON DUPLICATE KEY UPDATE
  state='open', cooldown_until=NOW() + INTERVAL 1 HOUR;
```

The pipeline will skip with `CircuitOpenError` (logged as
`status='aborted'`) until the cooldown elapses.

---

## Retention

`make ci-phase49c-retention CI_ARGS="--dry-run"` first to
preview. Then `make ci-phase49c-retention` to sweep. Full TTL
ladder in `docs/RETENTION.md`.

If a sweep deletes rows that turn out to be needed, restore from
the latest backup and surgically pull only the rows you need —
see RETENTION.md § Restore.

---

## Recommended host cron lines

Operators should install these on the host via
`crontab -e`. They're NOT in the scheduler container because
they need the docker socket.

```
# AegisCore daily ops
30 8 * * *  /opt/AegisCore/scripts/sde-auto-update.sh        >> /opt/AegisCore/scripts/log/sde-auto-update.log    2>&1
*/5 * * * * cd /opt/AegisCore && make battle-process-pending >> /var/log/aegiscore-battle-pipeline.log           2>&1
15 4 * * *  cd /opt/AegisCore && make ci-phase49c-retention  >> /opt/AegisCore/scripts/log/retention.log         2>&1
0 */6 * * * cd /opt/AegisCore && make ci-phase49a-lane-metrics >> /opt/AegisCore/scripts/log/lane-metrics.log    2>&1
30 */6 * * * cd /opt/AegisCore && make ci-phase49e-quality-guards >> /opt/AegisCore/scripts/log/quality-guards.log 2>&1
45 */6 * * * cd /opt/AegisCore && make ci-phase49-freshness    >> /opt/AegisCore/scripts/log/freshness.log         2>&1
```

Each line is independently safe (file-locked or idempotent)
per Phase 4.9 design.

---

## Escalation paths

- **Anything destructive** (DROP TABLE, force-push to main,
  bypass safe-restart-mariadb): stop and ask before doing.
- **Quality event severity = critical, not in this runbook**:
  open a verified_intelligence_item with item_kind='analyst_note'
  capturing the symptom + your investigation steps. Future
  operators get the breadcrumb.
- **Suspected platform compromise**: revoke EVE SSO tokens
  (`/portal/account-settings`), rotate uploader API tokens
  (`/portal/uploaders`), audit `intel_feedback_events`
  + `verified_intelligence_items` for inserts attributed to
  compromised users.

---

## Adding to this runbook

When you handle an incident not yet in the runbook, write the
recipe before you forget. Format: symptom → verify → recipe →
prevention. Keep entries tight; long entries get skipped under
pressure.
