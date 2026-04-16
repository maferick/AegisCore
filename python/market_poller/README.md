# market_poller

Pulls live order-book snapshots from ESI into the canonical
`market_orders` MariaDB table (per
[ADR-0003](../../docs/adr/0003-data-placement-freeze.md) and
[ADR-0004](../../docs/adr/0004-market-data-ingest.md)).

One-shot container: one invocation = one pass. The calling cadence
lives outside this package (Laravel scheduler, cron, systemd timer
— operator's call).

## Scope

Driven by `market_watched_locations` JOIN `market_hubs`. The runner
dispatches by `(location_type, is_public_reference)`:

1. **NPC stations** (`location_type = 'npc_station'`) — region endpoint
   + client-side location filter, no auth. Always public-reference.
2. **Admin-managed public-reference structures**
   (`location_type = 'player_structure'` AND
   `market_hubs.is_public_reference = true`) — structure endpoint using
   the `eve_service_tokens` singleton the Laravel
   `/admin/eve-service-character` flow authored. Requires
   `esi-markets.structure_markets.v1` scope.
3. **Private hubs** (`location_type = 'player_structure'` AND
   `market_hubs.is_public_reference = false`) — structure endpoint using
   a collector token from `market_hub_collectors`. The poller walks
   active collectors (primary first, then stalest-failure first),
   tries each in turn, and stops at the first success. Per ADR-0005:
   - Per-collector failure bookkeeping lives on
     `market_hub_collectors` (`consecutive_failure_count`,
     `last_failure_at`, `failure_reason`). Auto-deactivate on 3×403 or
     5×5xx, same tiering as the public-reference path.
   - Token ↔ collector `user_id` mismatch → immediate
     `disable_collector_immediately` (security-boundary violation).
   - When every active collector for a hub has been exhausted and none
     remain active, the hub is frozen with
     `market_hubs.disabled_reason = 'no_active_collector'` (without
     flipping `is_active` — admin / donor re-auth can rescue it).
   - A successful poll clears the hub's `disabled_reason`,
     denormalises the serving collector's `character_id` onto
     `market_hubs.active_collector_character_id`, and bumps
     `last_sync_at`.

Hubs with `is_active = 0` OR `disabled_reason IS NOT NULL` are filtered
at SELECT time so a stuck hub doesn't burn per-tick round-trips.

**Jita 4-4 is the seeded baseline** (region 10000002, location
60003760) and runs on every pass.

Per-location output: stamp `observation_kind = 'snapshot'`, bulk
`INSERT IGNORE` into `market_orders`, emit one
`market.orders_snapshot_ingested` outbox event.

Source-string convention:
- `esi_region_<region_id>_<location_id>` for NPC rows.
- `esi_structure_<structure_id>` for structure rows.

## Run

### Scheduled (default)

The `market_poll_scheduler` compose service runs the poller in a
loop on a 5-minute cadence (matches CCP's region-orders cache
window). It starts automatically with `docker compose up` —
no operator action needed. Tail its logs via:

```sh
make logs-market_poll_scheduler
```

Override the cadence per deployment via env:

```
MARKET_POLL_INTERVAL_SECONDS=300   # default — 5 min matches ESI cache
```

### Ad-hoc (operator one-shot)

```sh
make market-poll                                 # one pass, all enabled rows
make market-poll MARKET_ARGS="--dry-run"         # fetch + log, no inserts
make market-poll MARKET_ARGS="--only-location-id=60003760"    # Jita only
make market-poll MARKET_ARGS="--log-level=DEBUG"
```

The one-shot runs in a separate transient container (tools
profile), so there's no double-polling concern even while the
scheduler is running — the two never overlap on the same DB rows
(per-location transactions serialise on `INSERT IGNORE`).

## Outbox

Emits `market.orders_snapshot_ingested` (producer `market_poller`,
version 1). Payload:

```json
{
  "source": "esi_region_10000002_60003760",
  "region_id": 10000002,
  "location_id": 60003760,
  "location_type": "npc_station",
  "observed_at": "2026-04-14T11:29:37Z",
  "rows_received": 154231,
  "rows_inserted": 9874,
  "duration_ms": 4821
}
```

`rows_received` counts orders across every ESI page of the region;
`rows_inserted` is the subset that matched the location filter AND
wasn't a duplicate against an existing
`(observed_at, source, location_id, order_id)` row. A wide delta for
an NPC row is normal and means the region-wide fetch had lots of
orders we discarded (minor hubs co-located in the same region).

## Configuration

All env-driven, no dotenv. `infra/docker-compose.yml` passes:

| Env                                   | Default                                        | Notes |
| ---                                   | ---                                            | --- |
| `DB_HOST`                             | —                                              | required |
| `DB_PORT`                             | `3306`                                         |  |
| `DB_DATABASE`                         | —                                              | required |
| `DB_USERNAME`                         | —                                              | required |
| `DB_PASSWORD`                         | —                                              | required |
| `ESI_BASE_URL`                        | `https://esi.evetech.net/latest`               | override for a mirror |
| `ESI_USER_AGENT`                      | `AegisCore/0.1 (+ops@example.com)`             | CCP-required identifier + contact |
| `ESI_TIMEOUT_SECONDS`                 | `15`                                           |  |
| `MARKET_POLL_RATE_LIMIT_SAFETY_MARGIN`| `5`                                            | bucket floor (per-group) |
| `MARKET_POLL_ERROR_LIMIT_SAFETY_MARGIN`| `10`                                          | global error-budget floor |
| `MARKET_POLL_MAX_CONSECUTIVE_403S`    | `3`                                            | auto-disable threshold (no-access) |
| `MARKET_POLL_MAX_CONSECUTIVE_5XX`     | `5`                                            | auto-disable threshold (upstream fail) |
| `MARKET_POLL_BATCH_SIZE`              | `5000`                                         | rows per `executemany` |
| `APP_KEY`                             | _unset_                                        | Laravel encryption key; required for structure polling. Accepts `base64:xxx` or bare base64. |
| `EVE_SSO_CLIENT_ID`                   | _unset_                                        | CCP app client ID; required for service-token refresh. |
| `EVE_SSO_CLIENT_SECRET`               | _unset_                                        | CCP app client secret; required for service-token refresh. |
| `EVE_SSO_TOKEN_URL`                   | `https://login.eveonline.com/v2/oauth/token`   | override for testing only |

The last four are only needed for admin-owned structure polling. A
stack without player structures can leave them empty — the poller
skips structure rows cleanly with a log line (see
`ServiceTokenNotConfigured` in `auth.py`).

## Failure discipline

Per ADR-0004 § Failure handling + ADR-0005 § Failover:

- **NPC / public-reference structure** (`market_watched_locations`
  counter): routine failures (403, 5xx, timeout) bump
  `consecutive_failure_count`, populate `last_error` / `last_error_at`.
  Auto-disable after 3 consecutive 403s or 5 consecutive 5xx/timeouts
  via `record_failure`; `disabled_reason` ∈
  {`no_access`, `upstream_failing`, `upstream_unreachable`}. Security
  violations (service-token scope missing) → `disable_immediately`,
  no grace.
- **Private hub collector** (`market_hub_collectors` counter): same
  3×403 / 5×5xx tiering, but bookkeeping is per-collector via
  `record_collector_failure`. A collector hitting the threshold flips
  to `is_active = 0` without touching `market_hubs`. When every active
  collector for the hub has failed in a pass (either this pass or
  accumulated over prior passes) the hub is frozen via
  `freeze_hub_no_collectors`. Security violations
  (`ownership_mismatch`, `scope_missing`) → immediate
  `disable_collector_immediately`. A single success via
  `record_collector_success` + `record_hub_sync_success` clears the
  counter and un-freezes the hub.

## Plane boundary

Sustained ESI polling lives on the Python execution plane per
[ADR-0002](../../docs/adr/0002-eve-sso-and-esi-client.md). This is
the first concrete caller and delivers the refresh-handling piece
ADR-0002 § phase-2 #12 flagged as pending. What's here:

- Reactive per-request rate-limit awareness (reads
  `X-Ratelimit-Remaining` / `X-ESI-Error-Limit-Remain` on every
  response, sleeps when at or below configured margins).
- Refresh via `POST /v2/oauth/token` with row-level `SELECT FOR UPDATE`
  on `eve_service_tokens` (`auth.py`) — a hypothetical second poller
  instance serialises on the DB row rather than double-rotating the
  refresh token.
- Laravel-compatible `'encrypted'` cast interop (`laravel_encrypter.py`):
  AES-256-CBC + HMAC-SHA256 envelope, same wire format Laravel 12
  uses. 19 unittest cases cover round-trip, MAC tamper, AES-GCM
  rejection, and envelope structure validation.
- JWT signature verification is still deferred (same justification as
  ADR-0002 § JWT verification — TLS is the trust boundary). The JWKS
  dance is the remaining phase-2 #12 item.

### Laravel-compatible encrypter

The Python plane needs to read tokens the Laravel `/admin` flow
encrypted into `eve_service_tokens`. `laravel_encrypter.py` ports
the `Illuminate\Encryption\Encrypter` wire format (AES-256-CBC +
HMAC-SHA256, base64-JSON envelope, `Crypt::encryptString()`-style
no-serialize path). The Python poller can both decrypt stored tokens
and re-encrypt rotated refresh tokens back into the shared row, so
Laravel-side code reading `$token->refresh_token` keeps working
transparently.

Only AES-256-CBC is supported. If Laravel is reconfigured to
AES-256-GCM (set via `cipher` in `config/app.php`), decrypt throws a
specific "not supported" error rather than producing wrong plaintext.

## Tests

```sh
cd python/
python -m unittest market_poller.test_laravel_encrypter -v
```

19 stdlib-unittest cases around the Laravel encrypter — round-trip,
APP_KEY parsing, MAC tamper detection, AES-GCM rejection, envelope
structure validation. Requires the `cryptography` wheel installed
(pin in `requirements-market.txt`).

## Layout

```
python/
├── market_poller.Dockerfile
├── requirements-market.txt
└── market_poller/
    ├── __init__.py
    ├── __main__.py                      # `python -m market_poller`
    ├── cli.py                           # argparse
    ├── config.py                        # env → Config dataclass
    ├── log.py                           # stderr kv formatter
    ├── db.py                            # pymysql connection helper (autocommit off)
    ├── esi.py                           # httpx: region-orders + structure-orders endpoints
    ├── sso.py                           # POST /v2/oauth/token (refresh_token grant)
    ├── laravel_encrypter.py             # AES-256-CBC + HMAC-SHA256 Laravel-compatible
    ├── auth.py                          # load + refresh service token with row-level lock
    ├── persist.py                       # market_orders bulk upsert + watched-locations bookkeeping
    ├── outbox.py                        # emit market.orders_snapshot_ingested
    ├── runner.py                        # orchestrator: load → branch → poll → commit
    └── test_laravel_encrypter.py        # stdlib unittest for the encrypter
```
