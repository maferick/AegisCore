# syntax=docker/dockerfile:1.7
#
# market_poller — Python 3.12 slim image.
#
# Long-running-ish container (`make market-poll`) that runs one pass
# per invocation: walks enabled market_watched_locations, pulls order
# books from ESI, bulk-inserts into market_orders, emits an outbox
# event per location. Cadence lives outside this image (cron, systemd
# timer, scheduler container — operator's call).
#
# Sibling to sde_importer + graph_universe_sync; kept as a separate
# image so its httpx + pymysql + ulid deps don't bloat the other
# one-shots (they pull a superset but ship separately).

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# Install requirements first so the layer caches independently of code
# changes. Total install ~15MB.
COPY requirements-market.txt ./
RUN pip install -r requirements-market.txt

# Then the package itself.
COPY market_poller ./market_poller

# Unprivileged runtime user. Compose service overrides `user:` to match
# the host UID when needed.
RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "market_poller"]
