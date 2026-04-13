<?php

declare(strict_types=1);

namespace App\Services\Eve\Sso;

/**
 * The three things the caller needs to kick off an SSO login:
 *
 *  - `$url`          — full redirect URL to send the user to.
 *  - `$state`        — random string the callback has to echo back verbatim
 *                      for us to trust it. Stash in session.
 *  - `$codeVerifier` — PKCE secret. Stash in session, send back on the token
 *                      exchange (never goes near the browser after the
 *                      initial /authorize redirect).
 *
 * The caller (EveSsoController@redirect) is responsible for session-stashing
 * state + codeVerifier; we don't touch session from the service layer so the
 * client stays testable without a framework boot.
 */
final class EveSsoAuthorizeRedirect
{
    public function __construct(
        public readonly string $url,
        public readonly string $state,
        public readonly string $codeVerifier,
    ) {}
}
