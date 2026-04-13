<?php

declare(strict_types=1);

namespace App\Services\Eve\Sso;

use RuntimeException;

/**
 * Raised by `EveSsoClient` on any OAuth2 / JWT decode failure.
 *
 * Callers (typically the SSO callback controller) should catch this, log it,
 * and surface a generic "login failed" to the user — we don't leak OAuth
 * internals into the UI.
 */
class EveSsoException extends RuntimeException
{
}
