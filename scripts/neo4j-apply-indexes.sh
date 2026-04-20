#!/usr/bin/env bash
# Apply the Neo4j index + constraint baseline. Idempotent; run on
# every deploy before the nightly sync jobs.
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
# shellcheck disable=SC1091
[[ -f .env ]] && source .env 2>/dev/null || true
set +a
: "${NEO4J_PASSWORD:?NEO4J_PASSWORD required}"

docker compose --env-file .env -f infra/docker-compose.yml exec -T neo4j \
  cypher-shell -u neo4j -p "$NEO4J_PASSWORD" -d neo4j --format plain \
  < infra/neo4j/indexes.cypher
