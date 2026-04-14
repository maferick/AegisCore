"""Structured-ish logging — copy of sde_importer/log.py, graph_universe_sync/log.py,
and market_poller/log.py.

Kept intentionally duplicated rather than shared via a sibling package:
the one-shot containers ship as separate images with separate
requirements files, and promoting this to `python/_common/` would
cross-couple their Dockerfile layers for no real payoff. Four copies
now; if a fifth lands, promote to `python/_common/` and flip everyone
to import from there.
"""

from __future__ import annotations

import logging
import sys
from typing import Any


_LOGRECORD_RESERVED = frozenset({
    "name", "msg", "args", "levelname", "levelno", "pathname", "filename",
    "module", "exc_info", "exc_text", "stack_info", "lineno", "funcName",
    "created", "msecs", "relativeCreated", "thread", "threadName",
    "processName", "process", "message", "asctime", "taskName",
})

_STDLIB_PASSTHROUGH = frozenset({"exc_info", "stack_info", "stacklevel", "extra"})


class _KvFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        base = super().format(record)
        extras = {
            k: v for k, v in record.__dict__.items()
            if k not in _LOGRECORD_RESERVED and not k.startswith("_")
        }
        if extras:
            suffix = " ".join(f"{k}={_fmt(v)}" for k, v in extras.items())
            return f"{base} {suffix}"
        return base


def _fmt(v: Any) -> str:
    s = str(v)
    return f'"{s}"' if " " in s else s


class _KvLogger:
    __slots__ = ("_logger",)

    def __init__(self, logger: logging.Logger) -> None:
        self._logger = logger

    def _emit(self, level: int, msg: str, kwargs: dict) -> None:
        passthrough = {k: kwargs.pop(k) for k in list(kwargs) if k in _STDLIB_PASSTHROUGH}
        extra = passthrough.pop("extra", None) or {}
        for k, v in kwargs.items():
            safe_key = f"{k}_" if k in _LOGRECORD_RESERVED else k
            extra[safe_key] = v
        self._logger.log(level, msg, extra=extra, **passthrough)

    def debug(self, msg: str, **kwargs: Any) -> None: self._emit(logging.DEBUG, msg, kwargs)
    def info(self, msg: str, **kwargs: Any) -> None: self._emit(logging.INFO, msg, kwargs)
    def warning(self, msg: str, **kwargs: Any) -> None: self._emit(logging.WARNING, msg, kwargs)
    def error(self, msg: str, **kwargs: Any) -> None: self._emit(logging.ERROR, msg, kwargs)

    def exception(self, msg: str, **kwargs: Any) -> None:
        kwargs.setdefault("exc_info", True)
        self._emit(logging.ERROR, msg, kwargs)


def setup(level: str = "INFO") -> None:
    root = logging.getLogger()
    root.setLevel(level)
    for h in list(root.handlers):
        root.removeHandler(h)

    handler = logging.StreamHandler(sys.stderr)
    handler.setFormatter(_KvFormatter("%(asctime)s %(levelname)-5s %(name)s: %(message)s"))
    root.addHandler(handler)


def get(name: str) -> _KvLogger:
    return _KvLogger(logging.getLogger(name))
