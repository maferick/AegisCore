#!/usr/bin/env bash
# Counter-Intel scheduling installer.
#
# Installs three crons:
#   - 05:00 UTC daily   ci-daily-pipeline.sh         (character + Phase 1/2)
#   - 06:00 UTC daily   ci-operational-pipeline.sh   (Phase 4 / 4.5 / 4.6 / 4.7)
#   - hourly @ :05      ci-coverage-check.sh         (staleness alert)
#
# Removes superseded individual cron lines that are now folded into
# the pipelines. Repairs the historical phase17 1h cron line that was
# missing `cd /opt/AegisCore` (caused make: *** No rule to make target).
#
# Idempotent: safe to re-run.

set -euo pipefail

LOG_DIR="/opt/AegisCore/scripts/log"
mkdir -p "$LOG_DIR"

WANTED_CRONS=(
    '0 5 * * * /opt/AegisCore/scripts/ci-daily-pipeline.sh'
    '0 6 * * * /opt/AegisCore/scripts/ci-operational-pipeline.sh'
    '5 * * * * /opt/AegisCore/scripts/ci-coverage-check.sh'
)

# Cron lines this script previously installed but no longer want — they
# are now folded into pipelines.
SUPERSEDED_PATTERNS=(
    'make ci-features'
    'make ci-projection'
    'make ci-phase1-relative'
    'make ci-phase2-triangulation'
)

CURRENT="$(crontab -l 2>/dev/null || true)"

# 1. Drop superseded lines.
TMP_PATTERNS="$(mktemp)"
trap 'rm -f "$TMP_PATTERNS"' EXIT
for p in "${SUPERSEDED_PATTERNS[@]}"; do
    printf '%s\n' "$p" >> "$TMP_PATTERNS"
done
FILTERED="$(printf '%s\n' "$CURRENT" | awk -v pf="$TMP_PATTERNS" '
    BEGIN {
        n = 0
        while ((getline ln < pf) > 0) { n++; arr[n] = ln }
        close(pf)
    }
    {
        keep = 1
        for (i = 1; i <= n; i++) {
            if (index($0, arr[i]) > 0) { keep = 0; break }
        }
        if (keep) print $0
    }
')"

# 2. Repair phase17 1h cron line — historical entry missed the
#    `cd /opt/AegisCore` so make ran from cron home and got
#    "No rule to make target ci-phase17-what-changed". Repair by
#    rewriting any line that has the symptom into a working version.
FIXED="$(printf '%s\n' "$FILTERED" | awk '
    /WINDOW=1h.*make ci-phase17-what-changed/ && !/cd \/opt\/AegisCore/ {
        # rewrite to a working line, preserving the cron schedule prefix
        # (everything up to the env vars).
        match($0, /^([^*]*\*[^*]*\*[^*]*\*[^*]*\*[^*]*\*) +/, sched)
        if (sched[0]) {
            print sched[0] "cd /opt/AegisCore && VIEWER_BLOC=1 WINDOW=1h make ci-phase17-what-changed >> /opt/AegisCore/scripts/log/phase17-what-changed.log 2>&1"
            next
        }
    }
    { print }
')"

# 3. Append wanted lines if not already present.
APPENDED="$FIXED"
for line in "${WANTED_CRONS[@]}"; do
    if printf '%s\n' "$APPENDED" | grep -Fq "$line"; then
        echo "already present: $line"
    else
        APPENDED="${APPENDED}"$'\n'"${line}"
        echo "appending: $line"
    fi
done

# 4. Squash double blank lines.
CLEANED="$(printf '%s\n' "$APPENDED" | awk 'NF || prev_nf { print } { prev_nf = NF }')"

printf '%s\n' "$CLEANED" | crontab -
echo "done. current cron:"
crontab -l
