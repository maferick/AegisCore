<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| EVE Online integration config
|--------------------------------------------------------------------------
|
| Two surfaces live here: SSO (login.eveonline.com OAuth2) and ESI proper
| (esi.evetech.net). ADR-0002 explains the plane split: SSO is Laravel,
| light synchronous ESI calls are Laravel, heavy polling is Python.
|
| All values read through config() — never env() — so `php artisan
| config:cache` works in prod.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | SSO — login.eveonline.com (OAuth2 PKCE)
    |--------------------------------------------------------------------------
    |
    | Register an app at https://developers.eveonline.com/applications, set
    | the callback URL to match `callback_url` below, and grant the scopes
    | the app will ever need across both the user-login flow and the
    | (later) service-character flow. CCP lets you request a *subset* of
    | an app's enabled scopes per login, so one app handles both.
    |
    */
    'sso' => [
        'client_id' => env('EVE_SSO_CLIENT_ID'),
        'client_secret' => env('EVE_SSO_CLIENT_SECRET'),
        'callback_url' => env('EVE_SSO_CALLBACK_URL'),

        // Scopes requested for the user-facing /auth/eve login. `publicData`
        // is the floor — enough to read the JWT identity claim but nothing
        // character-sensitive. Override via EVE_SSO_LOGIN_SCOPES if a
        // deployment wants richer profile info on login.
        'login_scopes' => env('EVE_SSO_LOGIN_SCOPES', 'publicData'),

        // Scopes requested for the elevated /auth/eve/service-redirect
        // flow — the "service character" SSO that admins kick off from
        // /admin/eve-service-character. Tokens land in eve_service_tokens
        // (encrypted) for the Python execution-plane poller to consume.
        // Default covers the read-only set the polling roadmap needs;
        // operators trim or extend per deployment. Space- or
        // comma-separated. See ADR-0002 § Token kinds + phase-2 amendment.
        'service_scopes' => env(
            'EVE_SSO_SERVICE_SCOPES',
            'publicData esi-location.read_location.v1 esi-location.read_ship_type.v1 '
            .'esi-search.search_structures.v1 esi-universe.read_structures.v1 '
            .'esi-markets.structure_markets.v1 esi-characters.read_corporation_roles.v1 '
            .'esi-corporations.read_corporation_membership.v1 '
            .'esi-corporations.read_structures.v1 esi-alliances.read_contacts.v1',
        ),

        // EVE SSO v2 endpoints. Pinned here so a routing change from CCP
        // is a config edit, not a code search.
        'authorize_url' => 'https://login.eveonline.com/v2/oauth/authorize/',
        'token_url' => 'https://login.eveonline.com/v2/oauth/token',

        // Admin allow-list. Comma-separated EVE character IDs; characters
        // in this list bypass any DB role check and get Filament /admin
        // access. Whitespace tolerated. See ADR-0002 § Admin gate for why
        // this lives in env, not the DB.
        'admin_character_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EVE_SSO_ADMIN_CHARACTER_IDS', '')),
        ))),

        // ----- Donations character (third SSO flow) -----
        //
        // Optional in-game character that *receives* ISK donations.
        // Locked to one character ID so the donations poller can never
        // accidentally consume a token belonging to another character —
        // the SSO callback rejects mismatched authorisations rather
        // than upserting the wrong row. Set `character_id` to null
        // (empty env var) to disable the flow entirely; the admin page
        // hides its CTA and the scheduler skips polling.
        // See ADR-0002 § phase-2 amendment for the rationale.
        'donations' => [
            'character_id' => filter_var(
                env('EVE_SSO_DONATIONS_CHARACTER_ID'),
                FILTER_VALIDATE_INT,
                ['options' => ['default' => null]],
            ),
            'character_name' => env('EVE_SSO_DONATIONS_CHARACTER_NAME'),
            // Wallet read is the core scope this token needs; `publicData`
            // is requested alongside it because CCP's own consent screen
            // and some SSO-v2 edge cases expect it as the "base" scope of
            // any authorised session — without it some deployments see
            // the authorise flow drop the wallet scope silently. Override
            // via env if a deployment needs a different mix, but keep the
            // set as narrow as possible to limit the blast radius if the
            // bearer token ever leaks. Donor name resolution still uses
            // the unauth'd /universe/names/ endpoint regardless.
            'scopes' => env(
                'EVE_SSO_DONATIONS_SCOPES',
                'publicData esi-wallet.read_character_wallet.v1',
            ),
            // Cron expression for the donations poller. Default every
            // 5 minutes. Wallet journal is cached 1h server-side per
            // CCP, so a 5-min poll is mostly 304-cheap; the 1-hour
            // window is the floor on donor-feedback latency.
            'poll_cron' => env('EVE_DONATIONS_POLL_CRON', '*/5 * * * *'),

            // ISK-to-ad-free-days conversion rate.
            //
            // Each donation grants `amount / isk_per_day` days of
            // ad-free time. Default 100_000 ISK = 1 day. Operator-
            // tunable per deployment so a small community can run
            // generous (e.g. 10_000) and a large one can require more.
            //
            // Donations stack forward: a second donation arriving inside
            // an active window extends it from the current expiry, not
            // from "now". A donation after the window expired resets it
            // from its own arrival timestamp. See
            // App\Domains\UsersCharacters\Services\DonorBenefitCalculator
            // for the streaming accumulator.
            //
            // After changing this value, run `php artisan
            // eve:donations:recompute` to rebuild every donor's stored
            // `ad_free_until` against the new rate. (The change is
            // retroactive by design — changing the rate shouldn't
            // punish past donors by leaving them on the old curve.)
            //
            // Zero / negative values fall back to the default — we never
            // divide by zero and never send expiry backwards.
            'isk_per_day' => (int) env('EVE_DONATIONS_ISK_PER_DAY', 100_000),
        ],

        // ----- Market character (fourth SSO flow, donor self-service) -----
        //
        // Scope set requested when a donor clicks "Authorise market
        // data" on /account/settings. Per ADR-0004 § Live polling the
        // minimum viable set is:
        //
        //   publicData
        //     — base-identity scope CCP's consent surface expects.
        //   esi-search.search_structures.v1
        //     — powers the donor's structure picker (ESI only returns
        //       IDs the character has ACLs at, which enforces the
        //       structure-discovery-is-ACL-gated invariant).
        //   esi-universe.read_structures.v1
        //     — resolves structure_id → name/system (cached weekly).
        //   esi-markets.structure_markets.v1
        //     — the actual market-reads the poller performs.
        //
        // Extended for the standings surface (feeds /account/settings
        // corp/alliance standings + the battle-report friendly/enemy
        // tagging downstream):
        //
        //   esi-corporations.read_contacts.v1
        //     — corp official contact list. Character needs
        //       Personnel_Manager or Contact_Manager in-game role, so
        //       this will 403 for line-member donors. We tolerate that
        //       at sync time and just skip the corp half.
        //   esi-alliances.read_contacts.v1
        //     — alliance official contact list. Any alliance member
        //       can read it — no role gate.
        //
        // Override via env if a deployment wants a different mix
        // (e.g. dropping `esi-search` once a donor's structure picks
        // are locked in). Space- or comma-separated. Same parsing
        // rules as service_scopes.
        //
        // Existing tokens authorised before these scopes were added
        // will NOT have them in the JWT `scp` claim — the standings
        // sync logs a warning and skips those donors until they re-
        // authorise on /account/settings.
        'market_scopes' => env(
            'EVE_SSO_MARKET_SCOPES',
            'publicData esi-search.search_structures.v1 '
            .'esi-universe.read_structures.v1 esi-markets.structure_markets.v1 '
            .'esi-corporations.read_contacts.v1 esi-alliances.read_contacts.v1',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | ESI — esi.evetech.net
    |--------------------------------------------------------------------------
    |
    | Phase-1 client is "thin": logs rate-limit headers, honours Retry-After
    | on 429/420, does conditional-GET via Redis-cached ETag / Last-Modified.
    | Reactive per-group pre-flight throttling (bucket + global error-limit
    | budget) lives in App\Services\Eve\Esi\EsiRateLimiter. Token refresh
    | and heavy polling stay on the Python execution plane.
    |
    */
    'esi' => [
        'base_url' => env('ESI_BASE_URL', 'https://esi.evetech.net/latest'),

        // CCP require a User-Agent identifying app + contact. Docs:
        // https://developers.eveonline.com/docs/services/esi/best-practices/
        'user_agent' => env('ESI_USER_AGENT', 'AegisCore/0.1 (+ops@example.com)'),

        'timeout_seconds' => (int) env('ESI_TIMEOUT_SECONDS', 10),

        // Cache store + TTL for ETag / Last-Modified values. TTL caps the
        // memory footprint of the conditional-GET cache — real freshness
        // is bounded by CCP's `Expires` header per endpoint, so this
        // number just bounds how long stale validators hang around
        // before being re-learned.
        'cache_store' => env('ESI_CACHE_STORE', 'redis'),
        'cache_ttl_seconds' => (int) env('ESI_CACHE_TTL_SECONDS', 86400),

        // Payload cache (App\Services\Eve\Esi\CachedEsiClient). Sits on top
        // of the bare EsiClient transport and stores full response bodies
        // alongside the validators so:
        //
        //   - fresh hits return without a network call
        //   - 304s replay a usable body (not null) while preserving the
        //     notModified signal for short-circuit callers
        //   - transient upstream failures serve the last-good body
        //     instead of failing the caller
        //
        // `payload_fallback_freshness_seconds` is the dwell we assume when
        // ESI omits `Expires` (rare, but defensive). `stale_if_error`
        // bounds how old a cached body we'll serve under a 5xx / timeout.
        // `retention` bounds total entry lifetime in the cache store;
        // should be >= `cache_ttl_seconds` to avoid drift where the inner
        // has validators but the outer has evicted the payload. `lock_wait`
        // bounds single-flight coalescing — peers waiting longer fall
        // through to a direct fetch instead of starving.
        'payload_fallback_freshness_seconds' => (int) env('ESI_PAYLOAD_FALLBACK_FRESHNESS_SECONDS', 60),
        'payload_stale_if_error_seconds' => (int) env('ESI_PAYLOAD_STALE_IF_ERROR_SECONDS', 600),
        'payload_retention_seconds' => (int) env('ESI_PAYLOAD_RETENTION_SECONDS', 604_800),
        'payload_lock_wait_seconds' => (int) env('ESI_PAYLOAD_LOCK_WAIT_SECONDS', 5),

        // Kill switch. When false the container binds the bare EsiClient
        // for EsiClientInterface, bypassing the decorator entirely. Leave
        // true in production; flip to debug suspected cache-correctness
        // issues without a deploy.
        'payload_cache_enabled' => (bool) env('ESI_PAYLOAD_CACHE_ENABLED', true),

        // Reactive rate-limit throttle (App\Services\Eve\Esi\EsiRateLimiter).
        //
        //   safety_margin: refuse to send when the limiter's last-known
        //     `X-Ratelimit-Remaining` for the URL's group has dropped to or
        //     below this. Reserves a buffer for retries / out-of-band
        //     traffic so we never burn the final tokens speculatively.
        //
        //   max_wait_seconds: the EsiClient will sleep in-process for waits
        //     up to this many seconds (controllers, cron). Anything longer
        //     throws EsiRateLimitException with the wait time so Horizon
        //     callers can `release($seconds)` instead of pinning a worker.
        //
        //   error_limit_safety_margin: refuse to send when CCP's legacy
        //     global error budget (`X-ESI-Error-Limit-Remain`) has dropped
        //     to or below this. Fixed-window, 100-errors-per-minute by
        //     default; a tighter reserve than the bucket margin because
        //     overflow trips 420 for every route, not just the offender.
        'rate_limit_safety_margin' => (int) env('ESI_RATE_LIMIT_SAFETY_MARGIN', 5),
        'rate_limit_max_wait_seconds' => (int) env('ESI_RATE_LIMIT_MAX_WAIT_SECONDS', 5),
        'error_limit_safety_margin' => (int) env('ESI_ERROR_LIMIT_SAFETY_MARGIN', 10),
    ],

];
