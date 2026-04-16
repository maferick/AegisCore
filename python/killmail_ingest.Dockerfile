# syntax=docker/dockerfile:1.7
#
# killmail_ingest — Python 3.12 slim image.
#
# Two modes via subcommand:
#   backfill  — EVE Ref historical archive import (one-shot or loop)
#   stream    — R2Z2 live killmail stream (long-running)

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-killmail-ingest.txt ./
RUN pip install -r requirements-killmail-ingest.txt

COPY killmail_ingest ./killmail_ingest

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "killmail_ingest"]
