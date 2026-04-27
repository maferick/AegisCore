#!/usr/bin/env bash
# Daily partition rotation for market_orders.
#
# Maintains a rolling 72-hour HOT retention by:
#   1. dropping any partition whose date is older than today - 3 days
#   2. ensuring partitions exist for [today, today+90 days]
#
# DROP PARTITION on InnoDB is metadata-only (no row scan, no undo).
# Total runtime expected < 5 seconds.
#
# Cron (host-side; needs docker socket):
#   30 3 * * * /opt/AegisCore/scripts/market-orders-rotate.sh \
#     >> /opt/AegisCore/scripts/log/market-orders-rotate.log 2>&1
#
# Override env vars:
#   AEGIS_MARKET_RETENTION_DAYS=3   keep this many days hot (default 3)
#   AEGIS_MARKET_FUTURE_DAYS=90     pre-create this many days ahead
#   AEGIS_MARKET_DRY_RUN=1          report what would happen, no DDL
#
# Safety:
#   flock-guarded; never deletes the current day; never deletes
#   anything within retention window.

set -euo pipefail

REPO_ROOT="${REPO_ROOT:-/opt/AegisCore}"
LOG_DIR="${REPO_ROOT}/scripts/log"
LOCK_FILE="${LOG_DIR}/market-orders-rotate.lock"
RETENTION_DAYS="${AEGIS_MARKET_RETENTION_DAYS:-3}"
FUTURE_DAYS="${AEGIS_MARKET_FUTURE_DAYS:-90}"
DRY_RUN="${AEGIS_MARKET_DRY_RUN:-0}"

mkdir -p "$LOG_DIR"

ts() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }
log() { echo "[$(ts)] $*"; }

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "previous market-orders-rotate still running; exiting."
    exit 0
fi

log "=== market-orders-rotate start (retention=${RETENTION_DAYS}d, future=${FUTURE_DAYS}d, dry_run=${DRY_RUN}) ==="

mariadb_exec() {
    docker exec mariadb mariadb -uaegiscore -paegiscore aegiscore "$@"
}

# Today (UTC) + cutoff
TODAY=$(date -u +%Y-%m-%d)
CUTOFF=$(date -u -d "${TODAY} - ${RETENTION_DAYS} days" +%Y%m%d)

# Step 1: list existing partitions older than cutoff (excluding p_future)
OLD_PARTS=$(mariadb_exec --skip-column-names -B -e "
  SELECT partition_name FROM information_schema.partitions
   WHERE table_schema='aegiscore' AND table_name='market_orders'
     AND partition_name IS NOT NULL
     AND partition_name <> 'p_future'
     AND partition_name < 'p${CUTOFF}'
   ORDER BY partition_name
" 2>/dev/null)

if [[ -n "$OLD_PARTS" ]]; then
    log "drop candidates (older than p${CUTOFF}):"
    echo "$OLD_PARTS" | while read p; do log "  ${p}"; done

    if [[ "$DRY_RUN" == "1" ]]; then
        log "dry-run: skipping DROP PARTITION"
    else
        # Concatenate into single ALTER TABLE for atomicity
        DROP_LIST=$(echo "$OLD_PARTS" | paste -sd, -)
        log "dropping partitions: ${DROP_LIST}"
        mariadb_exec -e "ALTER TABLE market_orders DROP PARTITION ${DROP_LIST}"
        log "drop complete"
    fi
else
    log "no partitions older than p${CUTOFF}"
fi

# Step 2: ensure future partitions exist for today + N days
NEED_PARTS=()
for i in $(seq 0 ${FUTURE_DAYS}); do
    pday=$(date -u -d "${TODAY} + ${i} days" +%Y%m%d)
    pname="p${pday}"
    exists=$(mariadb_exec --skip-column-names -B -e "
      SELECT COUNT(*) FROM information_schema.partitions
       WHERE table_schema='aegiscore' AND table_name='market_orders'
         AND partition_name='${pname}'
    " 2>/dev/null)
    if [[ "$exists" == "0" ]]; then
        nxt=$(date -u -d "${TODAY} + $((i+1)) days" +%Y-%m-%d)
        NEED_PARTS+=("PARTITION ${pname} VALUES LESS THAN ('${nxt}')")
    fi
done

if [[ ${#NEED_PARTS[@]} -gt 0 ]]; then
    log "creating ${#NEED_PARTS[@]} new future partitions"
    if [[ "$DRY_RUN" == "1" ]]; then
        log "dry-run: skipping ALTER TABLE REORGANIZE"
    else
        # REORGANIZE p_future into [new days...] + new p_future
        NEW_LIST=$(IFS=,; echo "${NEED_PARTS[*]}")
        TOMORROW=$(date -u -d "${TODAY} + $((FUTURE_DAYS+1)) days" +%Y-%m-%d)
        mariadb_exec -e "ALTER TABLE market_orders REORGANIZE PARTITION p_future INTO (
            ${NEW_LIST},
            PARTITION p_future VALUES LESS THAN (MAXVALUE)
        )"
        log "create complete"
    fi
fi

log "=== market-orders-rotate done ==="
