#!/usr/bin/env bash
# Rolling daily-aggregate keeper for market_orders.
#
# Runs hourly. Re-aggregates yesterday (UTC) only.
#
# Why D-1, not D-0: the aggregator does an INSERT…SELECT…GROUP BY
# scan over the partition. Pollers writing concurrently to today's
# partition collide with that scan and InnoDB raises error 1020
# ("Record has changed since last read"). Yesterday's partition
# is closed for writes (pollers only target today), so the scan
# is race-free. Today's aggregate lands at the next 00:17 UTC tick.
#
# Idempotent: ON DUPLICATE KEY UPDATE in the worker means each
# hour overwrites the prior aggregate for the same
# (date, region, location, type, is_buy) key with fresher numbers
# — useful for late-arriving rows still being inserted into the
# previous-day partition right after UTC midnight rollover.
#
# flock-guarded so a slow run doesn't stack onto the next tick.
#
# Cron entry (already installed 2026-04-27):
#   17 * * * * /opt/AegisCore/scripts/market-order-aggregator-rolling.sh \
#     >> /opt/AegisCore/scripts/log/market-order-aggregator.log 2>&1

set -euo pipefail

REPO_ROOT="${REPO_ROOT:-/opt/AegisCore}"
LOG_DIR="${REPO_ROOT}/scripts/log"
LOCK_FILE="${LOG_DIR}/market-order-aggregator.lock"

mkdir -p "$LOG_DIR"
cd "$REPO_ROOT"

ts() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }
log() { echo "[$(ts)] $*"; }

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "previous market-order-aggregator still running; exiting."
    exit 0
fi

YESTERDAY=$(date -u -d "yesterday" +%Y-%m-%d)
TODAY=$(date -u +%Y-%m-%d)

log "=== market-order-aggregator-rolling start ==="
log "window: ${YESTERDAY} -> ${TODAY} (exclusive; today excluded to avoid InnoDB error 1020 against active poller writes)"

make market-order-aggregator-backfill START="${YESTERDAY}" END="${TODAY}"

log "=== market-order-aggregator-rolling done ==="
