#!/usr/bin/env bash
# Periodic zkill catch-up: for every system that had killmail activity
# in the last 4 hours, ask zkill for the last 7200 seconds worth of
# kms and ingest any we don't already have.
#
# Scope: close the R2Z2-lag gap we can't close live. R2Z2 sometimes
# drops or delays kills during big fights; zkill's public API has a
# different view and usually covers a bit more. This catches the
# "everyone else saw the kill but our stream missed it" case.
#
# Rate profile: ~1 req/sec to zkill (their rule) + up to 15 req/sec
# to ESI (well under the 100/sec cap). Active-system count is
# typically <100 in a 4h window.

set -uo pipefail
cd /opt/AegisCore

LOG=/opt/AegisCore/scripts/zkill-catchup.log
SCRIPT=/opt/AegisCore/scripts/fill-gap-zkill.py
START=$(date -u +%Y-%m-%dT%H:%M:%SZ)
echo "=== $START zkill-catchup start ===" >>"$LOG"

# Collect active system IDs — last 4h by created_at (covers the
# stream-lag window we care about).
ACTIVE_SYSTEMS=$(docker compose --env-file .env -f infra/docker-compose.yml exec -T php-fpm \
    php artisan tinker --execute="
use Illuminate\\Support\\Facades\\DB;
\$cutoff = now()->subHours(4);
foreach (DB::table('killmails')->where('created_at','>=',\$cutoff)->where('solar_system_id','>',0)->selectRaw('solar_system_id, COUNT(*) c')->groupBy('solar_system_id')->orderByDesc('c')->pluck('solar_system_id') as \$s) echo \$s.PHP_EOL;
" 2>&1 | grep -E '^[0-9]+$')

n=$(echo "$ACTIVE_SYSTEMS" | grep -c . || true)
echo "active systems: $n" >>"$LOG"

# Window for ESI ingest filter: last 3h to give zkill-lagged kms
# a chance to land without picking up yesterday's stuff.
FROM=$(date -u -d '3 hours ago' +%Y-%m-%dT%H:%M)
TO=$(date -u -d '5 minutes from now' +%Y-%m-%dT%H:%M)

total_ingested=0
for sys in $ACTIVE_SYSTEMS; do
    out=$(docker compose --env-file .env -f infra/docker-compose.yml \
        --profile tools run --rm --no-deps --entrypoint python \
        -v "$SCRIPT:/app/fill-gap-zkill.py:ro" \
        killmail_backfill /app/fill-gap-zkill.py \
          --system-id "$sys" \
          --past-seconds 7200 \
          --from "$FROM" \
          --to "$TO" 2>&1 | tail -5)
    echo "sys=$sys:" >>"$LOG"
    echo "$out" | sed 's/^/  /' >>"$LOG"
    # Parse "ingested=N" from the "done:" line to tally.
    ing=$(echo "$out" | grep -oE 'ingested=[0-9]+' | tail -1 | cut -d= -f2)
    total_ingested=$((total_ingested + ${ing:-0}))
    # Pace between systems — 1 zkill req/sec rule.
    sleep 1
done

END=$(date -u +%Y-%m-%dT%H:%M:%SZ)
echo "=== $END zkill-catchup done · total_ingested=$total_ingested across $n systems ===" >>"$LOG"
