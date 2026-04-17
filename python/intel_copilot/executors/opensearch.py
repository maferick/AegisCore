"""OpenSearch executor — translates QueryPlans into killmail index queries.

Maps the role-aware subject/filter vocabulary from ``plan.py`` onto the
concrete field names baked into the ``killmails`` index
(see ``python/killmail_search/index.py``). That table lives in
``_FIELD_MAP`` below; it is the one place this executor needs to change
when the mapping grows.

Design notes:

* The plan's ``role`` + ``entity_type`` resolves to a *keyword* sub-field
  (e.g. ``victim_ship_type_name.keyword``) for aggregations, and to a
  *text* sub-field for search. For ``Role.ANY`` on an attacker-side
  entity, we use the denormalized ID list (``attacker_alliance_ids`` etc.)
  for filtering and fall back to SQL for anything that needs a name.
* ``TOP_N`` becomes a single terms aggregation; ``TREND`` becomes a
  date_histogram; ``COUNT`` becomes a bare total hits query.
* Nothing here resolves names to IDs — the index already carries both.
  That keeps this executor pure and fast.
"""

from __future__ import annotations

import time
from typing import Any

from intel_copilot.contracts import Backend
from intel_copilot.executors.base import Executor, ResultRow, ResultSet
from intel_copilot.log import get
from intel_copilot.plan import (
    EntityRef,
    EntityType,
    GroupBy,
    Intent,
    Metric,
    Operator,
    PlanError,
    QueryPlan,
    Role,
    TimeWindow,
)

log = get(__name__)


# (role, entity_type) → (filter_field, keyword_field_for_agg)
#
# ``filter_field`` is what a term/terms query targets.
# ``keyword_field_for_agg`` is what a terms aggregation buckets on.
# They differ only for fields whose text variant is indexed as ``text``
# with a ``.keyword`` subfield.
_FIELD_MAP: dict[tuple[Role, EntityType], tuple[str, str]] = {
    # Victim side — fully denormalized.
    (Role.VICTIM, EntityType.SHIP_TYPE): ("victim_ship_type_name.keyword", "victim_ship_type_name.keyword"),
    (Role.VICTIM, EntityType.SHIP_GROUP): ("victim_ship_group_name", "victim_ship_group_name"),
    (Role.VICTIM, EntityType.SHIP_CATEGORY): ("victim_ship_category_name", "victim_ship_category_name"),
    (Role.VICTIM, EntityType.CHARACTER): ("victim_character_name.keyword", "victim_character_name.keyword"),
    (Role.VICTIM, EntityType.CORPORATION): ("victim_corporation_name.keyword", "victim_corporation_name.keyword"),
    (Role.VICTIM, EntityType.ALLIANCE): ("victim_alliance_name.keyword", "victim_alliance_name.keyword"),
    (Role.VICTIM, EntityType.SYSTEM): ("system_name", "system_name"),
    (Role.VICTIM, EntityType.REGION): ("region_name", "region_name"),
    # Attacker side — we have ID lists for filtering, plus denormalized
    # "final blow" fields good enough to serve top-N aggregations.
    (Role.ATTACKER, EntityType.SHIP_TYPE): ("final_blow_ship_type_name", "final_blow_ship_type_name"),
    (Role.ATTACKER, EntityType.CHARACTER): ("final_blow_character_name.keyword", "final_blow_character_name.keyword"),
    (Role.ATTACKER, EntityType.CORPORATION): ("final_blow_corporation_name", "final_blow_corporation_name"),
    # "any" role targets the location dimensions, which are not role-sided.
    (Role.ANY, EntityType.SYSTEM): ("system_name", "system_name"),
    (Role.ANY, EntityType.REGION): ("region_name", "region_name"),
}

# ID-valued attacker filters — these use the denormalized attacker_*_ids
# arrays, which the index exposes specifically for "find kills where X
# participated" questions. Keyed by entity_type only (attacker role is
# implicit).
_ATTACKER_ID_FIELDS: dict[EntityType, str] = {
    EntityType.CHARACTER: "attacker_character_ids",
    EntityType.CORPORATION: "attacker_corporation_ids",
    EntityType.ALLIANCE: "attacker_alliance_ids",
}


