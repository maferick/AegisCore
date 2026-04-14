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
            // Wallet read is the only scope this token needs. Default
            // explicitly excludes publicData — donor name resolution
            // uses the unauth'd /universe/names/ endpoint. Override
            // via env if a deployment somehow needs more, but the
            // smaller scope set keeps the blast radius small if the
            // bearer token leaks.
            'scopes' => env(
                'EVE_SSO_DONATIONS_SCOPES',
                'esi-wallet.read_character_wallet.v1',
            ),
            // Cron expression for the donations poller. Default every
            // 5 minutes. Wallet journal is cached 1h server-side per
            // CCP, so a 5-min poll is mostly 304-cheap; the 1-hour
            // window is the floor on donor-feedback latency.
            'poll_cron' => env('EVE_DONATIONS_POLL_CRON', '*/5 * * * *'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ESI — esi.evetech.net
    |--------------------------------------------------------------------------
    |
    | Phase-1 client is "thin": logs rate-limit headers, honours Retry-After
    | on 429/420, does conditional-GET via Redis-cached ETag / Last-Modified.
    | Per-group pre-flight throttling and token refresh are phase-2 work,
    | handled in the Python execution plane.
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
        'rate_limit_safety_margin' => (int) env('ESI_RATE_LIMIT_SAFETY_MARGIN', 5),
        'rate_limit_max_wait_seconds' => (int) env('ESI_RATE_LIMIT_MAX_WAIT_SECONDS', 5),
    ],

];
