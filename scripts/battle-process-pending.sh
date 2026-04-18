#!/usr/bin/env bash
# Auto-pipeline sweeper: runs Specs 2→3→4→5 on every (theater,
# alliance) pair that has sub-fleet membership but no Spec 4
# features yet. Invoked from host cron (see Makefile target
# `make battle-process-pending`).
#
# Environment variables (optional):
#   BATTLE_LIMIT=20           max pairs per invocation
#   BATTLE_MIN_MEMBERS=10     min participants per alliance side to qualify
#   BATTLE_WEIGHT_LABEL=v1_calibrated_seed
#
# Exit non-zero if ANY stage fails for ANY pair so cron logs
# flag it, but continue processing remaining pairs.
set -u
cd "$(dirname "$0")/.."

# Load MARIADB_* + friends from .env so the mariadb auth query works.
set -a
# shellcheck disable=SC1091
[[ -f .env ]] && source .env
set +a

LIMIT="${BATTLE_LIMIT:-20}"
MIN_MEMBERS="${BATTLE_MIN_MEMBERS:-10}"
WEIGHT_LABEL="${BATTLE_WEIGHT_LABEL:-v1_calibrated_seed}"

# Pull candidate list from MariaDB. Filter: has participants,
# missing Spec 4 features. Newest battles first.
CANDIDATES=$(
  docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u"${MARIADB_USER:-aegiscore}" -p"${MARIADB_PASSWORD}" \
            "${MARIADB_DATABASE:-aegiscore}" -NBe "
    SELECT CONCAT(bt.id, ',', p.alliance_id)
      FROM battle_theaters bt
      JOIN battle_theater_participants p ON p.theater_id = bt.id
      LEFT JOIN battle_character_role_features f
        ON f.battle_id = bt.id AND f.alliance_id = p.alliance_id
     WHERE p.alliance_id > 0
       AND f.battle_id IS NULL
     GROUP BY bt.id, p.alliance_id, bt.end_time
    HAVING COUNT(DISTINCT p.character_id) >= ${MIN_MEMBERS}
     ORDER BY bt.end_time DESC
     LIMIT ${LIMIT};
  " 2>/dev/null
)

if [[ -z "$CANDIDATES" ]]; then
  echo "[$(date -u +%FT%TZ)] battle-auto-pipeline: no pending pairs"
  exit 0
fi

PAIR_COUNT=$(echo "$CANDIDATES" | wc -l)
echo "[$(date -u +%FT%TZ)] battle-auto-pipeline: ${PAIR_COUNT} pair(s) pending"

FAIL=0
while IFS=, read -r bid aid <&3; do
    [[ -z "$bid" || -z "$aid" ]] && continue
    echo "[$(date -u +%FT%TZ)]   processing battle=${bid} alliance=${aid}"

    for stage in battle_graph battle_partition battle_features; do
        docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
            run --rm -T "${stage}" run \
              --battle-id "${bid}" --alliance-id "${aid}" </dev/null > /tmp/bp-stage.log 2>&1
        if [[ $? -ne 0 ]]; then
            echo "[$(date -u +%FT%TZ)]     ${stage} FAILED for battle=${bid} alliance=${aid}"
            tail -3 /tmp/bp-stage.log | sed 's/^/        /'
            FAIL=1
            continue 2
        fi
    done

    docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
        run --rm -T battle_role_scoring run \
          --battle-id "${bid}" --alliance-id "${aid}" \
          --weight-label "${WEIGHT_LABEL}" </dev/null > /tmp/bp-stage.log 2>&1
    if [[ $? -ne 0 ]]; then
        echo "[$(date -u +%FT%TZ)]     battle_role_scoring FAILED for battle=${bid} alliance=${aid}"
        tail -3 /tmp/bp-stage.log | sed 's/^/        /'
        FAIL=1
    fi
done 3<<< "$CANDIDATES"

echo "[$(date -u +%FT%TZ)] battle-auto-pipeline: done (failures=${FAIL})"
exit ${FAIL}
