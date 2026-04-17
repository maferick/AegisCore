"""intel_copilot CLI — ask a question, get a rendered answer.

Three modes:

    python -m intel_copilot ask "most used ship to kill freighters in the last 30 days"
    python -m intel_copilot plan --json '{"intent":"top_n", ...}'
    python -m intel_copilot llm "who killed the most freighters this week"

``ask`` uses the heuristic parser (no LLM dependency) — when it recognises
a question shape the whole pipeline runs offline. ``ask --llm`` (or the
top-level ``llm`` subcommand) falls through to Claude when no heuristic
fires. ``plan`` accepts pre-built JSON, which is how the eventual Laravel
HTTP bridge will call in.
"""

from __future__ import annotations

import argparse
import json
import sys

from intel_copilot.config import Config
from intel_copilot.log import get, setup
from intel_copilot.parser import DictPlanParser, HeuristicPlanParser
from intel_copilot.plan import PlanError, QueryPlan
from intel_copilot.router import Router
from intel_copilot.synth import render

log = get(__name__)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="intel_copilot")
    parser.add_argument("--log-level", default="INFO")
    sub = parser.add_subparsers(dest="cmd", required=True)

    ask = sub.add_parser("ask", help="ask a natural-language question (heuristic first, optional LLM fallback)")
    ask.add_argument("question", nargs="+")
    ask.add_argument("--dry-run", action="store_true", help="print the plan, do not execute")
    ask.add_argument("--llm", action="store_true", help="fall back to Claude when no heuristic template matches")

    llm_cmd = sub.add_parser("llm", help="force the LLM planner (requires ANTHROPIC_API_KEY)")
    llm_cmd.add_argument("question", nargs="+")
    llm_cmd.add_argument("--dry-run", action="store_true")
    llm_cmd.add_argument("--model", default=None)

    plan_cmd = sub.add_parser("plan", help="execute a pre-built plan JSON")
    plan_cmd.add_argument("--json", required=True, help="plan as a JSON string")
    plan_cmd.add_argument("--dry-run", action="store_true")

    args = parser.parse_args(argv)
    setup(args.log_level)

    plan: QueryPlan | None

    if args.cmd == "ask":
        question = " ".join(args.question)
        plan = HeuristicPlanParser().parse(question)
        if plan is None:
            if not args.llm:
                print(
                    "no heuristic template matched — pass --llm or use `llm` / `plan` subcommand",
                    file=sys.stderr,
                )
                return 2
            plan = _llm_parse(question)

    elif args.cmd == "llm":
        plan = _llm_parse(" ".join(args.question), model=args.model)

    else:  # plan
        try:
            plan = DictPlanParser().parse(json.loads(args.json))
        except (json.JSONDecodeError, PlanError) as exc:
            print(f"invalid plan: {exc}", file=sys.stderr)
            return 2

    if args.dry_run:
        print(json.dumps(plan.to_dict(), indent=2))
        return 0

    router = _build_router(Config.from_env())
    result = router.execute(plan)
    print(render(result))
    return 0


def _llm_parse(question: str, *, model: str | None = None) -> QueryPlan:
    """Bridge the CLI to Claude. Surfaces LLM / plan errors as exit 2
    rather than a stack trace — the CLI is the human-facing edge."""
    from intel_copilot.llm import ClaudeLLM, LLMError, LLMPlanParser

    overrides: dict[str, object] = {}
    if model:
        overrides["model"] = model
    try:
        llm = ClaudeLLM.from_env(**overrides)
        return LLMPlanParser(llm).parse(question)
    except (LLMError, PlanError) as exc:
        print(f"llm planner failed: {exc}", file=sys.stderr)
        raise SystemExit(2) from exc


def _build_router(cfg: Config) -> Router:
    router = Router()

    # OpenSearch is always wired up — it is the MVP backend.
    from opensearchpy import OpenSearch  # local import: keeps tests lightweight
    from intel_copilot.executors.opensearch import OpenSearchExecutor

    use_ssl = cfg.opensearch_url.startswith("https")
    client = OpenSearch(
        hosts=[cfg.opensearch_url],
        http_auth=(cfg.opensearch_username, cfg.opensearch_password) if cfg.opensearch_password else None,
        use_ssl=use_ssl,
        verify_certs=cfg.opensearch_verify_certs if use_ssl else False,
        ssl_show_warn=False,
    )
    router.register(OpenSearchExecutor(client, index=cfg.opensearch_index))

    # SQL wiring is optional — the executor is registered only if DB creds
    # are present. Without it, lookup intents will surface RoutingError.
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
