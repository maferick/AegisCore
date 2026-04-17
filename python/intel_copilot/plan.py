"""Query plan IR — the structured intent the LLM emits and backends execute.

The plan is deliberately narrow. It does not try to express arbitrary SQL /
OpenSearch / Cypher. It captures the small set of question *shapes* that the
killmail intelligence domain actually needs, and leaves everything else to
follow-up planning iterations.

A good rule of thumb: if a plan field only makes sense for one backend, it
does not belong here — put it in the executor.

The plan survives validation (`QueryPlan.validate()`), then flows through the
router, then through an executor. Backends never see free-form text.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
from enum import Enum
from typing import Any


PLAN_VERSION = "1"


class Intent(str, Enum):
    """What shape of answer the question is asking for."""
    TOP_N = "top_n"      # "most used ship"  → ranked list with counts
    COUNT = "count"      # "how many kills"  → single integer
    TREND = "trend"      # "kills per day"   → time-bucketed series
    LIST = "list"        # "which kills"     → raw documents (paged)
    # Reserved for follow-up phases:
    COMPARE = "compare"  # "A vs B"          → parallel metrics
    LOOKUP = "lookup"    # "who flies X"     → single-entity fact


class EntityType(str, Enum):
    """The kind of thing a subject or filter refers to.

    Maps to one or more concrete fields per backend. The router + executor
    resolve this to index fields / columns — callers never name fields
    directly.
    """
    SHIP_TYPE = "ship_type"
    SHIP_GROUP = "ship_group"
    SHIP_CATEGORY = "ship_category"
    CHARACTER = "character"
    CORPORATION = "corporation"
    ALLIANCE = "alliance"
    SYSTEM = "system"
    REGION = "region"


class Role(str, Enum):
    """Whose side of the killmail the entity sits on."""
    ATTACKER = "attacker"
    VICTIM = "victim"
    ANY = "any"  # either side — wildcard, used sparingly


class Metric(str, Enum):
    """Aggregate to compute. Not every metric is valid for every intent."""
    COUNT = "count"
    SUM_ISK = "sum_isk"      # sum of total_value
    AVG_ISK = "avg_isk"


class Operator(str, Enum):
    EQ = "eq"
    IN = "in"
    NE = "ne"


@dataclass(frozen=True)
class TimeWindow:
    """Half-open time window. Both sides accept ISO-8601 or OpenSearch-style
    relative expressions (``now``, ``now-30d``, ``now-1h/h``).

    Left endpoint is inclusive, right is exclusive. ``None`` = unbounded.
    """
    from_: str | None = None
    to: str | None = "now"


@dataclass(frozen=True)
class EntityRef:
    """A subject or filter target.

    ``value`` is the *named* handle the user mentioned (e.g. "Catalyst",
    "Horde"). Resolution to an ID is the executor's job — it has access to
    the SDE ref tables / OpenSearch keyword indexes. Passing raw IDs is
    allowed via ``value_id``.
    """
    role: Role
    entity_type: EntityType
    operator: Operator = Operator.EQ
    value: str | list[str] | None = None
    value_id: int | list[int] | None = None

    def has_value(self) -> bool:
        return self.value is not None or self.value_id is not None


@dataclass(frozen=True)
class GroupBy:
    """What to bucket the result by.

    For ``TOP_N`` the group_by is the subject dimension; for ``TREND`` it is
    time. Leaving it off makes ``COUNT`` / ``SUM_ISK`` reduce to a single
    scalar.
    """
    role: Role
    entity_type: EntityType
    # Only meaningful for TREND:
    time_interval: str | None = None  # e.g. "1d", "1h", "1w"


@dataclass(frozen=True)
class QueryPlan:
    """The full plan. Emitted by the parser, consumed by the router."""
    intent: Intent
    metric: Metric = Metric.COUNT
    subject: EntityRef | None = None
    filters: tuple[EntityRef, ...] = ()
    time_window: TimeWindow = field(default_factory=TimeWindow)
    group_by: GroupBy | None = None
    limit: int = 10
    plan_version: str = PLAN_VERSION

    # ------------------------------------------------------------------ #
    # Validation
    # ------------------------------------------------------------------ #

    def validate(self) -> None:
        """Raise ``PlanError`` if the plan is internally inconsistent.

        Keeps the surface narrow so downstream executors can trust their
        inputs. The checks here are intent-shape invariants, not permission
        or cost checks — those belong in the router.
        """
        if self.plan_version != PLAN_VERSION:
            raise PlanError(
                f"unsupported plan_version={self.plan_version!r}, "
                f"this broker speaks {PLAN_VERSION!r}"
            )

        if self.limit <= 0 or self.limit > 1000:
            raise PlanError(f"limit must be in (0, 1000], got {self.limit}")

        if self.intent is Intent.TOP_N:
            if self.subject is None:
                raise PlanError("top_n requires a subject")
            if self.group_by is None:
                # For TOP_N the group_by implicitly equals the subject;
                # materialize it so executors do not have to special-case.
                object.__setattr__(
                    self, "group_by",
                    GroupBy(role=self.subject.role, entity_type=self.subject.entity_type),
                )

        if self.intent is Intent.TREND:
            if self.group_by is None or self.group_by.time_interval is None:
                raise PlanError("trend requires group_by.time_interval (e.g. '1d')")

        if self.intent is Intent.COUNT and self.metric is not Metric.COUNT:
            # A COUNT plan asking for SUM_ISK is really a different intent.
            raise PlanError(
                "count intent only supports metric=count; "
                "use top_n or trend with metric=sum_isk instead"
            )

        for f in self.filters:
            if not f.has_value():
                raise PlanError(f"filter {f.role.value}/{f.entity_type.value} missing value")

    # ------------------------------------------------------------------ #
    # Serialization — so plans can cross process boundaries, be logged, or
    # round-trip through an LLM prompt. Using plain dict keeps it backend-
    # independent; we deliberately avoid pydantic to keep the dependency
    # footprint small.
    # ------------------------------------------------------------------ #

    def to_dict(self) -> dict[str, Any]:
        return _as_jsonable(asdict(self))

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "QueryPlan":
        return _plan_from_dict(data)


class PlanError(ValueError):
    """Raised when a plan fails validation."""


# ---------------------------------------------------------------------- #
# Serialization helpers — not public.
# ---------------------------------------------------------------------- #

def _as_jsonable(obj: Any) -> Any:
    if isinstance(obj, dict):
        return {k.rstrip("_"): _as_jsonable(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple)):
        return [_as_jsonable(x) for x in obj]
    if isinstance(obj, Enum):
        return obj.value
    return obj


def _plan_from_dict(data: dict[str, Any]) -> QueryPlan:
    def enum_or_none(cls, v):
        return cls(v) if v is not None else None

    subject = None
    if data.get("subject"):
        s = data["subject"]
        subject = EntityRef(
            role=Role(s["role"]),
            entity_type=EntityType(s["entity_type"]),
            operator=Operator(s.get("operator", "eq")),
            value=s.get("value"),
            value_id=s.get("value_id"),
        )

    filters = tuple(
        EntityRef(
            role=Role(f["role"]),
            entity_type=EntityType(f["entity_type"]),
            operator=Operator(f.get("operator", "eq")),
            value=f.get("value"),
            value_id=f.get("value_id"),
        )
        for f in data.get("filters", ())
    )

    tw_raw = data.get("time_window") or {}
    time_window = TimeWindow(
        from_=tw_raw.get("from") or tw_raw.get("from_"),
        to=tw_raw.get("to", "now"),
    )

    gb = None
    if data.get("group_by"):
        g = data["group_by"]
        gb = GroupBy(
            role=Role(g["role"]),
            entity_type=EntityType(g["entity_type"]),
            time_interval=g.get("time_interval"),
        )

    plan = QueryPlan(
        intent=Intent(data["intent"]),
        metric=enum_or_none(Metric, data.get("metric", "count")) or Metric.COUNT,
        subject=subject,
        filters=filters,
        time_window=time_window,
        group_by=gb,
        limit=int(data.get("limit", 10)),
        plan_version=str(data.get("plan_version", PLAN_VERSION)),
    )
    return plan
