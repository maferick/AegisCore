#!/bin/sh
# AegisCore php-fpm entrypoint.
#
# Runs as root on container start, before php-fpm drops to www-data.
# Fixes ownership on Laravel's writable dirs (storage/ + bootstrap/cache/)
# because the host bind-mount brings in whatever ownership the host
# checkout has — typically root from `git clone`, which www-data can't
# write to. Without this, Blade's compiled-view writer crashes with
# `tempnam(): file created in the system's temporary directory` and
# the request 500s.
#
# Idempotent: if ownership is already correct, chown is a no-op.
# Errors (e.g. dirs not present yet on first boot) are swallowed so
# the container still comes up and the operator can `make laravel-install`.
set -eu

for dir in /var/www/html/storage /var/www/html/bootstrap/cache; do
    if [ -d "$dir" ]; then
        chown -R www-data:www-data "$dir" 2>/dev/null || true
    fi
done

# Hand off to the upstream php image's entrypoint so things like
# `docker-php-ext-*` env handling still run.
exec docker-php-entrypoint "$@"
