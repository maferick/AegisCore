#!/bin/bash
# Safe MariaDB restart — stops traffic, clean shutdown, backup, restart.
#
# Use this INSTEAD of `docker compose restart mariadb` when changing
# InnoDB config (buffer_pool_size, log_file_size, etc).
#
# What happened without this (2026-04-16): changed innodb_log_file_size
# from 1G→2G via a simple restart. InnoDB resized the redo log on
# startup, corrupted tables, lost all data. 7.7M killmails gone.

set -eu

COMPOSE="docker compose -f /opt/AegisCore/infra/docker-compose.yml --env-file /opt/AegisCore/.env"

echo "=== Safe MariaDB restart ==="
echo ""

# 1. Stop all writers.
echo "[1/5] Stopping write traffic (Horizon + scheduler + Python workers)..."
$COMPOSE stop horizon scheduler killmail_stream killmail_backfill_scheduler market_poll_scheduler market_import_scheduler killmail_search_scheduler 2>/dev/null || true
sleep 3

# 2. Request clean InnoDB shutdown (flushes all dirty pages).
echo "[2/5] Requesting clean InnoDB shutdown..."
$COMPOSE exec -T mariadb mariadb -u root -p"${MARIADB_ROOT_PASSWORD}" \
    -e "SET GLOBAL innodb_fast_shutdown=0;"
sleep 2

# 3. Backup.
echo "[3/5] Taking backup..."
/opt/AegisCore/scripts/backup-mariadb.sh

# 4. Stop MariaDB.
echo "[4/5] Stopping MariaDB..."
$COMPOSE stop mariadb
sleep 5

# 5. Start MariaDB + resume traffic.
echo "[5/5] Starting MariaDB with new config..."
$COMPOSE up -d mariadb
echo "Waiting for MariaDB health check..."
$COMPOSE exec -T mariadb mariadb -u root -p"${MARIADB_ROOT_PASSWORD}" \
    -e "SELECT 'MariaDB is ready';" 2>/dev/null || sleep 10

echo ""
echo "Verifying data integrity..."
$COMPOSE exec -T mariadb mariadb -u aegiscore -paegiscore aegiscore \
    -e "SELECT 'killmails' as tbl, COUNT(*) as cnt FROM killmails UNION ALL SELECT 'ref_types', COUNT(*) FROM ref_item_types UNION ALL SELECT 'market_history', COUNT(*) FROM market_history;"

echo ""
echo "If counts look correct, restart writers:"
echo "  $COMPOSE up -d horizon scheduler killmail_stream killmail_backfill_scheduler market_poll_scheduler market_import_scheduler"
echo ""
echo "If counts are ZERO — restore from backup:"
echo "  gunzip -c /opt/AegisCore/backups/mariadb/LATEST.sql.gz | docker compose exec -T mariadb mariadb -u root -pROOT_PASSWORD aegiscore"
