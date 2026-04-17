<?php

declare(strict_types=1);

namespace App\Domains\IntelCopilot\Services;

use App\Domains\IntelCopilot\Data\BrokerResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin PHP client for the Python intel_copilot HTTP broker (ADR-0007).
 *
 * Everything that leaves Laravel and touches the broker goes through one
 * object so the retry / auth / logging surface area stays auditable. The
 * broker itself enforces the plan-shape contract — this side only has to
 * forward the question + carry the shared token.
 *
 * Failure modes the caller cares about:
 *
 *   - ``ConnectionException`` bubbles out as ``BrokerUnavailable`` so
 *     the chat page can show "broker offline" instead of a 500.
 *   - A 4xx from the broker becomes a ``BrokerResponse`` with
 *     ``ok=false`` and the broker's error message, because they are
 *     usually "no heuristic matched" / "invalid plan" — useful feedback
 *     to the user, not a crash.
 *   - 5xx / unexpected shape becomes ``BrokerError``.
 */
class IntelCopilotBroker
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * Ask the broker a natural-language question.
     *
     * @param  string  $question  Raw user input.
     * @param  bool    $useLlm    Fall through to Claude when heuristic misses.
     */
    public function ask(string $question, bool $useLlm = true): BrokerResponse
    {
        return $this->post('/ask', [
            'question' => $question,
            'use_llm' => $useLlm,
        ]);
    }

    /** Execute a pre-built plan (skips parsing). */
    public function executePlan(array $plan): BrokerResponse
    {
        return $this->post('/plan', $plan);
    }

    /** Cheap liveness probe. Does not count against the chat session. */
    public function health(): array
    {
        $response = $this->http
            ->timeout(2)
            ->acceptJson()
            ->get($this->url('/healthz'));

        return $response->successful() ? (array) $response->json() : ['ok' => false];
    }

    // ------------------------------------------------------------------ //

    private function post(string $path, array $payload): BrokerResponse
    {
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders($this->authHeaders())
                ->post($this->url($path), $payload);
        } catch (ConnectionException $exc) {
            Log::warning('intel_copilot.broker_unreachable', ['err' => $exc->getMessage()]);
            throw new BrokerUnavailable('Intel Copilot broker is not reachable.', previous: $exc);
        }

        $body = (array) $response->json();

        if ($response->clientError()) {
            // 4xx = "broker understood, rejected the request". Surface
            // the broker's own error string so the chat shows the same
            // message an operator would get on the CLI.
            return BrokerResponse::failure(
                status: $response->status(),
                error: $body['error'] ?? 'unknown client error',
                raw: $body,
            );
        }

        if (! $response->successful()) {
            throw new BrokerError(
                "broker returned HTTP {$response->status()}: "
                    .json_encode($body, JSON_UNESCAPED_SLASHES)
            );
        }

        return BrokerResponse::success($body);
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return $this->token === null || $this->token === ''
            ? []
            : ['X-Intel-Copilot-Token' => $this->token];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}


/** Broker reached, rejected request (4xx) — treat as user-facing feedback. */
class BrokerUnavailable extends RuntimeException {}

/** Broker 5xx, malformed response, or any other integration failure. */
class BrokerError extends RuntimeException {}
