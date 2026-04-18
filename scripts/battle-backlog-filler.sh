#!/usr/bin/env bash
# Full backlog sweep: runs Specs 2→3→4→5 across every pending
# (theater, alliance) pair. No limit cap. Parallelism via xargs -P.
#
# One-shot by design; invoke manually when the backlog grows too
# far. For steady-state, the cron-driven
# scripts/battle-process-pending.sh sweep handles new arrivals.
set -u
cd "$(dirname "$0")/.."

set -a
# shellcheck disable=SC1091
[[ -f .env ]] && source .env 2>/dev/null || true
set +a

PARALLEL="${BACKLOG_PARALLEL:-3}"
MIN_MEMBERS="${BATTLE_MIN_MEMBERS:-10}"
WEIGHT_LABEL="${BATTLE_WEIGHT_LABEL:-v1_calibrated_seed}"
LIST_FILE="${1:-/tmp/battle-backlog-$(date -u +%Y%m%d-%H%M%S).txt}"

echo "[$(date -u +%FT%TZ)] backlog-filler: discovering pending pairs (min_members=${MIN_MEMBERS})"

docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
  mariadb -u"${MARIADB_USER:-aegiscore}" -p"${MARIADB_PASSWORD}" \
          "${MARIADB_DATABASE:-aegiscore}" -NBe "
  SELECT CONCAT(bt.id, ',', p.alliance_id)
    FROM battle_theaters bt
    JOIN battle_theater_participants p ON p.theater_id=bt.id
    LEFT JOIN battle_character_role_features f
      ON f.battle_id=bt.id AND f.alliance_id=p.alliance_id
   WHERE p.alliance_id>0 AND f.battle_id IS NULL
   GROUP BY bt.id, p.alliance_id, bt.end_time
  HAVING COUNT(DISTINCT p.character_id) >= ${MIN_MEMBERS}
   ORDER BY bt.end_time DESC
  " 2>/dev/null > "$LIST_FILE"

TOTAL=$(wc -l < "$LIST_FILE")
echo "[$(date -u +%FT%TZ)] backlog-filler: ${TOTAL} pair(s) queued at ${LIST_FILE}"

if [[ "$TOTAL" -eq 0 ]]; then
    echo "[$(date -u +%FT%TZ)] backlog-filler: nothing to do"
    exit 0
fi

# Per-pair runner: Specs 2→3→4→5 sequentially for one pair. xargs
# dispatches ${PARALLEL} of these in parallel.
export WEIGHT_LABEL
run_one() {
    local line="$1"
    local bid="${line%,*}"
    local aid="${line#*,}"
    [[ -z "$bid" || -z "$aid" ]] && return 0
    local log=/tmp/bf-${bid}-${aid}.log
    {
        echo "=== $(date -u +%H:%M:%S) battle=$bid alliance=$aid ==="
        for stage in battle_graph battle_partition battle_features; do
            docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
                run --rm -T "${stage}" run \
                  --battle-id "${bid}" --alliance-id "${aid}" </dev/null 2>&1 | tail -1
            if [[ "${PIPESTATUS[0]}" -ne 0 ]]; then
                echo "  ${stage} FAILED"
                return 1
            fi
        done
        docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
            run --rm -T battle_role_scoring run \
              --battle-id "${bid}" --alliance-id "${aid}" \
              --weight-label "${WEIGHT_LABEL}" </dev/null 2>&1 | tail -1 || return 1
    } > "$log" 2>&1
    tail -1 "$log"
}
export -f run_one

echo "[$(date -u +%FT%TZ)] backlog-filler: launching ${PARALLEL} parallel worker(s)"
cat "$LIST_FILE" | xargs -I{} -P "${PARALLEL}" bash -c 'run_one "$@"' _ {}

echo "[$(date -u +%FT%TZ)] backlog-filler: done"
