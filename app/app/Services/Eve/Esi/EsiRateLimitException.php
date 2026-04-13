<?php

declare(strict_types=1);

namespace App\Services\Eve\Esi;

/**
 * Thrown by `EsiClient` when ESI returns 429 (new rate-limit-group overflow)
 * or 420 (legacy error-limit overflow).
 *
 * `$retryAfter` is the `Retry-After` header value in seconds — callers in a
 * Horizon job context should `$this->release($exception->retryAfter)`;
 * synchronous callers should surface a 503 or similar back to the user and
 * log.
 *
 * See https://developers.eveonline.com/docs/services/esi/rate-limiting/ for
 * the published token-cost model (2XX=2, 3XX=1, 4XX=5, 5XX=0).
 */
class EsiRateLimitException extends EsiException
{
    public function __construct(
        string $message,
        public readonly int $retryAfter,
        int $status,
        string $responseBody = '',
        string $url = '',
    ) {
        parent::__construct($message, $status, $responseBody, $url);
    }
}
