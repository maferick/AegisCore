"""Structured-log helper — matches counter_intel.log output shape."""

from __future__ import annotations

import json
import logging
import sys
from datetime import datetime, timezone


class _JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload: dict = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname.lower(),
            "logger": record.name,
            "message": record.getMessage(),
        }
        extra = getattr(record, "extra_fields", None)
        if isinstance(extra, dict):
            payload.update(extra)
        return json.dumps(payload, default=str)


_configured = False


def _configure() -> None:
    global _configured
    if _configured:
        return
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(_JsonFormatter())
    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(logging.INFO)
    _configured = True


class _Logger:
    def __init__(self, name: str) -> None:
        self._l = logging.getLogger(name)

    def _log(self, level: int, msg: str, fields: dict | None = None) -> None:
        extra = {"extra_fields": fields} if fields else {}
        self._l.log(level, msg, extra=extra)

    def info(self, msg: str, fields: dict | None = None) -> None:
        self._log(logging.INFO, msg, fields)

    def warning(self, msg: str, fields: dict | None = None) -> None:
        self._log(logging.WARNING, msg, fields)

    def error(self, msg: str, fields: dict | None = None) -> None:
        self._log(logging.ERROR, msg, fields)


def get(name: str) -> _Logger:
    _configure()
    return _Logger(name)
