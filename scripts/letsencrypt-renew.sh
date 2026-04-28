#!/usr/bin/env bash
# Let's Encrypt cert renewal for AegisCore.
#
# Runs daily via host cron — certbot is a no-op when the cert is
# >30 days from expiry, so daily is safe. The webroot challenge is
# already wired into the nginx vhosts (.well-known/acme-challenge/
# served from /var/www/letsencrypt without auth/IP filters); this
# script just drives certbot and reloads nginx if anything renewed.
#
# Logs land in scripts/log/letsencrypt-renew.log. Crontab line:
#   17 3 * * * /opt/AegisCore/scripts/letsencrypt-renew.sh
set -euo pipefail

ROOT="${AEGISCORE_ROOT:-/opt/AegisCore}"
LOG_DIR="$ROOT/scripts/log"
LOG="$LOG_DIR/letsencrypt-renew.log"
mkdir -p "$LOG_DIR"

ts() { date -u +'%Y-%m-%dT%H:%M:%SZ'; }

{
    echo "── $(ts) certbot renew start ──"

    # --deploy-hook only fires when at least one cert was renewed,
    # so nginx only reloads when there's something new to reload.
    docker run --rm \
        -v "$ROOT/letsencrypt/etc:/etc/letsencrypt" \
        -v "$ROOT/letsencrypt/lib:/var/lib/letsencrypt" \
        -v "$ROOT/nginx/letsencrypt:/var/www/letsencrypt" \
        certbot/certbot:latest renew \
            --webroot -w /var/www/letsencrypt \
            --quiet \
            --deploy-hook "touch /var/www/letsencrypt/.renewed"

    if [ -f "$ROOT/nginx/letsencrypt/.renewed" ]; then
        echo "$(ts) cert renewed → reloading nginx"
        docker compose -f "$ROOT/infra/docker-compose.yml" --env-file "$ROOT/.env" exec -T nginx nginx -s reload
        rm -f "$ROOT/nginx/letsencrypt/.renewed"
    else
        echo "$(ts) no renewals"
    fi

    echo "── $(ts) done ──"
} >> "$LOG" 2>&1
