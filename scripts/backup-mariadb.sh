#!/bin/bash
# AegisCore MariaDB backup — safe logical dump using mariadb-dump.
#
# Usage:
#   ./scripts/backup-mariadb.sh              # one-shot backup
#   crontab: 0 */6 * * * /opt/AegisCore/scripts/backup-mariadb.sh
#
# Writes to /opt/AegisCore/backups/mariadb/ with daily rotation.
# Keeps 7 days of backups.

set -eu

BACKUP_DIR="/opt/AegisCore/backups/mariadb"
COMPOSE_FILE="/opt/AegisCore/infra/docker-compose.yml"
ENV_FILE="/opt/AegisCore/.env"
RETENTION_DAYS=7

# Parse .env for DB credentials (grep + cut, not source — .env has
# values with parentheses that break bash).
DB_USER=$(grep '^MARIADB_USER=' "$ENV_FILE" | cut -d= -f2 | tr -d '"' || echo "aegiscore")
DB_PASS=$(grep '^MARIADB_PASSWORD=' "$ENV_FILE" | cut -d= -f2 | tr -d '"')
DB_NAME=$(grep '^MARIADB_DATABASE=' "$ENV_FILE" | cut -d= -f2 | tr -d '"' || echo "aegiscore")
DB_USER="${DB_USER:-aegiscore}"
DB_NAME="${DB_NAME:-aegiscore}"

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"

echo "[$(date)] Starting backup → $BACKUP_FILE"

# Use mariadb-dump inside the container. --single-transaction gives a
# consistent snapshot without locking (InnoDB only). --routines and
# --triggers preserve stored code. --quick streams rows instead of
# buffering the whole table in memory.
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" \
    exec -T mariadb mariadb-dump \
    -u"$DB_USER" -p"$DB_PASS" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    "$DB_NAME" \
    | gzip > "$BACKUP_FILE"

SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
echo "[$(date)] Backup complete: $BACKUP_FILE ($SIZE)"

# Rotate old backups.
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +$RETENTION_DAYS -delete
REMAINING=$(ls -1 "$BACKUP_DIR"/*.sql.gz 2>/dev/null | wc -l)
echo "[$(date)] Retention: kept $REMAINING backups (${RETENTION_DAYS}d policy)"
