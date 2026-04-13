# AegisCore

Human-first alliance intelligence platform with 4 pillars:
- Spy Detection
- Buyall & Doctrines
- Killmails & Battle Theaters
- Unified Users/Characters

See [`AGENTS.md`](AGENTS.md) for the project index and principles.

## Stack
- PHP control plane (php-fpm 8.4-alpine)
- Python execution plane (to land in a later phase)
- MariaDB (canonical), Neo4j, OpenSearch, InfluxDB
- Nginx reverse proxy (front door + PHP fastcgi)

## Quick start

Clone into the project root (default: `/opt/aegiscore`) and prepare env:

```bash
cp .env.example .env
# edit .env and replace every CHANGE_ME value
```

Create the persistent data directories with correct ownership (one-time):

```bash
make bootstrap
```

Bring the stack up:

```bash
make up
```

## Verify

| Service                | URL                         | Notes                        |
|------------------------|-----------------------------|------------------------------|
| Nginx (front door)     | http://localhost/           | serves `app/public/` via PHP |
| Nginx health           | http://localhost/health     | returns `ok`                 |
| MariaDB                | `127.0.0.1:3306`            | localhost only               |
| OpenSearch             | https://localhost:9200      | self-signed cert             |
| OpenSearch Dashboards  | http://localhost:5601       |                              |
| InfluxDB               | http://localhost:8086       |                              |
| Neo4j Browser          | http://localhost:7474       |                              |
| Neo4j Bolt             | `127.0.0.1:7687`            | localhost only               |

## Common commands
- `make up` — start everything
- `make down` — stop everything
- `make restart` — restart all services
- `make ps` — list services + status
- `make logs` — tail all logs
- `make logs-neo4j` — tail a single service (swap name)
- `make pull` — pull latest pinned images
- `make bootstrap` — create `/opt/aegiscore/docker/*` with correct ownership
- `make php-shell` — open a shell in the `php-fpm` container
