#!/usr/bin/env bash
# Rolling daily-aggregate keeper for market_orders.
#
# Runs hourly. Re-aggregates yesterday + today (UTC) so that
# market_order_daily_aggregates stays current even as pollers
# add new snapshots throughout the day.
#
# Idempotent: ON DUPLICATE KEY UPDATE in the worker means each
# hour overwrites the prior hour's aggregate for the same
# (date, region, location, type, is_buy) key with fresher
# numbers.
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
TOMORROW=$(date -u -d "tomorrow" +%Y-%m-%d)

log "=== market-order-aggregator-rolling start ==="
log "window: ${YESTERDAY} -> ${TOMORROW} (exclusive)"

make market-order-aggregator-backfill START="${YESTERDAY}" END="${TOMORROW}"

log "=== market-order-aggregator-rolling done ==="
