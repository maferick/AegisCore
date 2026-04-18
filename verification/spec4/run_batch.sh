#!/usr/bin/env bash
# Spec 4 batch runner: fans battle_features across the 8 validation
# battles and tees output to /tmp/spec4_batch.log.
set -u
cd "$(git rev-parse --show-toplevel 2>/dev/null || echo /opt/AegisCore)"
LOG=/tmp/spec4_batch.log
: > "$LOG"

CASES=(
    "40365 99011978 Amamake-99011978"
    "40228 99014027 Aldranette-99014027"
    "40374 99003581 2E-ZR5-Frat"
    "40541 99011223 U-L4KS-Sigma"
    "40478 99003581 Atioth-Frat"
    "40537 1900696668 Komo-99011978"
    "40605 99012122 9S-GPT-99012122"
    "40553 99011223 6RQ9-A-Sigma"
)

for case in "${CASES[@]}"; do
    read -r bid aid label <<< "$case"
    echo "=== $(date -u +%H:%M:%S) case=$label battle=$bid alliance=$aid ===" | tee -a "$LOG"
    docker compose --env-file .env -f infra/docker-compose.yml --profile tools run --rm battle_features \
        run --battle-id "$bid" --alliance-id "$aid" 2>&1 | tail -6 >> "$LOG"
done
echo "=== done $(date -u +%H:%M:%S) ===" | tee -a "$LOG"
