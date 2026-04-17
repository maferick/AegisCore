"""Answer synthesis — ResultSet → human-readable text.

The rule here is the same rule that runs through the rest of the package:
the LLM can *phrase* the answer, but it does not *decide* the answer. This
module produces a deterministic summary the LLM can either echo verbatim or
rewrite stylistically; the numbers are already fixed by the time synthesis
runs.

Phase-1 uses simple templates keyed on intent. An LLM-wrapping variant can
be added later without changing callers — the function signature is
``(ResultSet) → str``.
"""

from __future__ import annotations

from intel_copilot.executors.base import ResultSet
from intel_copilot.plan import Intent, Metric


def render(result: ResultSet) -> str:
    plan = result.plan
    if plan.intent is Intent.COUNT:
        return _render_count(result)
    if plan.intent is Intent.TOP_N:
        return _render_top_n(result)
    if plan.intent is Intent.TREND:
        return _render_trend(result)
    if plan.intent is Intent.LIST:
        return _render_list(result)
    return f"(no renderer for intent={plan.intent.value})"


# ---------------------------------------------------------------------- #

def _window_phrase(result: ResultSet) -> str:
    tw = result.plan.time_window
    if tw.from_ and tw.from_.startswith("now-"):
        return f" in the {tw.from_[4:]}"
    if tw.from_:
        return f" since {tw.from_}"
    return ""


def _metric_noun(metric: Metric) -> str:
    return {
        Metric.COUNT: "kills",
        Metric.SUM_ISK: "ISK destroyed",
        Metric.AVG_ISK: "ISK per kill (avg)",
    }.get(metric, "value")


def _fmt_value(v: float | int, metric: Metric) -> str:
    if metric in (Metric.SUM_ISK, Metric.AVG_ISK):
        return f"{v:,.0f} ISK"
    return f"{int(v):,}"


def _render_count(result: ResultSet) -> str:
    total = result.total or 0
    return f"{int(total):,} kills{_window_phrase(result)}."


def _render_top_n(result: ResultSet) -> str:
    if not result.rows:
        return f"No matching kills{_window_phrase(result)}."
    metric = result.plan.metric
    lines = [
        f"Top {len(result.rows)} by {_metric_noun(metric)}{_window_phrase(result)}:"
    ]
    for i, row in enumerate(result.rows, 1):
        lines.append(f"  {i}. {row.label} — {_fmt_value(row.value, metric)}")
    return "\n".join(lines)


def _render_trend(result: ResultSet) -> str:
    if not result.rows:
        return f"No activity{_window_phrase(result)}."
    metric = result.plan.metric
    lines = [f"Trend ({_metric_noun(metric)}):"]
    for row in result.rows:
        lines.append(f"  {row.label}  {_fmt_value(row.value, metric)}")
    return "\n".join(lines)


def _render_list(result: ResultSet) -> str:
    if not result.rows:
        return "No matching records."
    lines = [f"{len(result.rows)} record(s):"]
    for row in result.rows:
        lines.append(f"  - {row.label}")
    return "\n".join(lines)
