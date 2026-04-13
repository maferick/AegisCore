<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

use RuntimeException;

/**
 * Raised by `EsiClient` on any non-success response that isn't a rate-limit
 * (which throws the more specific `EsiRateLimitException`).
 *
 * Carries the status code and raw body so callers can decide whether it's a
 * retryable transient (5xx) or a permanent schema / auth problem (4xx).
 */
class EsiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $responseBody = '',
        public readonly string $url = '',
    ) {
        parent::__construct($message);
    }
}
