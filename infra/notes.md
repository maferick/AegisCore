# Infra Notes

## Project layout on the host

```
/opt/aegiscore/                  ← project root (git clone)
├── infra/docker-compose.yml
├── docker/                      ← container state (gitignored)
│   ├── mariadb/{data,config,logs}
│   ├── redis/data
│   ├── opensearch/{data,logs}
│   ├── influxdb2/{data,config}
│   ├── neo4j/{data,logs,import,plugins}
│   └── nginx/logs
├── app/                         ← Laravel control plane source
│   └── public/index.php         ← front controller (served by nginx + php-fpm)
├── php/
│   └── conf.d/aegiscore.ini     ← custom php.ini overrides, read-only mount
├── infra/
│   ├── docker-compose.yml
│   └── php/Dockerfile           ← custom php-fpm image (extensions + composer)
├── nginx/
│   ├── conf.d/aegiscore.conf    ← mounted read-only into nginx container
│   └── certs/                   ← TLS material (gitignored)
└── docs/
```

All bind-mount paths in `infra/docker-compose.yml` resolve via
`${AEGISCORE_ROOT:-/opt/aegiscore}`, so you can point the stack at a different
root on dev laptops by setting `AEGISCORE_ROOT` in `.env`.

> ⚠️ **`AEGISCORE_ROOT` is case-sensitive.** It must match the on-disk path
> exactly. If the var is unset (or misspelled) compose silently falls back to
> the default `/opt/aegiscore` and Docker auto-creates an **empty** shadow
> tree at that path, then bind-mounts those empty dirs into every container.
> Symptoms: nginx starts with no config (`default.conf is not a file`), PHP
> sees an empty `/var/www/html`, logs disappear. Check with
> `grep AEGISCORE_ROOT .env` — the value must exactly match the project dir
> casing (e.g. `/opt/AegisCore`, not `/opt/aegiscore`).

## First-time setup

Run `make bootstrap` to create the `docker/*` dirs with correct ownership:

| Service     | Path                                   | UID  |
|-------------|----------------------------------------|------|
| MariaDB     | `$AEGISCORE_ROOT/docker/mariadb`       | 999  |
| Redis       | `$AEGISCORE_ROOT/docker/redis`         | 999  |
| OpenSearch  | `$AEGISCORE_ROOT/docker/opensearch`    | 1000 |
| InfluxDB    | `$AEGISCORE_ROOT/docker/influxdb2`     | 1000 |
| Neo4j       | `$AEGISCORE_ROOT/docker/neo4j`         | 7474 |
| Nginx logs  | `$AEGISCORE_ROOT/docker/nginx/logs`    | root |

## Image pinning
- All images are pinned. Bump intentionally, one image per commit.
- Never commit `.env`.

## Portainer
- Git auto-update target: `infra/docker-compose.yml`.
- Set env vars in the Portainer stack config (or point to `.env`).

## Port exposure
- `80` / `443` — nginx, public.
- `3306` (MariaDB) and `7687` (Neo4j bolt) — bound to `127.0.0.1` only.
- `5601` / `8086` / `9200` / `7474` — exposed on all interfaces for dev. In
  prod, remove these from the compose file and route through nginx.

## Data ownership
MariaDB is canonical. Neo4j / OpenSearch / InfluxDB are derived stores — they
can be rebuilt from MariaDB + external sources. Don't add business logic that
only lives in a derived store.

## OpenSearch security posture (phase 1)
The security plugin is **disabled** on both `opensearch` and
`opensearch-dashboards`:

- `DISABLE_SECURITY_PLUGIN=true` on the opensearch service
- `DISABLE_SECURITY_DASHBOARDS_PLUGIN=true` on the dashboards service
- `DISABLE_INSTALL_DEMO_CONFIG=true` so no self-signed TLS gets generated
- Clients talk plain `http://opensearch:9200` — no auth header, no cert

Why: OpenSearch is only reachable on the internal `aegiscore` Docker network
plus the dev-only host ports. The demo config's self-signed TLS breaks APOC
→ OpenSearch integrations from Neo4j (cert pinning / truststore friction)
and Dashboards with no matching security gain inside the compose network.

Before prod:
1. Put OpenSearch behind nginx with mTLS (or terminate upstream at a
   proper cert-authority-issued cert),
2. Or restore the security plugin + set `OPENSEARCH_ADMIN_PASSWORD` and
   flip all clients back to `https://` + basic auth.

## TLS termination: nginx → php-fpm

nginx terminates TLS at the edge and forwards to php-fpm over plain HTTP,
setting `X-Forwarded-Proto: https`. For Laravel to generate `https://` URLs
(asset links, signed URLs, session cookies with `Secure`), two things must be
true:

1. **`APP_URL` in `.env`** must match the public origin — e.g.
   `APP_URL=https://winterco.killsineve.online`, not `http://localhost`.
   Filament's login page builds asset paths off `APP_URL`; set it wrong and
   you'll get mixed-content blocks and an unstyled login form.
2. **`trustProxies(at: '*')`** is configured in
   `app/bootstrap/app.php` so Laravel reads `X-Forwarded-Proto` and
   `X-Forwarded-Host` from nginx. `at: '*'` is safe here because php-fpm is
   only reachable from the nginx container on the internal bridge network —
   no externally-reachable proxy path exists.

Symptoms of a misconfigured setup: `/admin/login` renders with no CSS,
Livewire JS 404s, and submitting the form reloads the same page without
authenticating (the session cookie never gets set because `SESSION_SECURE`
sees the request as HTTP).

