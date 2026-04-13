# syntax=docker/dockerfile:1.7
#
# sde_importer — Python 3.12 slim image.
#
# Runs as a one-shot container (docker compose run --rm sde_importer),
# not a long-lived service. Keeps the image tiny: no build tools, no
# dev shell goodies, no Laravel. Everything it needs is pymysql +
# httpx + python-ulid from a tiny requirements.txt.

FROM python:3.12-slim AS base

# Non-interactive, no bytecode on disk (container is ephemeral), no pip
# version warnings cluttering logs, no cache to inflate the image.
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# A predictable home for the code. Keeps paths the same in local dev
# and in prod so log lines read the same everywhere.
WORKDIR /app

# Install requirements first so the layer caches independently of code
# changes. ~20MB of wheels; no compile step needed for our deps.
COPY requirements.txt ./
RUN pip install -r requirements.txt

# Then the package itself.
COPY sde_importer ./sde_importer

# Unprivileged runtime user. The mounted infra/sde volume needs to be
# writable, so the compose service sets `user:` to match the host UID.
RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "sde_importer"]
