#!/bin/bash
# Guard against accidental database reinitialization.
#
# MariaDB's entrypoint runs scripts in /docker-entrypoint-initdb.d/
# ONLY on first initialization (when /var/lib/mysql/mysql doesn't exist).
# If this script runs, it means MariaDB thinks this is a fresh install.
#
# We check for a sentinel file that should exist after the first real
# initialization. If the sentinel exists but the mysql system dir
# doesn't, something went wrong — refuse to continue.

SENTINEL="/var/lib/mysql/.aegiscore-initialized"

if [ -f "$SENTINEL" ]; then
    echo "FATAL: AegisCore sentinel exists but MariaDB is reinitializing!"
    echo "This means data was lost. Refusing to start with a blank database."
    echo "Check the bind mount and InnoDB files."
    echo "To force a fresh start, remove: $SENTINEL"
    exit 1
fi

# First real initialization — create the sentinel.
touch "$SENTINEL"
echo "AegisCore: first initialization complete. Sentinel created."
