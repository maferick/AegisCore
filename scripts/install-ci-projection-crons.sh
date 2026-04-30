#!/usr/bin/env bash
# Counter-Intel staleness prevention — append daily refresh of
# ci_character_features_rolling + Neo4j projection to the operator
# host crontab. Run once on the host.
#
# Without these, phase18 fusion + character-lookup graph insights
# read a frozen window: defector / recruit transitions (e.g.
# Dracarys → Insidious) lag in the surface for weeks.
#
# Idempotent: re-running re-appends only if missing.

set -euo pipefail

LOG_DIR="/opt/AegisCore/scripts/log"
mkdir -p "$LOG_DIR"

CRON_LINE_FEATURES='35 4 * * * cd /opt/AegisCore && CI_ARGS="--window-end $(date -u +\%Y-\%m-\%d)" make ci-features >> /opt/AegisCore/scripts/log/ci-features.log 2>&1'
CRON_LINE_PROJECTION='55 4 * * * cd /opt/AegisCore && make ci-projection >> /opt/AegisCore/scripts/log/ci-projection.log 2>&1'

CURRENT="$(crontab -l 2>/dev/null || true)"

add_if_missing() {
    local line="$1"
    if printf '%s\n' "$CURRENT" | grep -Fq "$line"; then
        echo "already present: $line"
        return
    fi
    CURRENT="${CURRENT}"$'\n'"${line}"
    echo "appending: $line"
}

add_if_missing "$CRON_LINE_FEATURES"
add_if_missing "$CRON_LINE_PROJECTION"

printf '%s\n' "$CURRENT" | crontab -
echo "done. current cron tail:"
crontab -l | tail -8
