#!/usr/bin/env bash
# One-shot: reconcile 2026-04-17 killmails once EVE Ref publishes the
# daily archive. Set via `at` (see /opt/AegisCore/scripts/backfill-apr17.log).
# Retries every 30 min until the archive lands or 23:00 local, then
# gives up and logs.
set -uo pipefail

cd /opt/AegisCore
LOG=/opt/AegisCore/scripts/backfill-apr17.log
SYS=30000318   # 9-GBPD

echo "=== $(date -u +%Y-%m-%dT%H:%M:%SZ) backfill-apr17 start ===" >>"$LOG"

ok=0
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    echo "--- attempt $attempt $(date -u +%H:%M:%SZ) ---" >>"$LOG"
    out=$(docker compose --env-file .env -f infra/docker-compose.yml \
        --profile tools run --rm killmail_backfill backfill \
        --from 2026-04-17 --to 2026-04-17 2>&1)
    echo "$out" >>"$LOG"
    if echo "$out" | grep -q "day loaded day=2026-04-17"; then
        ok=1
        break
    fi
    if echo "$out" | grep -q "day skipped (no archive) day=2026-04-17"; then
        echo "archive not published yet, retrying in 30 min" >>"$LOG"
        sleep 1800
        continue
    fi
    echo "unexpected output, aborting" >>"$LOG"
    break
done

if [ "$ok" = "1" ]; then
    echo "backfill succeeded, triggering clustering + enrich" >>"$LOG"
    docker compose --env-file .env -f infra/docker-compose.yml exec -T \
        theater_clustering_scheduler python -m theater_clustering run >>"$LOG" 2>&1
    docker compose --env-file .env -f infra/docker-compose.yml exec -T \
        php-fpm php artisan tinker --execute="\App\Domains\KillmailsBattleTheaters\Jobs\EnrichPendingKillmails::dispatch('2026-04'); echo 'enrich dispatched';" >>"$LOG" 2>&1
    new_count=$(docker compose --env-file .env -f infra/docker-compose.yml exec -T \
        php-fpm php artisan tinker --execute="use Illuminate\\Support\\Facades\\DB; echo DB::table('killmails')->where('solar_system_id',${SYS})->where('killed_at','>=','2026-04-17')->where('killed_at','<','2026-04-18')->count();" 2>&1 | tail -1)
    echo "9-GBPD km count after backfill: $new_count (br-icon target: 2670)" >>"$LOG"
fi

echo "=== $(date -u +%Y-%m-%dT%H:%M:%SZ) done ===" >>"$LOG"
