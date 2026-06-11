"""ADR-0014 — advanced subtle-spy correlation signals.

Each function in this module computes one materialised signal table.
Signals are intentionally separated so the daily pipeline can run them
in parallel and so calibration can advance them independently.

Signal status (B-0 = scaffold only; full impl lands in B-1..B-5):

  - opposite_side                  — B-1 (next commit)
  - participation_selectivity      — B-2
  - contribution_anomaly           — B-3
  - event_triggered_activity       — B-4
  - reaction_timing_correlation    — B-4
  - cohort_behavior_deviation      — B-5

Each `compute_<signal>` raises NotImplementedError until its phase
lands. The CLI dispatcher passes through; nothing schedules these
yet.

Hostile-triangulation already lives in `phase2_triangulation.py` and
`ci_hostile_triangulation`; ADR-0014 will extend that table with a
cohesion column in B-1, not duplicate the computer here.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, timedelta
from typing import Optional

from counter_intel.config import Config
from counter_intel.db import connection
from counter_intel.log import get

log = get("counter_intel.correlation_signals")

# Default rolling window matches phase1/phase2 (90d) so signals are
# directly composable with existing CI surfaces.
DEFAULT_WINDOW_DAYS = 90


@dataclass
class SignalRunCfg:
    viewer_bloc_id: int
    window_end: date
    window_days: int = DEFAULT_WINDOW_DAYS

    @property
    def window_start(self) -> date:
        return self.window_end - timedelta(days=self.window_days)


def compute_opposite_side(cfg: SignalRunCfg) -> dict:
    """Signal 1 — opposite-side correlation. Phase B-1."""
    raise NotImplementedError("scheduled for ADR-0014 phase B-1")


def compute_participation_selectivity(cfg: SignalRunCfg) -> dict:
    """Signal 4 — fight-size distribution. Phase B-2."""
    raise NotImplementedError("scheduled for ADR-0014 phase B-2")


def compute_contribution_anomaly(cfg: SignalRunCfg) -> dict:
    """Signal 5 — damage-vs-battle-median + zero-damage ratio. Phase B-3."""
    raise NotImplementedError("scheduled for ADR-0014 phase B-3")


def compute_event_triggered_activity(cfg: SignalRunCfg) -> dict:
    """Signal 3 — activity concentration around operational_incidents
    of severity >= strategic. Phase B-4."""
    raise NotImplementedError("scheduled for ADR-0014 phase B-4")


def compute_reaction_timing(cfg: SignalRunCfg) -> dict:
    """Signal 6 — friendly activity → hostile incident within ±30min
    in same/adjacent system. Phase B-4."""
    raise NotImplementedError("scheduled for ADR-0014 phase B-4")


def compute_cohort_behavior_deviation(cfg: SignalRunCfg) -> dict:
    """Signal 7 — z-score per character vs declared-alliance ×
    activity-band × dominant-role cohort. Phase B-5."""
    raise NotImplementedError("scheduled for ADR-0014 phase B-5")


# Convenience entry: run every signal that has shipped. Skips
# NotImplementedError gracefully so the daily pipeline can call this
# repeatedly across phases without crashing.
def run(viewer_bloc_id: int, window_end: Optional[date] = None,
        window_days: int = DEFAULT_WINDOW_DAYS) -> dict:
    cfg = SignalRunCfg(
        viewer_bloc_id=viewer_bloc_id,
        window_end=window_end or date.today(),
        window_days=window_days,
    )
    log.info("correlation_signals run starting", {
        "viewer_bloc_id": cfg.viewer_bloc_id,
        "window_start": cfg.window_start.isoformat(),
        "window_end": cfg.window_end.isoformat(),
        "window_days": cfg.window_days,
    })

    results = {}
    signals = [
        ("opposite_side", compute_opposite_side),
        ("participation_selectivity", compute_participation_selectivity),
        ("contribution_anomaly", compute_contribution_anomaly),
        ("event_triggered_activity", compute_event_triggered_activity),
        ("reaction_timing", compute_reaction_timing),
        ("cohort_behavior_deviation", compute_cohort_behavior_deviation),
    ]
    for name, fn in signals:
        try:
            results[name] = fn(cfg)
            log.info("signal computed", {"signal": name, "stats": results[name]})
        except NotImplementedError as e:
            results[name] = {"status": "deferred", "reason": str(e)}
            log.info("signal deferred to later phase", {"signal": name, "reason": str(e)})
        except Exception as e:
            results[name] = {"status": "error", "reason": str(e)}
            log.error("signal failed", {"signal": name, "error": str(e)})

    log.info("correlation_signals run complete", {"results": results})
    return results
