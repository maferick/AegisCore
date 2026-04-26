#!/usr/bin/env bash
# AegisCore — SDE auto-update
#
# Designed for host cron (NOT the scheduler container, which lacks
# docker socket). Pipeline:
#
#   1. make sde-check           → refreshes sde_version_checks row
#   2. read latest row          → checks is_bump_available
#   3. if bump + safe-window    → make sde-import
#   4. on success               → make neo4j-sync-universe
#   5. logs to scripts/log/sde-auto-update.log
#
# Safety:
#   - Skips if a previous run is still active (advisory file lock).
#   - Skips if last successful import was within MIN_IMPORT_INTERVAL_HOURS.
#   - Aborts on any non-zero exit from make targets — does NOT roll
#     back. CCP SDE bumps are additive in practice; partial loads
#     leave the platform in a recoverable state.
#
# Recommended cron:
#   30 8 * * *  /opt/AegisCore/scripts/sde-auto-update.sh >> /opt/AegisCore/scripts/log/sde-auto-update.log 2>&1
#
# Override behaviour with env vars:
#   AEGIS_SDE_AUTO_IMPORT=0     dry-run; check + report only, never import
#   AEGIS_SDE_FORCE=1           ignore MIN_IMPORT_INTERVAL_HOURS
#   AEGIS_SDE_SKIP_NEO4J=1      skip neo4j-sync-universe step
#   MIN_IMPORT_INTERVAL_HOURS   default 23 (one calendar day minus drift)

set -euo pipefail

REPO_ROOT="${REPO_ROOT:-/opt/AegisCore}"
LOG_DIR="${REPO_ROOT}/scripts/log"
LOCK_FILE="${LOG_DIR}/sde-auto-update.lock"
MIN_IMPORT_INTERVAL_HOURS="${MIN_IMPORT_INTERVAL_HOURS:-23}"

mkdir -p "$LOG_DIR"
cd "$REPO_ROOT"

ts() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }

log() { echo "[$(ts)] $*"; }

# Single-instance guard.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "previous sde-auto-update still running; exiting."
    exit 0
fi

log "=== sde-auto-update start ==="

# Step 1: refresh the version-check row. Uses --sync so the result lands
# synchronously instead of being queued.
log "running make sde-check"
make sde-check

# Step 2: read the latest sde_version_check row.
read_latest_check() {
    docker exec mariadb mariadb -uaegiscore -paegiscore aegiscore --skip-column-names -B -e \
"SELECT is_bump_available, pinned_version, upstream_version,
        UNIX_TIMESTAMP(checked_at) AS checked_ts
   FROM sde_version_checks
  ORDER BY id DESC
  LIMIT 1"
}

LATEST="$(read_latest_check)"
if [[ -z "$LATEST" ]]; then
    log "no sde_version_check row found; nothing to do."
    exit 0
fi

read -r BUMP PINNED UPSTREAM CHECKED_TS <<<"$LATEST"

log "pinned=${PINNED}  upstream=${UPSTREAM}  bump=${BUMP}  checked_ts=${CHECKED_TS}"

if [[ "$BUMP" != "1" ]]; then
    log "no bump available — exiting clean."
    exit 0
fi

# Step 3: gate on AEGIS_SDE_AUTO_IMPORT.
if [[ "${AEGIS_SDE_AUTO_IMPORT:-1}" == "0" ]]; then
    log "AEGIS_SDE_AUTO_IMPORT=0 — bump available but auto-import disabled. Run 'make sde-import' manually."
    exit 0
fi

# Step 4: throttle on previous successful import.
LAST_IMPORT_VER_FILE="${REPO_ROOT}/infra/sde/version.txt"
if [[ -r "$LAST_IMPORT_VER_FILE" ]]; then
    LAST_IMPORT_TS=$(stat -c %Y "$LAST_IMPORT_VER_FILE" 2>/dev/null || echo 0)
    NOW_TS=$(date +%s)
    HOURS_SINCE=$(( (NOW_TS - LAST_IMPORT_TS) / 3600 ))
    if [[ "${AEGIS_SDE_FORCE:-0}" != "1" && "$HOURS_SINCE" -lt "$MIN_IMPORT_INTERVAL_HOURS" ]]; then
        log "last import was ${HOURS_SINCE}h ago (< ${MIN_IMPORT_INTERVAL_HOURS}h floor); skipping. Pass AEGIS_SDE_FORCE=1 to override."
        exit 0
    fi
fi

# Step 5: run the import. Failure here aborts the script — exit non-zero
# so the cron operator gets a mail (or the upstream observer sees the
# stderr redirected log line).
log "bump detected — running make sde-import"
make sde-import

log "sde-import succeeded"

# Step 6: refresh neo4j-projected universe topology so map renderers
# pick up new systems / stargates.
if [[ "${AEGIS_SDE_SKIP_NEO4J:-0}" != "1" ]]; then
    log "running make neo4j-sync-universe"
    make neo4j-sync-universe || log "warning: neo4j-sync-universe failed (non-fatal)"
fi

log "=== sde-auto-update done ==="
