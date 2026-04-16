# syntax=docker/dockerfile:1.7
#
# killmail_search — OpenSearch killmail indexer.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-killmail-search.txt ./
RUN pip install -r requirements-killmail-search.txt

COPY killmail_search ./killmail_search

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "killmail_search"]
