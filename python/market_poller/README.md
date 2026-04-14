# market_poller

Pulls live order-book snapshots from ESI into the canonical
`market_orders` MariaDB table (per
[ADR-0003](../../docs/adr/0003-data-placement-freeze.md) and
[ADR-0004](../../docs/adr/0004-market-data-ingest.md)).

One-shot container: one invocation = one pass. The calling cadence
lives outside this package (Laravel scheduler, cron, systemd timer
— operator's call).

## Scope

Polls two kinds of row in `market_watched_locations`:

1. **NPC stations** (`location_type = 'npc_station'`) — region endpoint
   + client-side location filter, no auth.
2. **Admin-owned player structures** (`location_type = 'player_structure'`
   with `owner_user_id IS NULL`) — structure endpoint using the
   `eve_service_tokens` singleton the Laravel
   `/admin/eve-service-character` flow authored. Requires
   `esi-markets.structure_markets.v1` scope.

**Jita 4-4 is the seeded baseline** (region 10000002, location
60003760) and runs on every pass.

Donor-owned structure polling (`location_type = 'player_structure'`
with `owner_user_id = <user>`, backed by `eve_market_tokens`) is the
next rollout step per ADR-0004. Rows exist in
`market_watched_locations` but are log-skipped by this package until
that step lands.

Per-location output: stamp `observation_kind = 'snapshot'`, bulk
`INSERT IGNORE` into `market_orders`, emit one
`market.orders_snapshot_ingested` outbox event.

Source-string convention:
- `esi_region_<region_id>_<location_id>` for NPC rows.
- `esi_structure_<structure_id>` for structure rows.

## Run

```sh
make market-poll                                 # one pass, all enabled rows
make market-poll MARKET_ARGS="--dry-run"         # fetch + log, no inserts
make market-poll MARKET_ARGS="--only-location-id=60003760"    # Jita only
make market-poll MARKET_ARGS="--log-level=DEBUG"
```

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

Per ADR-0004 § Failure handling:

- **Routine failures** (403 no-access, 5xx, timeout):
  `consecutive_failure_count` increments, `last_error` / `last_error_at`
  record the most recent. Auto-disable after 3 consecutive 403s or 5
  consecutive 5xx/timeouts; `disabled_reason` is set (`no_access`,
  `upstream_failing`, `upstream_unreachable`). A single success resets
  the counter.
- **Security-boundary failures** (token ↔ location ownership mismatch,
  required scope missing): immediate disable, no grace counter. Not
  reachable in phase 1 (no auth'd polling yet); the path exists in
  `persist.disable_immediately` for later steps.

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
