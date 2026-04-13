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
    ],

];