class OpenSearchExecutor:
    """Plan executor backed by the killmails OpenSearch index.

    Accepts any OpenSearch-client-shaped object that exposes ``.search(index,
    body)``. That lets unit tests pass a stub without dragging the real
    ``opensearchpy`` dependency into the test path.
    """

    backend = Backend.OPENSEARCH

    def __init__(self, client: Any, index: str = "killmails") -> None:
        self._client = client
        self._index = index

    # ------------------------------------------------------------------ #
    # Public API
    # ------------------------------------------------------------------ #

    def execute(self, plan: QueryPlan) -> ResultSet:
        body = self.build_query(plan)
        t0 = time.monotonic()
        resp = self._client.search(index=self._index, body=body)
        took_ms = int((time.monotonic() - t0) * 1000)
        rows, total = self._parse_response(plan, resp)
        return ResultSet(
            backend=self.backend,
            plan=plan,
            rows=rows,
            total=total,
            took_ms=took_ms,
            query=body,
        )

    # ------------------------------------------------------------------ #
    # Query translation
    # ------------------------------------------------------------------ #

    def build_query(self, plan: QueryPlan) -> dict[str, Any]:
        """Pure plan → OpenSearch DSL. Isolated from the client for testing."""
        query = self._build_bool(plan)
        body: dict[str, Any] = {"size": 0, "query": query}

        if plan.intent is Intent.TOP_N:
            assert plan.group_by is not None  # enforced by validate()
            body["aggs"] = {"buckets": self._terms_agg(plan.group_by, plan)}

        elif plan.intent is Intent.TREND:
            assert plan.group_by is not None
            body["aggs"] = {"buckets": self._date_histogram(plan.group_by, plan)}

        elif plan.intent is Intent.COUNT:
            # No aggs needed — total hits is the answer. Track to at least
            # the user-visible limit so the response carries a real count.
            body["track_total_hits"] = True

        elif plan.intent is Intent.LIST:
            body["size"] = plan.limit
            body["sort"] = [{"killed_at": "desc"}]

        else:
            raise PlanError(f"OpenSearch executor does not handle intent={plan.intent.value}")

        return body

    # ------------------------------------------------------------------ #
    # Building blocks
    # ------------------------------------------------------------------ #

    def _build_bool(self, plan: QueryPlan) -> dict[str, Any]:
        must: list[dict[str, Any]] = []
        must_not: list[dict[str, Any]] = []

        # Time window
        range_clause = self._range_clause(plan.time_window)
        if range_clause:
            must.append({"range": {"killed_at": range_clause}})

        # Filters — one clause per EntityRef.
        for f in plan.filters:
            clause = self._filter_clause(f)
            if f.operator is Operator.NE:
                must_not.append(clause)
            else:
                must.append(clause)

        bool_q: dict[str, Any] = {}
        if must:
            bool_q["must"] = must
        if must_not:
            bool_q["must_not"] = must_not
        if not bool_q:
            return {"match_all": {}}
        return {"bool": bool_q}

    @staticmethod
    def _range_clause(tw: TimeWindow) -> dict[str, str] | None:
        clause: dict[str, str] = {}
        if tw.from_:
            clause["gte"] = tw.from_
        if tw.to and tw.to != "now":
            clause["lt"] = tw.to
        elif tw.to == "now":
            # Open right edge — "now" is implicit. Omit to keep the query cheap.
            pass
        return clause or None

    @staticmethod
    def _filter_clause(f: EntityRef) -> dict[str, Any]:
        # ID-valued attacker filters use the denormalized ID array fields.
        if f.role is Role.ATTACKER and f.entity_type in _ATTACKER_ID_FIELDS and f.value_id is not None:
            field_name = _ATTACKER_ID_FIELDS[f.entity_type]
            values = f.value_id if isinstance(f.value_id, list) else [f.value_id]
            return {"terms": {field_name: values}}

        key = (f.role, f.entity_type)
        if key not in _FIELD_MAP:
            raise PlanError(
                f"OpenSearch executor has no field mapping for "
                f"role={f.role.value} entity_type={f.entity_type.value}"
            )
        field_name, _ = _FIELD_MAP[key]

        if f.operator is Operator.IN or isinstance(f.value, list):
            values = f.value if isinstance(f.value, list) else [f.value]
            return {"terms": {field_name: values}}
        return {"term": {field_name: f.value}}

    @staticmethod
    def _terms_agg(gb: GroupBy, plan: QueryPlan) -> dict[str, Any]:
        key = (gb.role, gb.entity_type)
        if key not in _FIELD_MAP:
            raise PlanError(
                f"OpenSearch executor cannot group_by "
                f"role={gb.role.value} entity_type={gb.entity_type.value}"
            )
        _, agg_field = _FIELD_MAP[key]
        agg: dict[str, Any] = {
            "terms": {"field": agg_field, "size": plan.limit},
        }
        if plan.metric is Metric.SUM_ISK:
            agg["aggs"] = {"sum_isk": {"sum": {"field": "total_value"}}}
            agg["terms"]["order"] = {"sum_isk": "desc"}
        elif plan.metric is Metric.AVG_ISK:
            agg["aggs"] = {"avg_isk": {"avg": {"field": "total_value"}}}
            agg["terms"]["order"] = {"avg_isk": "desc"}
        return agg

    @staticmethod
    def _date_histogram(gb: GroupBy, plan: QueryPlan) -> dict[str, Any]:
        interval = gb.time_interval
        agg: dict[str, Any] = {
            "date_histogram": {
                "field": "killed_at",
                "fixed_interval": interval,
                "min_doc_count": 0,
            },
        }
        if plan.metric is Metric.SUM_ISK:
            agg["aggs"] = {"sum_isk": {"sum": {"field": "total_value"}}}
        elif plan.metric is Metric.AVG_ISK:
            agg["aggs"] = {"avg_isk": {"avg": {"field": "total_value"}}}
        return agg

    # ------------------------------------------------------------------ #
    # Response parsing
    # ------------------------------------------------------------------ #

    def _parse_response(
        self, plan: QueryPlan, resp: dict[str, Any]
    ) -> tuple[tuple[ResultRow, ...], int | float | None]:
        total = _get_total(resp)

        if plan.intent is Intent.COUNT:
            return (), total

        if plan.intent is Intent.LIST:
            hits = resp.get("hits", {}).get("hits", [])
            rows = tuple(
                ResultRow(
                    label=str(h.get("_id")),
                    value=h.get("_score") or 0,
                    meta=h.get("_source", {}),
                )
                for h in hits
            )
            return rows, total

        buckets = resp.get("aggregations", {}).get("buckets", {}).get("buckets", [])
        rows = tuple(self._bucket_to_row(b, plan) for b in buckets)
        return rows, total

    @staticmethod
    def _bucket_to_row(bucket: dict[str, Any], plan: QueryPlan) -> ResultRow:
        if plan.intent is Intent.TREND:
            label = bucket.get("key_as_string") or str(bucket.get("key"))
        else:
            label = str(bucket.get("key"))

        if plan.metric is Metric.SUM_ISK:
            value = bucket.get("sum_isk", {}).get("value") or 0
        elif plan.metric is Metric.AVG_ISK:
            value = bucket.get("avg_isk", {}).get("value") or 0
        else:
            value = bucket.get("doc_count", 0)

        return ResultRow(label=label, value=value, meta={"doc_count": bucket.get("doc_count")})


def _get_total(resp: dict[str, Any]) -> int | None:
    hits = resp.get("hits", {})
    total = hits.get("total")
    if isinstance(total, dict):
        return total.get("value")
    if isinstance(total, int):
        return total
    return None
