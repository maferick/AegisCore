"""SQL (MariaDB) executor — stub for lookups + temporal-truth queries.

The OpenSearch executor handles denormalized aggregations. This one is for
questions that need the canonical source: temporal character/corp/alliance
history, valuation provenance, outbox state, anything where the index would
lie because it was written at a different time than the truth.

Phase-1 scope is explicitly narrow. ``LOOKUP`` is the only intent wired up,
and only for the ``CHARACTER`` / ``CORPORATION`` / ``ALLIANCE`` entity
types. Anything broader is deferred until we know what the Laravel side
actually wants to ask — designing speculative SQL surface area here would
just invite drift.
"""

from __future__ import annotations

import time
from typing import Any

from intel_copilot.contracts import Backend
from intel_copilot.executors.base import Executor, ResultRow, ResultSet
from intel_copilot.log import get
from intel_copilot.plan import EntityType, Intent, PlanError, QueryPlan

log = get(__name__)


class SQLExecutor:
    """MariaDB-backed executor for the narrow lookup set (phase 1).

    ``connection`` is any PEP-249-compatible connection (pymysql,
    mysqlclient, …). The executor never commits — all queries are SELECTs,
    and callers own connection lifecycle.
    """

    backend = Backend.SQL

    def __init__(self, connection: Any) -> None:
        self._conn = connection

    def execute(self, plan: QueryPlan) -> ResultSet:
        if plan.intent is not Intent.LOOKUP:
            raise PlanError(
                f"SQL executor phase-1 only handles intent=lookup; got {plan.intent.value}"
            )
        if plan.subject is None:
            raise PlanError("lookup requires a subject")

        sql, params = self._build_sql(plan)
        t0 = time.monotonic()
        with self._conn.cursor() as cur:
            cur.execute(sql, params)
            fetched = cur.fetchall()
        took_ms = int((time.monotonic() - t0) * 1000)

        rows = tuple(
            ResultRow(label=_row_label(r), value=1, meta=_row_meta(r))
            for r in fetched
        )
        return ResultSet(
            backend=self.backend,
            plan=plan,
            rows=rows,
            total=len(rows),
            took_ms=took_ms,
            query={"sql": sql, "params": params},
        )

    # ------------------------------------------------------------------ #
    # Query translation
    # ------------------------------------------------------------------ #

    def _build_sql(self, plan: QueryPlan) -> tuple[str, tuple[Any, ...]]:
        assert plan.subject is not None
        et = plan.subject.entity_type

        # Table + id column per entity type. Adding a new entity type means
        # adding a row here — there is no auto-discovery on purpose, to
        # keep the blast radius of this executor small and auditable.
        table_for: dict[EntityType, tuple[str, str, str]] = {
            EntityType.CHARACTER: ("characters", "character_id", "name"),
            EntityType.CORPORATION: ("corporations", "corporation_id", "name"),
            EntityType.ALLIANCE: ("alliances", "alliance_id", "name"),
        }
        if et not in table_for:
            raise PlanError(f"SQL lookup does not handle entity_type={et.value}")

        table, id_col, name_col = table_for[et]

        # Identifiers come from the closed ``table_for`` map, never from
        # user input — safe to interpolate. Values are always parameterized.
        if plan.subject.value_id is not None:
            where = f"`{id_col}` = %s"
            params: tuple[Any, ...] = (plan.subject.value_id,)
        elif plan.subject.value is not None:
            where = f"`{name_col}` = %s"
            params = (plan.subject.value,)
        else:
            raise PlanError("lookup subject must carry value or value_id")

        sql = (
            f"SELECT `{id_col}` AS id, `{name_col}` AS name "
            f"FROM `{table}` WHERE {where} LIMIT %s"
        )
        return sql, params + (plan.limit,)


def _row_label(row: Any) -> str:
    if isinstance(row, dict):
        return str(row.get("name") or row.get("id"))
    # Tuple cursor — (id, name)
    return str(row[1] if len(row) > 1 else row[0])


def _row_meta(row: Any) -> dict[str, Any]:
    if isinstance(row, dict):
        return dict(row)
    return {"id": row[0], "name": row[1] if len(row) > 1 else None}
