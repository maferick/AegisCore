#!/usr/bin/env bash
# Counter-Intel daily pipeline. Runs the full chain in dependency
# order so ci_character_anomalies_rolling, baseline, triangulation,
# graph features, and Neo4j projection all advance to the same
# window_end_date each day.
#
# Replaces the previous fan of separate cron lines that left
# ci-anomalies / ci-graph-features / ci-phase2-baseline / ci-phase2-
# cohort-features unscheduled — incident: 2026-05-01, anomaly table
# 11d stale, character bands frozen on outdated recent_hostile_join.

set -uo pipefail
cd /opt/AegisCore

LOG_DIR=/opt/AegisCore/scripts/log
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/ci-daily-pipeline.log"
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
        return $rc
    fi
}

echo "###### ci-daily-pipeline start $(date -u +%FT%TZ) window=$WIN bloc=$VIEWER_BLOC ######" >> "$LOG"

# 1. base feature row per character
run features            make ci-features            CI_ARGS="--window-end $WIN"

# 2. first anomalies pass (no graph features yet on first-ever run; daily
#    runs already have yesterday's graph features so this is fine)
run anomalies-1         make ci-anomalies           VIEWER_BLOC=$VIEWER_BLOC CI_ARGS="--window-end $WIN"

# 3. graph features read anomaly seed columns (hostile_alliance_count_history,
#    hostile_cooccurrence_count) populated above
run graph-features      make ci-graph-features      VIEWER_BLOC=$VIEWER_BLOC CI_ARGS="--window-end $WIN"

# 4. re-run anomalies so seed_boost / internal_bridge / small_ring fold into
#    today's review_priority_score
run anomalies-2         make ci-anomalies           VIEWER_BLOC=$VIEWER_BLOC CI_ARGS="--window-end $WIN"

# 5. tz centroid + cohort features
run cohort-features     make ci-phase2-cohort-features CI_ARGS="--window-end $WIN"

# 6. bloc-relative phase 1 signals (fills community_hostile_pct +
#    asymmetric_top_pair_* on anomaly rows). MUST run before
#    phase2-baseline because baseline aggregates community_hostile_pct
#    across alliance members.
run phase1-relative     make ci-phase1-relative     VIEWER_BLOC=$VIEWER_BLOC CI_ARGS="--window-end $WIN"

# 7. alliance community baseline (per-alliance median/p90 community_hostile_pct).
#    Reads phase1-relative's output → must run after.
run phase2-baseline     make ci-phase2-baseline     VIEWER_BLOC=$VIEWER_BLOC CI_ARGS="--window-end $WIN"

# 8. recurring hostile triangle clusters
run phase2-triangulation make ci-phase2-triangulation VIEWER_BLOC=$VIEWER_BLOC CI_ARGS="--window-end $WIN"

# 9. project everything into Neo4j for downstream graph-insight reads
run projection          make ci-projection

echo "###### ci-daily-pipeline end   $(date -u +%FT%TZ) ######" >> "$LOG"
