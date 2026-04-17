"""SQL (MariaDB) executor — narrow lookup against the canonical names table.

OpenSearch handles denormalized aggregations. This one is for questions that
need the canonical source: "who / what is this ID?" (and, once phase 2
needs it, temporal character/corp/alliance affiliation history, valuation
provenance, outbox state — anywhere the index would lie because it was
written at a different time than the truth).

Phase-1 scope is explicitly narrow. ``LOOKUP`` is the only intent wired up,
and only for the ``CHARACTER`` / ``CORPORATION`` / ``ALLIANCE`` entity
types.

Names source is ``esi_entity_names(entity_id, name, category, cached_at)``
— a unified cache populated by ``EsiNameResolver`` on the Laravel side.
No separate ``corporations`` / ``alliances`` tables exist; ``characters``
is scoped to SSO-linked user characters only, so it's the wrong store for
arbitrary-pilot lookups. Using ``esi_entity_names`` with a
``category`` filter keeps the executor correct for every EVE entity the
platform has ever touched.
"""

from __future__ import annotations

import time
from typing import Any

from intel_copilot.contracts import Backend
from intel_copilot.executors.base import Executor, ResultRow, ResultSet
from intel_copilot.log import get
from intel_copilot.plan import EntityType, Intent, PlanError, QueryPlan

log = get(__name__)


# EntityType → category value stored in esi_entity_names.category.
# Additions to this map are the only thing needed to widen LOOKUP support
# to a new entity kind; the rest of the builder is category-agnostic.
_CATEGORY_FOR: dict[EntityType, str] = {
    EntityType.CHARACTER: "character",
    EntityType.CORPORATION: "corporation",
    EntityType.ALLIANCE: "alliance",
}


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
        """Assemble a parameterised SELECT against ``esi_entity_names``.

        Both shapes — lookup by id or by name — resolve to the same table
        + same column set. ``category`` is always bound so that a name
        collision across categories (rare but real: alliance named the
        same as a character) cannot leak.
        """
        assert plan.subject is not None
        et = plan.subject.entity_type

        if et not in _CATEGORY_FOR:
            raise PlanError(f"SQL lookup does not handle entity_type={et.value}")

        category = _CATEGORY_FOR[et]

        # Identifier comes from the closed map above — safe literal.
        # Values are always bound as parameters.
        if plan.subject.value_id is not None:
            id_values = plan.subject.value_id if isinstance(plan.subject.value_id, list) else [plan.subject.value_id]
            placeholders = ",".join(["%s"] * len(id_values))
            where = f"`entity_id` IN ({placeholders})"
            params: tuple[Any, ...] = (category, *id_values)
        elif plan.subject.value is not None:
            name_values = plan.subject.value if isinstance(plan.subject.value, list) else [plan.subject.value]
            placeholders = ",".join(["%s"] * len(name_values))
            where = f"`name` IN ({placeholders})"
            params = (category, *name_values)
        else:
            raise PlanError("lookup subject must carry value or value_id")

        sql = (
            "SELECT `entity_id` AS id, `name`, `category` "
            "FROM `esi_entity_names` "
            f"WHERE `category` = %s AND {where} "
            "LIMIT %s"
        )
        return sql, params + (plan.limit,)


def _row_label(row: Any) -> str:
    if isinstance(row, dict):
        return str(row.get("name") or row.get("id"))
    # Tuple cursor — column order matches the SELECT: (id, name, category)
    if len(row) >= 2 and row[1] is not None:
        return str(row[1])
    return str(row[0])


def _row_meta(row: Any) -> dict[str, Any]:
    if isinstance(row, dict):
        return dict(row)
    return {
        "id": row[0],
        "name": row[1] if len(row) > 1 else None,
        "category": row[2] if len(row) > 2 else None,
    }
