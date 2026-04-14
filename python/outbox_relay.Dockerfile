# syntax=docker/dockerfile:1.7
#
# outbox_relay — Python 3.12 slim image.
#
# Long-lived container that polls the MariaDB `outbox` table for
# market.* events, runs the registered projector for each, and
# writes derived points to InfluxDB. Sibling to sde_importer,
# graph_universe_sync, market_poller, market_importer; kept as a
# separate image so its (small) deps don't bloat the others.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# Install requirements first so the layer caches independently of
# code changes. influxdb-client is the largest dep (~3MB wheel +
# urllib3 + certifi + reactivex transitives); total install ~30MB.
COPY requirements-outbox-relay.txt ./
RUN pip install -r requirements-outbox-relay.txt

# Then the package itself.
COPY outbox_relay ./outbox_relay

# Unprivileged runtime user. Compose service overrides `user:` to
# match the host UID when needed.
RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "outbox_relay"]
