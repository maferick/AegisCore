"""HTTP bridge — exposes the broker to Laravel over a small JSON API.

Why stdlib ``http.server`` instead of Flask / FastAPI: the surface area is
three endpoints, request volume is bounded by human typing speed, and the
broker already owns its own threading model (each executor holds its own
DB / OpenSearch client). Pulling in a framework would be scope creep.

Endpoints
---------

``GET  /healthz``
    Cheap liveness check. Returns ``{"ok": true, "backends": [...]}`` so the
    Laravel side can decide whether to render the chat input enabled.

``POST /ask``
    Body: ``{"question": str, "use_llm": bool = false, "dry_run": bool = false}``
    Runs the heuristic parser; falls through to Claude when ``use_llm`` is
    true and the heuristic didn't match. Returns the plan + result set.

``POST /plan``
    Body: a raw QueryPlan dict (what an LLM-driven front-end would already
    have on hand). Skips the parser, validates + executes.

Auth
----

A shared secret is required on every non-health request via the
``X-Intel-Copilot-Token`` header when ``INTEL_COPILOT_API_TOKEN`` is set
in the server's env. Missing or empty token disables auth (dev-only —
production compose always sets one). Docker-network isolation is the
outer ring; the header is the inner ring against accidental exposure.
"""

from __future__ import annotations

import json
import os
import sys
import threading
import traceback
from dataclasses import asdict, is_dataclass
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Callable

from intel_copilot.cache import ResultCache
from intel_copilot.config import Config
from intel_copilot.contracts import Backend, RoutingError
from intel_copilot.log import get, setup
from intel_copilot.parser import DictPlanParser, HeuristicPlanParser
from intel_copilot.plan import PlanError, QueryPlan
from intel_copilot.router import Router

log = get(__name__)


DEFAULT_HOST = "0.0.0.0"
DEFAULT_PORT = 8000
TOKEN_HEADER = "X-Intel-Copilot-Token"
TOKEN_ENV = "INTEL_COPILOT_API_TOKEN"


class IntelCopilotServer:
    """Stdlib-backed HTTP server wrapping a pre-built ``Router``.

    The router is injected so tests can pass a stub with only the backends
    they want to exercise. ``build_production_server`` constructs the real
    thing (OpenSearch + optional SQL + optional Claude planner).
    """

    def __init__(
        self,
        router: Router,
        *,
        llm_planner_factory: Callable[[], Any] | None = None,
        api_token: str | None = None,
        cache: ResultCache | None = None,
    ) -> None:
        self._router = router
        self._llm_planner_factory = llm_planner_factory
        self._api_token = api_token or os.environ.get(TOKEN_ENV) or None
        self._llm_parser: Any = None  # lazy — don't dial Anthropic on boot
        self._cache = cache or ResultCache(None)

    # ------------------------------------------------------------------ #
    # Request handlers — return (status, body_dict). The handler class
    # below translates those to a serialised response.
    # ------------------------------------------------------------------ #

    def handle_health(self) -> tuple[int, dict[str, Any]]:
        return HTTPStatus.OK, {
            "ok": True,
            "backends": [b.value for b in self._router.backends()],
            "llm": self._llm_planner_factory is not None,
            "cache": self._cache.enabled,
        }

    def handle_ask(self, body: dict[str, Any]) -> tuple[int, dict[str, Any]]:
        question = body.get("question")
        if not isinstance(question, str) or not question.strip():
            return HTTPStatus.BAD_REQUEST, {"error": "question is required"}

        dry_run = bool(body.get("dry_run"))
        use_llm = bool(body.get("use_llm"))
        # ``use_cache`` defaults true — callers opt out per-request when
        # they want a fresh computation (debug, "refresh" button).
        use_cache = body.get("use_cache", True) is not False

        plan = HeuristicPlanParser().parse(question)
        parser_used = "heuristic"

        if plan is None:
            if not use_llm:
                return HTTPStatus.UNPROCESSABLE_ENTITY, {
                    "error": "no heuristic template matched",
                    "hint": "set use_llm=true to fall back to Claude",
                }
            plan = self._llm_plan(question)
            parser_used = "llm"

        if dry_run:
            return HTTPStatus.OK, {
                "parser": parser_used,
                "plan": plan.to_dict(),
                "result": None,
            }

        return self._execute(plan, parser_used, use_cache=use_cache)

    def handle_plan(self, body: dict[str, Any]) -> tuple[int, dict[str, Any]]:
        try:
            plan = DictPlanParser().parse(body)
        except PlanError as exc:
            return HTTPStatus.UNPROCESSABLE_ENTITY, {"error": f"invalid plan: {exc}"}
        dry_run = bool(body.get("dry_run"))
        use_cache = body.get("use_cache", True) is not False
        if dry_run:
            return HTTPStatus.OK, {"parser": "dict", "plan": plan.to_dict(), "result": None}
        return self._execute(plan, "dict", use_cache=use_cache)

    # ------------------------------------------------------------------ #
    # Internals
    # ------------------------------------------------------------------ #

    def _execute(
        self,
        plan: QueryPlan,
        parser_used: str,
        *,
        use_cache: bool = True,
    ) -> tuple[int, dict[str, Any]]:
        # Cache read is keyed by the plan only — the parser that produced
        # it (heuristic vs llm vs dict) can't change the answer and would
        # only cause cache misses. The parser field is stamped on the
        # cached payload before store so the UI still reflects how the
        # plan got built when a hit serves it.
        if use_cache:
            cached = self._cache.get(plan)
            if cached is not None:
                cached["cache"] = "hit"
                return HTTPStatus.OK, cached

        try:
            result = self._router.execute(plan)
        except RoutingError as exc:
            return HTTPStatus.UNPROCESSABLE_ENTITY, {"error": str(exc), "plan": plan.to_dict()}
        except PlanError as exc:
            return HTTPStatus.UNPROCESSABLE_ENTITY, {"error": str(exc), "plan": plan.to_dict()}

        payload = {
            "parser": parser_used,
            "plan": plan.to_dict(),
            "result": _result_to_dict(result),
            "cache": "miss" if use_cache else "bypass",
        }
        if use_cache:
            self._cache.put(plan, payload)
        return HTTPStatus.OK, payload

    def _llm_plan(self, question: str) -> QueryPlan:
        """Lazy-build the LLM parser so the broker boots without
        ANTHROPIC_API_KEY when the factory's never called."""
        if self._llm_planner_factory is None:
            raise PlanError("LLM planner not configured on this broker")
        if self._llm_parser is None:
            self._llm_parser = self._llm_planner_factory()
        return self._llm_parser.parse(question)

    # ------------------------------------------------------------------ #
    # Lifecycle
    # ------------------------------------------------------------------ #

    def serve(self, host: str = DEFAULT_HOST, port: int = DEFAULT_PORT) -> None:
        handler = _make_handler(self)
        httpd = ThreadingHTTPServer((host, port), handler)
        log.info("intel_copilot listening", host=host, port=port,
                 auth="token" if self._api_token else "open")
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            log.info("shutdown requested")
        finally:
            httpd.server_close()

    # Exposed so tests can hit handle_* without spinning a socket.
    @property
    def api_token(self) -> str | None:
        return self._api_token


