# syntax=docker/dockerfile:1.7
#
# market_importer — Python 3.12 slim image.
#
# One-shot container (`make market-import`) that pulls EVE Ref's
# daily market-history CSV dumps from data.everef.net, reconciles
# them against the local `market_history` table via totals.json, and
# bulk-upserts every missing or partial day. Sibling to sde_importer,
# graph_universe_sync, and market_poller; kept as a separate image so
# its (small) deps don't bloat the others.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# Install requirements first so the layer caches independently of code
# changes. Total install ~15MB.
COPY requirements-market-import.txt ./
RUN pip install -r requirements-market-import.txt

# Then the package itself.
COPY market_importer ./market_importer

# Unprivileged runtime user. Compose service overrides `user:` to match
# the host UID when needed.
RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "market_importer"]