## PHP control plane
- Image: built locally from `infra/php/Dockerfile` (tag
  `aegiscore/php-fpm:0.1.0`). Base is `php:8.4-fpm-alpine` + Laravel-required
  extensions (`pdo_mysql`, `redis`, `intl`, `bcmath`, `gd`, `mbstring`,
  `opcache`, `pcntl`, `sockets`, `zip`) + Composer.
- The php-fpm service sets `pull_policy: build` — compose/Portainer will
  always build from `infra/php/Dockerfile` and never attempt a registry pull.
  Without this, Portainer's auto-pull step fails with
  `pull access denied for aegiscore/php-fpm`.
- Bump the image tag in `infra/docker-compose.yml` whenever the Dockerfile
  changes; rebuild with `make build`. `make pull` uses `--ignore-buildable`
  so it pulls only the registry-hosted images.
- App source: `$AEGISCORE_ROOT/app/`, mounted **read-write** into php-fpm and
  **read-only** into nginx. PHP should not write to `app/` at runtime — any
  writable state goes under `docker/php/` (add a volume when needed).
- PHP overrides live in `$AEGISCORE_ROOT/php/conf.d/*.ini` and are mounted at
  `/usr/local/etc/php/conf.d/aegiscore/` inside the container.
- Backend credentials reach PHP via env vars in the compose file — service
  names (`mariadb`, `redis`, `opensearch`, `influxdb2`, `neo4j`) resolve
  inside the `aegiscore` network.

## Redis
- Image: `redis:7-alpine`, AOF persistence (`appendfsync everysec`).
- Password-protected via `REDIS_PASSWORD` in `.env`. Bound to `127.0.0.1:6379`.
- Memory cap: `REDIS_MAXMEMORY` (default `512mb`) with `allkeys-lru`.
- Used for Laravel cache, sessions, queues, and Horizon. **Not a system of
  record** — anything important lands in MariaDB.
- Password should avoid `$`, `` ` ``, `"`, `\` to stay shell-safe in the
  command + healthcheck.

## Troubleshooting
- **OpenSearch won't start, "config not found":** don't bind-mount
  `/usr/share/opensearch/config` unless you pre-seed it with the image's files.
  We intentionally do not mount that path.
- **Dashboards login loops:** should not happen on phase 1 — the security
  plugin is disabled on both `opensearch` and `opensearch-dashboards`. If
  you re-enable security in prod, set `OPENSEARCH_USERNAME` +
  `OPENSEARCH_PASSWORD` on the dashboards service.
- **Neo4j OOM on small host:** lower `NEO4J_HEAP_*` and `NEO4J_PAGECACHE` in
  `.env`. Dev defaults target a laptop; prod defaults are in the comments.
- **Nginx returns 404 for `/`:** make sure `app/public/index.php` exists and
  `$AEGISCORE_ROOT/app` is mounted — both nginx and php-fpm need to see it.
- **PHP changes don't show up:** OPcache revalidates every 2 seconds in the
  shipped `aegiscore.ini`; wait a moment or `make restart` php-fpm.
- **Redis healthcheck flapping:** confirm `REDIS_PASSWORD` in `.env` doesn't
  contain shell metacharacters (`$`, `` ` ``, `"`, `\`). Rotate if needed.
- **`make bootstrap` fails with permission denied:** the target uses `sudo` on
  purpose because `/opt/aegiscore` is typically root-owned.
- **`make up` rebuilds php-fpm every time:** shouldn't — compose caches by
  image tag. Bump `aegiscore/php-fpm:<version>` in compose when the
  Dockerfile changes and run `make build` explicitly.
- **Portainer / `docker compose pull` fails with `pull access denied for
  aegiscore/php-fpm`:** this is expected — the image is built locally.
  `pull_policy: build` on the php-fpm service prevents the pull attempt. If
  you still see this, you're on an older compose; run `make build` manually
  before `make up` on first deploy.
- **Nginx container shows `unhealthy` but curl works:** busybox `wget`
  resolves `localhost` to IPv6 `::1` before trying IPv4. If the nginx config
  doesn't include `listen [::]:80`, the healthcheck gets `Connection refused`
  from inside the container even though IPv4 works fine. The shipped config
  listens on both stacks and the healthcheck uses `127.0.0.1` explicitly —
  if you edit either, keep that invariant.
- **Empty response from `curl http://localhost/` after a clean boot:** almost
  always a path-casing mismatch between `AEGISCORE_ROOT` and the on-disk
  project dir. See the warning at the top of this file.
- **`/admin/login` has no styling and submitting it does nothing:** Laravel
  isn't trusting the nginx proxy, so it thinks the request is HTTP and
  generates `http://` asset URLs on an HTTPS page — the browser blocks
  them and Livewire never loads. Two fixes, both required:
  1. Set `APP_URL` in `.env` to the public origin (e.g.
     `APP_URL=https://winterco.killsineve.online`).
  2. Verify `trustProxies(at: '*')` is still in `app/bootstrap/app.php`.
  Then `make artisan CMD="config:clear"` and hard-reload the browser.
- **`/admin` loads but icons/CSS 404 after a fresh deploy:** Filament's
  asset bundle didn't get published into `app/public/`. This should be
  automatic (composer's `post-autoload-dump` runs `filament:upgrade`), but
  if it got skipped, run `make laravel-install` — it publishes assets and
  creates the `storage/app/public` symlink.
