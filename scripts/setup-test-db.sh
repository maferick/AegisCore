#!/bin/bash
# One-time bootstrap for the `aegiscore_test` MariaDB schema used by
# phpunit. Idempotent — safe to re-run.
#
# Why this script exists: the test suite needs a physically separate
# MariaDB schema from production (`aegiscore`) so that the unavoidable
# migrate:fresh can never target the prod database. The 2026-04-16
# incident proved that a misconfigured env can redirect Laravel's
# default connection to production; the fix is to pin the test
# connection to a different schema, and that schema has to exist
# before the first test runs.
#
# We do NOT let TestCase.php create the schema on the fly — that would
# require a root-equivalent credential in test code. An operator runs
# this script once per environment instead. The aegiscore user it
# grants is the same limited-privilege account the app already uses;
# it just gains access to the separate test schema.
#
# Usage:
#   ./scripts/setup-test-db.sh
#     or:
#   make test-db-setup

set -eu

COMPOSE="docker compose -f /opt/AegisCore/infra/docker-compose.yml --env-file /opt/AegisCore/.env"
ROOT_PW=$(grep '^MARIADB_ROOT_PASSWORD=' /opt/AegisCore/.env | cut -d= -f2- | tr -d '"')

if [ -z "$ROOT_PW" ]; then
    echo "FATAL: MARIADB_ROOT_PASSWORD not set in /opt/AegisCore/.env" >&2
    exit 1
fi

echo "[setup-test-db] Ensuring aegiscore_test exists + aegiscore user has access..."

$COMPOSE exec -T mariadb mariadb -u root -p"$ROOT_PW" <<'SQL'
CREATE DATABASE IF NOT EXISTS aegiscore_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON aegiscore_test.* TO 'aegiscore'@'%';
FLUSH PRIVILEGES;
SQL

echo "[setup-test-db] Done. `make test` is now safe to run."
