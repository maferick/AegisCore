"""Structured-ish logging setup. No third-party structlog — keep deps small.

Output goes to stderr as one line per event, key=value pairs after the message.
Good enough for `docker compose logs sde_importer` and grep workflows; can
upgrade to JSON logging later if we ship these to OpenSearch.

Callers use the wrapper returned by `get(name)`:

    log = get(__name__)
    log.info("downloading SDE", url=source_url, bytes=n)

kwargs are promoted to `extra={}` so the stdlib Logger accepts them and
`_KvFormatter` can append them as `key=value` after the message. Using
the raw `logging.Logger` directly wouldn't work — stdlib rejects unknown
kwargs with `TypeError: Logger._log() got an unexpected keyword
argument`.
"""

from __future__ import annotations

import logging
import sys
from typing import Any


# Names that `logging.LogRecord` owns — passing them via `extra` raises
# "KeyError: Attempt to overwrite '<name>' in LogRecord". We rename any
# collisions rather than crash, so a caller's `log.info(..., name=x)`
# still makes it to the log as `name_=x`.
_LOGRECORD_RESERVED = frozenset({
    "name", "msg", "args", "levelname", "levelno", "pathname", "filename",
    "module", "exc_info", "exc_text", "stack_info", "lineno", "funcName",
    "created", "msecs", "relativeCreated", "thread", "threadName",
    "processName", "process", "message", "asctime", "taskName",
})

# kwargs the stdlib Logger itself accepts; these pass through to
# `Logger._log()` instead of becoming extras.
_STDLIB_PASSTHROUGH = frozenset({"exc_info", "stack_info", "stacklevel", "extra"})


class _KvFormatter(logging.Formatter):
    """Append `key=value` pairs from record.extras after the message."""

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
    """Stdlib Logger wrapper that turns arbitrary kwargs into `extra={}`.

    Keeps the call sites clean (`log.info("msg", key=val)`) without
    forcing everyone to write `log.info("msg", extra={"key": val})`.
    """

    __slots__ = ("_logger",)

    def __init__(self, logger: logging.Logger) -> None:
        self._logger = logger

    def _emit(self, level: int, msg: str, kwargs: dict) -> None:
        # Let stdlib-meaningful kwargs pass through untouched; everything
        # else becomes an extra. Rename any name that would collide with
        # LogRecord internals so we never crash over a bad key name.
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
        # `exception()` is `error()` + `exc_info=True`; preserve that.
        kwargs.setdefault("exc_info", True)
        self._emit(logging.ERROR, msg, kwargs)


def setup(level: str = "INFO") -> None:
    root = logging.getLogger()
    root.setLevel(level)
    # Clear any handlers set by imports (e.g. urllib3) so we own stderr.
    for h in list(root.handlers):
        root.removeHandler(h)

    handler = logging.StreamHandler(sys.stderr)
    handler.setFormatter(_KvFormatter("%(asctime)s %(levelname)-5s %(name)s: %(message)s"))
    root.addHandler(handler)


def get(name: str) -> _KvLogger:
    return _KvLogger(logging.getLogger(name))
