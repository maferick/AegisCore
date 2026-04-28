#!/usr/bin/env bash
# Auto-pipeline sweeper: runs Specs 2→3→4→5 on every (theater,
# alliance) pair that has sub-fleet membership but no Spec 4
# features yet. Invoked from host cron (see Makefile target
# `make battle-process-pending`).
#
# Environment variables (optional):
#   BATTLE_LIMIT=20           max pairs per invocation
#   BATTLE_MIN_MEMBERS=<n>    min participants; default = small_tier_max+1
#                             (battle_graph skips pilot_count <= small_tier_max,
#                             so smaller pairs would loop forever as 'pending')
#   BATTLE_MAX_MEMBERS=1500   max participants. Pairs above this cap are
#                             deferred to manual / v2. Empirically the
#                             centrality + role-inference step on Neo4j hangs
#                             indefinitely on graphs > ~1M edges (≥1500 pilots),
#                             starving threads and wedging the pipeline.
#   BATTLE_STAGE_TIMEOUT=600  per-stage hard timeout (seconds). Any stage
#                             exceeding this is SIGTERM'd so the script can
#                             move on to the next pair instead of wedging.
#   BATTLE_WEIGHT_LABEL=v1_calibrated_seed
#   BATTLE_ORPHAN_TTL_MIN=30  reap battle_graph containers older than N min
#
# Concurrency:
#   flock-guarded so overlapping cron ticks don't stack invocations.
#   Without this, a slow run (single huge battle takes 20 minutes;
#   cron tick is 5 minutes) gets a fresh batch on top, and 16 stuck
#   battle_graph containers can pile up exhausting Neo4j threads.
#
# Exit non-zero if ANY stage fails for ANY pair so cron logs
# flag it, but continue processing remaining pairs.
set -u
cd "$(dirname "$0")/.."

LOCK_FILE="/tmp/aegiscore-battle-process-pending.lock"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "[$(date -u +%FT%TZ)] battle-auto-pipeline: previous tick still running, skipping"
    exit 0
fi

# Reap any leftover battle_graph orphans from a previously-killed run.
# `docker compose run --rm` doesn't clean up if the host kills the
# parent (cron timeout, reboot, OOM). These idle containers hold
# Neo4j threads and starve subsequent runs.
ORPHAN_TTL_MIN="${BATTLE_ORPHAN_TTL_MIN:-30}"
ORPHAN_CUTOFF=$(date -u -d "-${ORPHAN_TTL_MIN} minutes" +%s)
for cid in $(docker ps --filter "name=battle_graph-run" --filter "status=running" --format '{{.ID}}'); do
    started=$(docker inspect -f '{{.State.StartedAt}}' "$cid" 2>/dev/null || echo "")
    [[ -z "$started" ]] && continue
    started_ts=$(date -u -d "$started" +%s 2>/dev/null || echo 0)
    if [[ "$started_ts" -gt 0 && "$started_ts" -lt "$ORPHAN_CUTOFF" ]]; then
        echo "[$(date -u +%FT%TZ)] battle-auto-pipeline: reaping orphan $cid (started $started)"
        docker rm -f "$cid" >/dev/null 2>&1 || true
    fi
done

# Load MARIADB_* + friends from .env so the mariadb auth query works.
set -a
# shellcheck disable=SC1091
[[ -f .env ]] && source .env
set +a

LIMIT="${BATTLE_LIMIT:-20}"
WEIGHT_LABEL="${BATTLE_WEIGHT_LABEL:-v1_calibrated_seed}"

# Active weight version used for inference lookups.
WEIGHT_VERSION=$(
  docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u"${MARIADB_USER:-aegiscore}" -p"${MARIADB_PASSWORD}" \
            "${MARIADB_DATABASE:-aegiscore}" -NBe "
    SELECT weight_version FROM battle_role_weight_versions WHERE is_default=1 LIMIT 1
  " 2>/dev/null
)
WEIGHT_VERSION="${WEIGHT_VERSION:-9}"

# Tie min-members floor to battle_graph's skip threshold so we don't
# repeatedly schedule pairs that battle_graph will mark 'skipped'.
SMALL_TIER_MAX=$(
  docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u"${MARIADB_USER:-aegiscore}" -p"${MARIADB_PASSWORD}" \
            "${MARIADB_DATABASE:-aegiscore}" -NBe "
    SELECT MAX(small_tier_max) FROM battle_graph_algo_profile_versions
  " 2>/dev/null
)
SMALL_TIER_MAX="${SMALL_TIER_MAX:-10}"
MIN_MEMBERS="${BATTLE_MIN_MEMBERS:-$((SMALL_TIER_MAX + 1))}"
MAX_MEMBERS="${BATTLE_MAX_MEMBERS:-1500}"
STAGE_TIMEOUT="${BATTLE_STAGE_TIMEOUT:-600}"

