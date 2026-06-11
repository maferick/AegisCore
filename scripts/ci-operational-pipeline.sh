#!/usr/bin/env bash
# Counter-Intel operational pipeline (Phase 4 → 4.5 → 4.6 → 4.7).
#
# Chains every operational compute pass in dependency order so the
# downstream surfaces (digest, alerts, narratives, profiles) actually
# advance every day. Without this, the individual `make ci-phase4-*`
# targets sit unscheduled and tables freeze — exactly the bug found
# 2026-05-01 (operational_incidents 5d stale, operational_force_
# compositions 45d stale).
#
# Companion to scripts/ci-daily-pipeline.sh. The character/feature
# pipeline runs at 05:00; this runs at 06:00 so character features
# from today's pipeline are available to operational consumers.
#
# Failures in one step do not abort the chain; each step logs FAIL
# and the script continues. coverage check (cron, hourly) catches
# any output that goes stale.

set -uo pipefail
cd /opt/AegisCore

LOG_DIR=/opt/AegisCore/scripts/log
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/ci-operational-pipeline.log"
WIN=$(date -u +%Y-%m-%d)
VIEWER_BLOC=${VIEWER_BLOC:-1}

run() {
    local label="$1"; shift
    echo "=== $(date -u +%FT%TZ) [$label] $* ===" >> "$LOG"
    if "$@" >> "$LOG" 2>&1; then
        echo "    OK $(date -u +%FT%TZ) [$label]" >> "$LOG"
    else
        local rc=$?
        echo "    FAIL rc=$rc $(date -u +%FT%TZ) [$label]" >> "$LOG"
    fi
}

echo "###### ci-operational-pipeline start $(date -u +%FT%TZ) bloc=$VIEWER_BLOC ######" >> "$LOG"

# ---- Phase 4 base layer (eve-log + killmail derived) ----------------
run phase4-timelines             make ci-phase4-timelines             VIEWER_BLOC=$VIEWER_BLOC
run phase4-fleet-participation   make ci-phase4-fleet-participation   VIEWER_BLOC=$VIEWER_BLOC
run phase4-intel-reliability     make ci-phase4-intel-reliability     VIEWER_BLOC=$VIEWER_BLOC
run phase4-session-correlation   make ci-phase4-session-correlation   VIEWER_BLOC=$VIEWER_BLOC

# ---- Phase 4 aggregation (operational_* tables) ---------------------
run phase4-hostile-clusters      make ci-phase4-hostile-clusters      VIEWER_BLOC=$VIEWER_BLOC
run phase4-incidents             make ci-phase4-incidents             VIEWER_BLOC=$VIEWER_BLOC
run phase4-system-activity       make ci-phase4-system-activity       VIEWER_BLOC=$VIEWER_BLOC
run phase4-corridors             make ci-phase4-corridors             VIEWER_BLOC=$VIEWER_BLOC
run phase4-response-times        make ci-phase4-response-times        VIEWER_BLOC=$VIEWER_BLOC
run phase4-threat-surface        make ci-phase4-threat-surface        VIEWER_BLOC=$VIEWER_BLOC

# ---- Phase 4.5 — force composition + transitions --------------------
run phase45-force-compositions   make ci-phase45-force-compositions   VIEWER_BLOC=$VIEWER_BLOC
run phase45-force-transitions    make ci-phase45-force-transitions    VIEWER_BLOC=$VIEWER_BLOC

# ---- Phase 4.6 — coalition + alliance profiles ----------------------
run phase46-alliance-profiles    make ci-phase46-alliance-profiles    VIEWER_BLOC=$VIEWER_BLOC
run phase46-coalition-comparisons make ci-phase46-coalition-comparisons VIEWER_BLOC=$VIEWER_BLOC
run phase46-doctrine-evolution   make ci-phase46-doctrine-evolution   VIEWER_BLOC=$VIEWER_BLOC
run phase46-route-pressure       make ci-phase46-route-pressure       VIEWER_BLOC=$VIEWER_BLOC
run phase46-operator-fingerprints make ci-phase46-operator-fingerprints VIEWER_BLOC=$VIEWER_BLOC

# ---- Phase 4.7 — workflow surfaces ----------------------------------
run phase47-strategic-alerts     make ci-phase47-strategic-alerts     VIEWER_BLOC=$VIEWER_BLOC
run phase47-incident-narratives  make ci-phase47-incident-narratives  VIEWER_BLOC=$VIEWER_BLOC
run phase47-daily-digest         make ci-phase47-daily-digest         VIEWER_BLOC=$VIEWER_BLOC

# ---- Phase 4.8 — governance / trust ---------------------------------
run phase48-alert-suppression    make ci-phase48-alert-suppression    VIEWER_BLOC=$VIEWER_BLOC
run phase48-trust-metrics        make ci-phase48-trust-metrics        VIEWER_BLOC=$VIEWER_BLOC
run phase48-enrich-digest-trust  make ci-phase48-enrich-digest-trust  VIEWER_BLOC=$VIEWER_BLOC
run phase48-enrich-narrative-sources make ci-phase48-enrich-narrative-sources VIEWER_BLOC=$VIEWER_BLOC

echo "###### ci-operational-pipeline end   $(date -u +%FT%TZ) ######" >> "$LOG"
