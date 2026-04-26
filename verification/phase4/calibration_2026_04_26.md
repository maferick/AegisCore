# Phase 4.1 — calibration cleanup, 2026-04-26

Operator-only single-source telemetry (one Windows uploader, ~1500
EVE log files spanning Feb–Mar 2026). Calibration tightening run
end-to-end on real data.

## Changes

1. `combat_spike` threshold: 10 → 30 events / 5min window.
   Configurable via `PHASE4_COMBAT_SPIKE_MIN_EVENTS`.
2. `self_destruct_wave`: lazy substring → exact `(notify)` regex
   anchored on real EVE notify phrasings (sequence initiated /
   activated / aborted, capsule self-destruct).
3. New `channel_motd` event_type. Pre-existing
   `EVE System > Channel MOTD:` lines reclassified.
4. `eve-log:retry-parse-errors` artisan + `make eve-log-retry-parse-errors`
   replays `eve_log_parse_errors` through current parser.
5. Folder-baseline log_type detection — `chatlog` baseline
   established before channel-name parse.
6. Per-line UTF-8 BOM strip in parser (chat logs have BOM at
   start of every line, not just file start).

## Before / after

### Events table (`eve_log_events`)

| event_type     | BEFORE  | AFTER   | Δ |
|----------------|--------:|--------:|---:|
| `unknown`      | 112,076 |  24,167 | **−78%** |
| `local_message`|      52 |  48,311 | new (Local channel detection fixed) |
| `chat_message` |  45,420 |  47,656 | + |
| `fleet_message`|       0 |  30,545 | new (Fleet channel detection fixed) |
| `combat_event` |  37,968 |  37,968 | = |
| `notify_event` |  17,548 |  20,272 | + (gamelog flavor reclassification) |
| `session_event`|     926 |   3,588 | + |
| `channel_motd` |       0 |   1,519 | new |

`unknown` events that survived re-parse (24,167) are genuine
unparseable garbage — header decoration tweaks, half-truncated
chunks, or EVE client edge cases.

### Operational timelines (`operational_timeline_events`, bloc 1)

| timeline_type      | BEFORE | AFTER | Δ |
|--------------------|------:|------:|---:|
| `combat_spike`     | 2,369 | 1,101 | **−54%** (threshold 10→30) |
| `self_destruct_wave`| 2,046 |  953 | **−53%** (strict regex) |
| `fleet_formup`     |     0 | 4,898 | new (chatlog log_type fix) |
| `crash_symptom`    |    62 |   121 | + |
| `disengagement`    |    47 |     3 | mostly absorbed into combat_spike |

Fleet form-ups now surface — chat-log classification was the gate.

### Fleet presence windows (new since cleanup)

| derived_role         | n     |
|----------------------|------:|
| `passive_observer`   | 6,783 |
| `active_combatant`   | 1,250 |
| `unknown`            |   709 |
| `fleet_lurker`       |   673 |

### Session correlation edges (new since cleanup)

| confidence | n     |
|------------|------:|
| medium     | 2,680 |
| high       |   387 |

### Parse error queue

| status        | n      |
|---------------|-------:|
| `reparsed_ok` | 87,909 |
| `retried`     | 24,167 |

## Calibration backlog (next pass)

1. **`self_destruct_wave` still over-fires** (953). Three
   improvements considered:
   - Add minimum gap between waves (e.g. 30min) so a single
     fleet-wipe doesn't fire 5 windows.
   - Require distinct ship/character context on the notify line
     once we extract it.
   - Drop confidence to "low" until real-world calibration arrives.

2. **`fleet_formup` count is high** (4,898). Real fleet form-ups
   per week: ~10 per active operator. A single operator's data
   covering ~3 months should produce hundreds, not thousands.
   Likely over-firing on continuous fleet chatter. Tighten:
   require ≥6 messages from ≥3 distinct speakers AND a 5-minute
   silence window before the cluster.

3. **Intel reliability still 0** — no events classified as
   `intel_report`. Channel name pattern matching needs the
   bloc's actual intel channel names (e.g. WinterCo's specific
   intel channels). Current `INTEL_CHANNEL_HINTS` (`intel`, `spy`,
   `red light`, `cs intel`, `wartime`) doesn't match this
   operator's channels.

4. **`combat_spike` still 1,101 entries**. May further raise
   threshold to 50, OR add distinct attacker count requirement
   so AI gunnery autofire stops dominating.

5. **`disengagement` lost data** — only 3 vs 47 before. The
   higher combat_spike threshold + sliding window logic isn't
   recognising "combat then silence" cleanly. Algorithm needs
   restating: track combat-rate-derivative rather than raw event
   count, then "disengagement" = sharp drop in rate.

## Reproduce

```bash
# replay parse errors through current parser
make eve-log-retry-parse-errors ELOG_RETRY_ARGS="--limit=200000"

# rerun all Phase 4 passes
make ci-phase4-timelines             VIEWER_BLOC=1 CI_ARGS="--since-hours=8760"
make ci-phase4-fleet-participation   VIEWER_BLOC=1 CI_ARGS="--since-hours=8760"
make ci-phase4-intel-reliability     VIEWER_BLOC=1 CI_ARGS="--window-days=90"
make ci-phase4-session-correlation   VIEWER_BLOC=1 CI_ARGS="--window-days=90"

# check current state
php artisan counter-intel:phase4-status --bloc=1
```
