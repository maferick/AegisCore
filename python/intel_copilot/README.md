# intel_copilot

Natural-language query + reasoning layer over EVE data. See
[ADR-0007](../../docs/adr/0007-intel-copilot-rag-broker.md) for the design
rationale.

## Pipeline

```
question → parser → QueryPlan → router → executor → synth → answer
```

- **`plan.py`** — the IR. Typed dataclasses for `Intent`, `EntityRef`,
  `TimeWindow`, etc. `QueryPlan.validate()` is the only validation
  callers need.
- **`contracts.py`** — the routing table. One row per intent. Adding an
  intent means editing this file and registering an executor.
- **`router.py`** — the broker. Validates the plan and dispatches to the
  first registered executor for its intent.
- **`executors/opensearch.py`** — translates plans into OpenSearch DSL
  against the `killmails` index (see `killmail_search/index.py`).
- **`executors/sql.py`** — phase-1 stub: `LOOKUP` against
  `characters` / `corporations` / `alliances`.
- **`parser.py`** — `DictPlanParser` (LLM-emitted JSON → plan) and
  `HeuristicPlanParser` (narrow rule-based, for tests + offline demos).
- **`synth.py`** — deterministic templates that turn a `ResultSet` into
  an English answer.

## Running

```bash
# Heuristic parser, no external deps needed for dry-run:
python -m intel_copilot ask "most used ship to kill freighters in the last 30 days" --dry-run

# Execute against live OpenSearch (requires OPENSEARCH_* env vars):
python -m intel_copilot ask "how many kills in the last 24 hours"

# Execute an LLM-emitted plan directly:
python -m intel_copilot plan --json '{"intent":"top_n","subject":{"role":"attacker","entity_type":"ship_type"},"filters":[{"role":"victim","entity_type":"ship_group","value":"Freighter"}],"time_window":{"from":"now-30d","to":"now"}}'
```

## Tests

Stdlib `unittest`, no external services required:

```bash
cd python && python -m unittest discover -s intel_copilot -p 'test_*.py' -v
```