# ---------------------------------------------------------------------- #
# Serialisation helpers
# ---------------------------------------------------------------------- #

def _result_to_dict(result: Any) -> dict[str, Any]:
    """Convert a ``ResultSet`` into a wire-friendly dict.

    ``rows`` become a list of ``{label, value, meta}`` dicts. ``plan`` is
    already round-trippable via ``QueryPlan.to_dict``; the executor's
    backend-specific query dump is left attached so the Laravel side can
    show it in an "advanced" toggle.
    """
    return {
        "backend": result.backend.value if isinstance(result.backend, Backend) else str(result.backend),
        "rows": [_row_to_dict(r) for r in result.rows],
        "total": result.total,
        "took_ms": result.took_ms,
        "query": result.query,
    }


def _row_to_dict(row: Any) -> dict[str, Any]:
    if is_dataclass(row) and not isinstance(row, type):
        return asdict(row)
    return dict(row)


# ---------------------------------------------------------------------- #
# Request handler — kept as a factory closure so the server instance
# can be captured in the class without subclassing gymnastics.
# ---------------------------------------------------------------------- #

def _make_handler(server: IntelCopilotServer) -> type[BaseHTTPRequestHandler]:
    class Handler(BaseHTTPRequestHandler):
        # Silence the stdlib access log — we emit our own structured one.
        def log_message(self, fmt: str, *args: Any) -> None:  # noqa: A003
            return

        def do_GET(self) -> None:  # noqa: N802 — stdlib hook name
            if self.path.rstrip("/") == "/healthz":
                _send_json(self, *server.handle_health())
                return
            _send_json(self, HTTPStatus.NOT_FOUND, {"error": "not found"})

        def do_POST(self) -> None:  # noqa: N802
            if not _check_auth(self, server):
                return
            body = _read_json(self)
            if body is None:
                return  # error already written

            path = self.path.rstrip("/")
            try:
                if path == "/ask":
                    status, payload = server.handle_ask(body)
                elif path == "/plan":
                    status, payload = server.handle_plan(body)
                else:
                    status, payload = HTTPStatus.NOT_FOUND, {"error": "not found"}
            except Exception as exc:  # noqa: BLE001 — top-level handler
                log.error("unhandled server error", err=str(exc), tb=traceback.format_exc())
                status, payload = HTTPStatus.INTERNAL_SERVER_ERROR, {"error": "internal error"}

            _send_json(self, status, payload)

    return Handler


