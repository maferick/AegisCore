#!/usr/bin/env bash
# CI / operational compute coverage check.
#
# Reads scripts/ci-coverage-manifest.yml. For every table with a
# non-null max_age_hours, queries MAX(timestamp_col) and compares to
# now. Logs OK / WARN / CRIT per row.
#
# WARN if max_age exceeded; CRIT if exceeded by >2x. Exits non-zero
# when any CRIT, so cron can mail or downstream alerting can hook.
#
# Doesn't make assumptions about what "should" run — manifest is
# authoritative. If a job is missing schedule, the line says
# "NOT IN CRON" and this script will eventually catch it as the
# table goes stale.
#
# Reason for existing: 2026-05-01 incident, ci_character_anomalies_
# rolling froze 11d because nothing scheduled the INSERT pass.
# Adding more jobs is fine; forgetting to schedule them is the bug
# this catches.

set -uo pipefail
cd /opt/AegisCore

LOG_DIR=/opt/AegisCore/scripts/log
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/ci-coverage-check.log"
MANIFEST=/opt/AegisCore/scripts/ci-coverage-manifest.yml
NOW_TS=$(date -u +%FT%TZ)

if [[ ! -f "$MANIFEST" ]]; then
    echo "[$NOW_TS] FATAL manifest missing: $MANIFEST" >> "$LOG"
    exit 2
fi

# Load DB password from .env (matches make ci-* convention).
if [[ -f /opt/AegisCore/.env ]]; then
    # shellcheck disable=SC1091
    set -a
    source <(grep -E '^MARIADB_(ROOT_)?PASSWORD=' /opt/AegisCore/.env)
    set +a
fi
DB_PASS="${MARIADB_ROOT_PASSWORD:-CHANGE_ME}"

q() {
    docker exec mariadb mariadb -uroot -p"$DB_PASS" -D aegiscore -N -B -e "$1" 2>/dev/null
}

echo "###### ci-coverage-check $NOW_TS ######" >> "$LOG"

# Parse manifest entries with awk (avoids yq/python dependency).
# Format expected: each entry block starts with "  - table: NAME" and
# the next 4 indented lines carry the bindings. Tolerates extra
# fields (notes / schedule) by ignoring anything we don't recognise.
crit_count=0
warn_count=0
ok_count=0
skip_count=0

awk '
    /^  - table:/ { if (table) print_block(); table=$3; ts=""; max="" }
    /^    timestamp_col:/ { ts=$2 }
    /^    max_age_hours:/ { max=$2 }
    END { if (table) print_block() }
    function print_block() {
        printf "%s|%s|%s\n", table, ts, max
    }
' "$MANIFEST" | while IFS='|' read -r table ts max; do
    [[ -z "$table" || -z "$ts" ]] && continue
    if [[ "$max" == "null" || -z "$max" ]]; then
        echo "[$NOW_TS] SKIP $table (max_age_hours=null — not yet shipped)" >> "$LOG"
        skip_count=$((skip_count + 1))
        continue
    fi
    latest=$(q "SELECT IFNULL(MAX($ts),'') FROM $table" 2>&1 | head -1)
    if [[ -z "$latest" ]]; then
        echo "[$NOW_TS] CRIT $table no rows (or query failed)" >> "$LOG"
        crit_count=$((crit_count + 1))
        continue
    fi
    age_min=$(q "SELECT TIMESTAMPDIFF(MINUTE, '$latest', UTC_TIMESTAMP())")
    age_h=$((age_min / 60))
    if (( age_h > max * 2 )); then
        echo "[$NOW_TS] CRIT $table age=${age_h}h max=${max}h latest=$latest" >> "$LOG"
        crit_count=$((crit_count + 1))
    elif (( age_h > max )); then
        echo "[$NOW_TS] WARN $table age=${age_h}h max=${max}h latest=$latest" >> "$LOG"
        warn_count=$((warn_count + 1))
    else
        echo "[$NOW_TS] OK   $table age=${age_h}h max=${max}h latest=$latest" >> "$LOG"
        ok_count=$((ok_count + 1))
    fi
done

# Counts above are inside a subshell (pipe to while), so re-derive
# from the log tail to print the summary.
tail_block=$(awk -v ts="$NOW_TS" '$0 ~ "###### ci-coverage-check " ts { capture=1; next } /^######/ { capture=0 } capture' "$LOG")
ok=$(echo "$tail_block" | grep -c '^\[.*\] OK ')
warn=$(echo "$tail_block" | grep -c '^\[.*\] WARN ')
crit=$(echo "$tail_block" | grep -c '^\[.*\] CRIT ')
skip=$(echo "$tail_block" | grep -c '^\[.*\] SKIP ')
echo "[$NOW_TS] SUMMARY ok=$ok warn=$warn crit=$crit skip=$skip" >> "$LOG"

echo "ci-coverage-check $NOW_TS ok=$ok warn=$warn crit=$crit skip=$skip"
exit $(( crit > 0 ? 1 : 0 ))
