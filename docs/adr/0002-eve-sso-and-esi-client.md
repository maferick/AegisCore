# ADR-0002 — EVE SSO for admin access, ESI client split across planes

**Status:** Accepted
**Date:** 2026-04-13
**Related:** [ADR-0001](0001-static-reference-data.md),
[docs/CONTRACTS.md](../CONTRACTS.md), [AGENTS.md § Plane boundary](../../AGENTS.md#plane-boundary)

## Context

AegisCore needs two distinct flavours of CCP integration:

1. **EVE SSO** — OAuth2 login against `login.eveonline.com`. One round-trip
   per human login, low volume, session-scoped. This is how we decide *who*
   is allowed into `/admin` and how we'll later tie characters to users.

2. **ESI proper** — calls to `esi.evetech.net/latest/...`. This is the
   rate-limit-sensitive surface. CCP publishes a per-route-group token bucket
   (see `X-Ratelimit-Group`, `X-Ratelimit-Limit`, `X-Ratelimit-Remaining`,
   `X-Ratelimit-Used` headers), plus a legacy 100-errors-per-minute fallback
   that trips a 420 response. Token costs published on
   <https://developers.eveonline.com/docs/services/esi/rate-limiting/>:
   2XX = 2 tokens, 3XX = 1 token, 4XX = 5 tokens (except 429), 5XX = 0.

These look like one concern but they're not — SSO is a page-request-bound
event, ESI polling is a sustained background workload. Collapsing them into
a single "EVE client" either over-engineers SSO or under-engineers ESI.

## Decision

### Plane split

| Call | Plane | Reason |
|---|---|---|
| `login.eveonline.com/v2/oauth/*` (auth redirect + token exchange) | Laravel | Request-scoped, user-bound, session-tied. |
| `esi.evetech.net` **light synchronous** calls (≤1 round-trip inside a page/job) | Laravel | Fits the `<2s` / `<100 rows` job budget (ADR-0001, CONTRACTS.md). |
| `esi.evetech.net` **heavy polling** (killmails, corp/alliance rosters, wallets, markets) | Python execution plane | Violates the job budget. Needs per-group rate-bucket tracking, distributed locks on token refresh, and sustained error-budget management. |

The split is the same one ADR-0001 drew for reference-data loads: Laravel is
the control plane, Python is the execution plane. A Horizon job that polls
`/characters/{id}/wallet/journal` on a loop is the wrong tool.

### Token kinds

- **Login token** (phase 1, this ADR). Scope: `publicData`. Returned on user
  login; we decode the JWT to read `sub` (`CHARACTER:EVE:<id>`) and `name`,
  then **discard the token**. We keep only the character → user mapping in
  MariaDB. Refresh tokens are not stored — if a user's session expires, they
  log in again.

- **Service character token** (phase 2 — storage + admin flow landed
  early in a follow-up to this ADR; Python poller + automatic refresh
  still pending). Elevated scopes from `EVE_SSO_SERVICE_SCOPES` —
  default set covers `publicData`, `esi-location.*`, `esi-search.*`,
  `esi-universe.read_structures.v1`, `esi-markets.structure_markets.v1`,
  `esi-characters.read_corporation_roles.v1`, `esi-corporations.*`,
  `esi-alliances.read_contacts.v1`. Initiated by an admin from
  `/admin/eve-service-character`. Access + refresh tokens stored
  encrypted at rest in `eve_service_tokens` (Laravel's `'encrypted'`
  cast, APP_KEY = encryption key), keyed on `character_id` so re-auth
  upserts. One service character per deployment for now;
  scoped-per-feature later. Token-refresh handling stays out of this
  ADR — the Python poller takes that on alongside JWT signature
  verification.

Keeping these two flows separate means the user-login surface still
ships without any encrypted-at-rest token storage, distributed refresh
locking, or scope-set bookkeeping. The service-character storage that
landed early is one row per stack and the refresh dance is deferred to
the polling subsystem anyway, so it doesn't pull encryption or
distributed locks into the synchronous request path.

Both flows share `/auth/eve/callback` — the registered CCP app only
needs one redirect URI on file. The session stashes a `flow` marker
(`'login'` or `'service'`) when redirecting to /authorize; the callback
reads it back to dispatch to the right finisher. Bare callbacks (no
marker) fall through to the safer login finisher.

### Admin gate

`EVE_SSO_ADMIN_CHARACTER_IDS` in `.env` is a comma-separated allow-list of
EVE character IDs (not names — names are mutable, IDs are not) with
Filament `/admin` access. `App\Models\User::canAccessPanel()` checks the
authenticated user's linked character against this list.

Chosen over a DB column because:

1. Operator admin control stays in infra config (ssh + git), reachable
   without a pre-existing admin account to bootstrap.
2. No "first admin" chicken-and-egg on fresh installs.
3. Rotating the admin set doesn't need a DB migration or a UI.

Email+password login (`make filament-user`) is left in place for emergency
operator access; EVE SSO is additive, not a replacement.

### ESI client (phase 1 shape)

`App\Services\Eve\Esi\EsiClient` is a thin wrapper around Laravel's HTTP
client. Phase 1 scope:

- `User-Agent` per CCP's policy: `AegisCore/<version> (+<maintainer-email>)`.
- Bearer-token auth when the caller supplies one (unauthed routes by
  default).
- Per-URL conditional GET — stores `ETag` / `Last-Modified` in Redis,
  auto-attaches `If-None-Match` / `If-Modified-Since` on the next request.
  3XX responses are half-price in token cost, so this pays for itself on
  repeat fetches.
- Logs `X-Ratelimit-Group`, `X-Ratelimit-Remaining`, `X-Ratelimit-Used` on
  every response.
- Reactive per-group throttle via `App\Services\Eve\Esi\EsiRateLimiter`
  (added in a follow-up to this ADR — the original phase-1 plan punted
  this to Python, but real Laravel-side import callers landed first):
  - State (`remaining`, `reset_at`) and 429 backoffs live in the same
    Redis store as the conditional-GET cache, with TTLs that match the
    window so stale entries fall out automatically.
  - Pre-flight: blocks (or throws) when the URL's group is in 429
    cooldown or has dropped to/below `safety_margin` remaining tokens.
    First-time URLs (no group learned yet) only check the global
    cooldown — the response then populates the group map for next time.
  - Reactive learning: every response (including 304 + 4xx) reseeds the
    group's state from `X-Ratelimit-*` headers. We deliberately don't
    count tokens locally — CCP's `Remaining` is the source of truth, and
    re-counting compounds drift on every parallel worker.
  - Not a distributed lock. Two workers can race past `preflight()`;
    the safety margin absorbs small overshoots and the 429 → backoff is
    the safety net. A Lua-script-backed atomic counter is on the table
    when concurrent imports demonstrate the margin isn't enough.
- On 429 or 420: tells the limiter to back off (per-group + global),
  then throws `EsiRateLimitException` carrying the `Retry-After` value.
  The caller decides — Horizon jobs call `release($seconds)`,
  synchronous code bubbles the error.

Still **not** in scope:

- OpenAPI-spec-derived limit map (`x-rate-limit` extension). The reactive
  limiter learns each group's window from the first response — cheaper
  than ingesting the spec at deploy time and re-discovers the window
  when CCP rotates routes.
- Token refresh, because login tokens aren't stored.
- Distributed locks on refresh.

### JWT verification

Phase 1 decodes the SSO callback JWT **without signature verification**.
Justification:

- We always fetch the token ourselves via TLS from `login.eveonline.com`
  (the `POST /v2/oauth/token` exchange on the callback). The token never
  touches a user-controlled channel — only `code` and `state` do, both of
  which we validate server-side.
- The TLS chain to CCP is therefore the trust boundary; adding JWT signature
  verification would be belt-and-suspenders against a compromised CCP TLS
  cert.

Phase 2 adds JWKS-based `RS256`/`ES256` verification when it lands alongside
refresh-token handling (which needs JWKS anyway for the periodic re-checks).

## Alternatives considered

**1. `laravel/socialite` + community EVE provider.**
Rejected. The maintained community providers are either abandoned
(last commit 2019-2021) or hardcode the old SSO v1 endpoints. EVE SSO v2 is
standard enough PKCE that rolling our own OAuth2 client is ~150 lines and
avoids a crumbling dependency chain.

**2. Admin role via `spatie/laravel-permission`.**
Rejected for phase 1. `spatie/laravel-permission` is already installed and
will become canonical later, but seeding roles requires an existing admin,
which requires SSO to work, which requires an admin gate — chicken-and-egg.
Env-based allow-list resolves the bootstrap without blocking the eventual
DB-based role model; the two can co-exist (`canAccessPanel()` OR's them).

**3. Single unified ESI client, Laravel-only.**
Rejected. Heavy polling (killmails at seconds-to-minutes cadence, corp
member syncs) exceeds the `<2s` job budget from ADR-0001 and CONTRACTS.md.
A Horizon worker that sleeps on a 429 holds its queue connection open, which
starves other jobs. Polling belongs in Python with a dedicated process pool.

**4. Store every login token + refresh token.**
Rejected for phase 1. Login tokens only prove identity — once we've
extracted `character_id` and bound it to a session, the token has no
further use (we never call ESI on a user's behalf with their login scope).
Storing them is pure liability (encrypted-at-rest ops, refresh, revocation).
Service character tokens are different and will be stored in phase 2.

**5. Verify JWT signature in phase 1.**
Rejected. See § JWT verification above — the token is never delivered via
an untrusted channel, so verification adds dependency + complexity without
closing a real gap. Phase 2 adds it along with refresh handling.

**6. Pass the EVE character's name into `users.name` on login.**
Accepted. We do this. Downstream code can rename the User row; the
immutable join key is `characters.character_id`.

## Consequences

**Positive:**

- Phase 1 is self-contained: no token storage, no encrypted-at-rest ops,
  no JWT library, no distributed locks.
- Clean plane split: polling code physically cannot be written as a Horizon
  job (the client isn't there), so future contributors can't accidentally
  violate ADR-0001.
- Admin gate operator-controllable without a DB admin.
- ESI client is ready for light-synchronous callers (e.g. "did this token
  grant the scopes we expect") without carrying phase-2 complexity.

**Negative:**

- Service character UX doesn't exist yet — admins can't actually trigger
  background polling from `/admin` until phase 2 lands.
- `publicData`-only login can't display things like "your corp is X"
  without re-requesting scopes. Acceptable for phase 1; the info exists
  elsewhere (ESI endpoint `/characters/{id}/` returns corp + alliance
  without any scope).
- JWT decode without verification will need to be revisited; ADR-0002
  phase-2 addendum expected.

**Neutral:**

- Operator runbook needed for "register an app on developers.eveonline.com"
  and "pick redirect URI + scopes". Runbook concern, not code.

## Implementation checklist (for follow-up PRs, not this ADR)

1. `.env.example` EVE section — `EVE_SSO_CLIENT_ID`, `EVE_SSO_CLIENT_SECRET`,
   `EVE_SSO_CALLBACK_URL`, `EVE_SSO_SCOPES`, `EVE_SSO_ADMIN_CHARACTER_IDS`.
2. `config/eve.php` reading the above with sensible defaults.
3. `characters` table migration (phase-1 columns only — corp/alliance
   nullable, filled in phase 2).
4. `App\Domains\UsersCharacters\Models\Character` + `User` relation.
5. `App\Services\Eve\Sso\EveSsoClient` — OAuth2 PKCE + token exchange +
   unverified JWT decode.
6. `App\Services\Eve\Esi\EsiClient` — conditional-GET + rate-header logging.
7. `App\Http\Controllers\Auth\EveSsoController` — `/auth/eve` redirect +
   `/auth/eve/callback`.
8. Filament login page render-hook button: "Log in with EVE".
9. `User::canAccessPanel()` checks `EVE_SSO_ADMIN_CHARACTER_IDS` OR the
   legacy password path, so `make filament-user` users still work.

Phase 2 (separate ADR amendment):

10. ~~`eve_tokens` table + encryption.~~ Landed early as
    `eve_service_tokens` — schema designed for the singleton service
    character flow that #11 surfaces. Token columns ride Laravel's
    `'encrypted'` cast (APP_KEY = encryption key) so a `SELECT *` leak
    is ciphertext, not bearer tokens. See `2026_04_14_000001_create_eve_service_tokens_table.php`.
11. ~~`/admin/eve-service-character` flow for elevated-scope login.~~
    Landed early. Admin-gated route at `/auth/eve/service-redirect`
    kicks off the elevated-scope SSO round-trip; both flows reuse
    `/auth/eve/callback` with a session-stashed `flow` marker
    (`'login'` vs `'service'`) routing to the right finisher. Status +
    re-auth surface lives at `/admin/eve-service-character`.
12. Python execution plane ESI poller (per-group bucket tracker, refresh
    handling, JWT signature verification). Still pending — automatic
    refresh in particular needs distributed locks (two pollers racing
    to use a stale access token, both refreshing, double-rotating the
    refresh token). Reactive Laravel-side refresh would land in another
    PR if the first sustained polling work proves we need it before the
    Python plane is built.