def _check_auth(handler: BaseHTTPRequestHandler, server: IntelCopilotServer) -> bool:
    if server.api_token is None:
        return True
    provided = handler.headers.get(TOKEN_HEADER)
    if provided == server.api_token:
        return True
    _send_json(handler, HTTPStatus.UNAUTHORIZED, {"error": "auth required"})
    return False


def _read_json(handler: BaseHTTPRequestHandler) -> dict[str, Any] | None:
    length = int(handler.headers.get("Content-Length") or 0)
    if length <= 0:
        _send_json(handler, HTTPStatus.BAD_REQUEST, {"error": "empty body"})
        return None
    raw = handler.rfile.read(length)
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        _send_json(handler, HTTPStatus.BAD_REQUEST, {"error": f"invalid json: {exc}"})
        return None
    if not isinstance(parsed, dict):
        _send_json(handler, HTTPStatus.BAD_REQUEST, {"error": "body must be a JSON object"})
        return None
    return parsed


def _send_json(handler: BaseHTTPRequestHandler, status: int, payload: dict[str, Any]) -> None:
    body = json.dumps(payload, default=str).encode("utf-8")
    handler.send_response(status)
    handler.send_header("Content-Type", "application/json; charset=utf-8")
    handler.send_header("Content-Length", str(len(body)))
    handler.end_headers()
    handler.wfile.write(body)


# ---------------------------------------------------------------------- #
# Production boot
# ---------------------------------------------------------------------- #

def build_production_server(cfg: Config | None = None) -> IntelCopilotServer:
    """Compose a router + LLM planner factory from env-backed config.

    Never called from tests — tests construct ``IntelCopilotServer``
    directly with stub executors.
    """
    cfg = cfg or Config.from_env()
    router = _build_router(cfg)
    from intel_copilot.cache import from_env as cache_from_env

    return IntelCopilotServer(
        router,
        llm_planner_factory=_build_llm_factory(),
        cache=cache_from_env(),
    )


def _build_router(cfg: Config) -> Router:
    from opensearchpy import OpenSearch
    from intel_copilot.executors.opensearch import OpenSearchExecutor

    router = Router()

    use_ssl = cfg.opensearch_url.startswith("https")
    client = OpenSearch(
        hosts=[cfg.opensearch_url],
        http_auth=(cfg.opensearch_username, cfg.opensearch_password) if cfg.opensearch_password else None,
        use_ssl=use_ssl,
        verify_certs=cfg.opensearch_verify_certs if use_ssl else False,
        ssl_show_warn=False,
    )
    router.register(OpenSearchExecutor(client, index=cfg.opensearch_index))

    if cfg.db_host and cfg.db_database and cfg.db_username:
        import pymysql
        import pymysql.cursors
        from intel_copilot.executors.sql import SQLExecutor

        conn = pymysql.connect(
            host=cfg.db_host,
            port=cfg.db_port,
            user=cfg.db_username,
            password=cfg.db_password or "",
            database=cfg.db_database,
            charset="utf8mb4",
            autocommit=True,
            cursorclass=pymysql.cursors.DictCursor,
        )
        router.register(SQLExecutor(conn))

    return router


def _build_llm_factory() -> Callable[[], Any] | None:
    """Returns a zero-arg factory that produces the LLM parser, or None
    when the API key isn't configured. Lazy so server boot doesn't need
    the SDK installed."""
    if not os.environ.get("ANTHROPIC_API_KEY"):
        return None

    def factory() -> Any:
        from intel_copilot.llm import ClaudeLLM, LLMPlanParser

        return LLMPlanParser(ClaudeLLM.from_env())

    return factory


# ---------------------------------------------------------------------- #
# Entrypoint: `python -m intel_copilot.server`
# ---------------------------------------------------------------------- #

def _cli_main(argv: list[str] | None = None) -> int:
    import argparse

    parser = argparse.ArgumentParser(prog="intel_copilot.server")
    parser.add_argument("--host", default=os.environ.get("INTEL_COPILOT_HOST", DEFAULT_HOST))
    parser.add_argument("--port", type=int,
                        default=int(os.environ.get("INTEL_COPILOT_PORT", str(DEFAULT_PORT))))
    parser.add_argument("--log-level", default="INFO")
    args = parser.parse_args(argv)
    setup(args.log_level)

    server = build_production_server()
    server.serve(host=args.host, port=args.port)
    return 0


if __name__ == "__main__":
    sys.exit(_cli_main())
