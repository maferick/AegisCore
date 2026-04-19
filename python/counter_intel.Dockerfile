# syntax=docker/dockerfile:1.7
#
# counter_intel — Python 3.12 slim image for the Counter-Intel Dossier
# pipeline. Commit-sequence subcommands:
#   features → MariaDB feature-table compute (this image)
#   projection / similarity / anomalies → added in later commits.
#
# One-shot per invocation, scheduler container invokes it.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-counter-intel.txt ./
RUN pip install -r requirements-counter-intel.txt

COPY counter_intel ./counter_intel

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "counter_intel"]
