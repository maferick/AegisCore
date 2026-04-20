#!/usr/bin/env bash
# FastRP embeddings + Filtered KNN rebuild for :CICharacter nodes.
#
# GDS named graphs only live inside a single bolt session on some
# client stacks, so the safest way to run this chain is through
# cypher-shell with the script file passed in.
#
# Usage:
#   NEO4J_PASSWORD=... ./scripts/neo4j-compute-embeddings.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

# Load .env so NEO4J_PASSWORD is available.
set -a
# shellcheck disable=SC1091
[[ -f .env ]] && source .env 2>/dev/null || true
set +a

: "${NEO4J_PASSWORD:?NEO4J_PASSWORD required}"

# Stream the script through cypher-shell inside the Neo4j container.
docker compose --env-file .env -f infra/docker-compose.yml exec -T neo4j \
  cypher-shell -u neo4j -p "$NEO4J_PASSWORD" -d neo4j --format plain \
  < infra/neo4j/compute-embeddings.cypher