# Pull candidate list: locked battles that need either the full pipeline
# or just role scoring. We skip unlocked theaters because theater_clustering
# recycles their ids every 5 min — running the pipeline against an id that
# is about to disappear wastes every stage downstream.
#
# `stage_needed` is 'full' (needs graph+partition+features+scoring) or
# 'scoring_only' (features already computed but no inference row yet).
CANDIDATES=$(
  docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u"${MARIADB_USER:-aegiscore}" -p"${MARIADB_PASSWORD}" \
            "${MARIADB_DATABASE:-aegiscore}" -NBe "
    SELECT CONCAT(bt.id, ',', p.alliance_id, ',',
                  CASE WHEN f.battle_id IS NULL THEN 'full' ELSE 'scoring_only' END)
      FROM battle_theaters bt
      JOIN battle_theater_participants p ON p.theater_id = bt.id
      LEFT JOIN battle_character_role_features f
        ON f.battle_id = bt.id AND f.alliance_id = p.alliance_id
      LEFT JOIN battle_character_role_inference i
        ON i.battle_id = bt.id AND i.alliance_id = p.alliance_id
       AND i.weight_version = ${WEIGHT_VERSION}
     WHERE bt.locked_at IS NOT NULL
       AND p.alliance_id > 0
       AND i.battle_id IS NULL
     GROUP BY bt.id, p.alliance_id, bt.end_time, f.battle_id
    HAVING COUNT(DISTINCT p.character_id) >= ${MIN_MEMBERS}
       AND COUNT(DISTINCT p.character_id) <= ${MAX_MEMBERS}
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
while IFS=, read -r bid aid stage_needed <&3; do
    [[ -z "$bid" || -z "$aid" ]] && continue
    stage_needed="${stage_needed:-full}"
    echo "[$(date -u +%FT%TZ)]   processing battle=${bid} alliance=${aid} (${stage_needed})"

    if [[ "${stage_needed}" == "full" ]]; then
        for stage in battle_graph battle_partition battle_features; do
            timeout --kill-after=30 "${STAGE_TIMEOUT}" \
              docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
                run --rm -T "${stage}" run \
                  --battle-id "${bid}" --alliance-id "${aid}" </dev/null > /tmp/bp-stage.log 2>&1
            rc=$?
            if [[ $rc -eq 124 ]]; then
                echo "[$(date -u +%FT%TZ)]     ${stage} TIMEOUT (${STAGE_TIMEOUT}s) for battle=${bid} alliance=${aid} — reaping container"
                docker ps --filter "name=${stage}-run" -q | xargs -r docker rm -f >/dev/null 2>&1 || true
                FAIL=1
                continue 2
            fi
            if [[ $rc -ne 0 ]]; then
                echo "[$(date -u +%FT%TZ)]     ${stage} FAILED for battle=${bid} alliance=${aid}"
                tail -3 /tmp/bp-stage.log | sed 's/^/        /'
                FAIL=1
                continue 2
            fi
        done
    fi

    timeout --kill-after=30 "${STAGE_TIMEOUT}" \
      docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
        run --rm -T battle_role_scoring run \
          --battle-id "${bid}" --alliance-id "${aid}" \
          --weight-label "${WEIGHT_LABEL}" </dev/null > /tmp/bp-stage.log 2>&1
    rc=$?
    if [[ $rc -eq 124 ]]; then
        echo "[$(date -u +%FT%TZ)]     battle_role_scoring TIMEOUT (${STAGE_TIMEOUT}s) for battle=${bid} alliance=${aid} — reaping"
        docker ps --filter "name=battle_role_scoring-run" -q | xargs -r docker rm -f >/dev/null 2>&1 || true
        FAIL=1
    elif [[ $rc -ne 0 ]]; then
        echo "[$(date -u +%FT%TZ)]     battle_role_scoring FAILED for battle=${bid} alliance=${aid}"
        tail -3 /tmp/bp-stage.log | sed 's/^/        /'
        FAIL=1
    fi
done 3<<< "$CANDIDATES"

echo "[$(date -u +%FT%TZ)] battle-auto-pipeline: done (failures=${FAIL})"
exit ${FAIL}
