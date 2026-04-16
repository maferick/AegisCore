"""Tiny structured-log helper — mirrors killmail_ingest.log style so
operator output reads the same across Python workers."""

from __future__ import annotations

import json
import logging
import sys
from typing import Any


_configured = False


def _configure() -> None:
    global _configured
    if _configured:
        return
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(logging.Formatter(
        "%(asctime)s %(levelname)s %(name)s %(message)s",
        datefmt="%Y-%m-%dT%H:%M:%S",
    ))
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    root.addHandler(handler)
    _configured = True


class Logger:
    def __init__(self, name: str) -> None:
        _configure()
        self._log = logging.getLogger(name)

    def _emit(self, level: int, msg: str, **kv: Any) -> None:
        if kv:
            self._log.log(level, "%s %s", msg, json.dumps(kv, default=str))
        else:
            self._log.log(level, "%s", msg)

    def info(self, msg: str, **kv: Any) -> None:
        self._emit(logging.INFO, msg, **kv)

    def warning(self, msg: str, **kv: Any) -> None:
        self._emit(logging.WARNING, msg, **kv)

    def error(self, msg: str, **kv: Any) -> None:
        self._emit(logging.ERROR, msg, **kv)


def get(name: str) -> Logger:
    return Logger(name)
