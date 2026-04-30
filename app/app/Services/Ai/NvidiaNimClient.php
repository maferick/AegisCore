<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * NVIDIA NIM chat-completions client.
 *
 * Out-of-band safe-AI backend authorised under ADR 0012 / ADR 0013
 * (calibration_proposals row 2026-04-30, kind=dependency_addition).
 *
 * Hard rules:
 *   - timeout + connect-timeout enforced on every request
 *   - retry with exponential backoff on transient failures
 *   - automatic fallback to secondary model on primary failure
 *   - JSON mode requested when the caller wants structured output
 *   - graceful failure: never throws on transport error from caller
 *     POV when invoked through {@see chatJsonOrNull()}; CI / cron
 *     callers can therefore exit zero even when NIM is down
 *   - never logs the API key or full message bodies; logs the
 *     model name, prompt-hash, latency, status, and a redacted
 *     metadata block
 *
 * Plane-boundary: this client is for artisan / out-of-band paths
 * only. Do not invoke from Livewire mounts, Filament page renders,
 * or queue jobs that gate analyst-visible state. The 2s p95 budget
 * does not apply to artisan, but the client is still bounded by
 * the configured timeout.
 */
final class NvidiaNimClient
{
    public const TIER_FAST = 'fast';
    public const TIER_HEAVY = 'heavy';
    public const TIER_SAFETY = 'safety';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('services.nvidia_nim', []);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key'])
            && ! empty($this->config['base_url']);
    }

    public function primaryModel(): string
    {
        return (string) ($this->config['primary_model'] ?? '');
    }

    public function fallbackModel(): ?string
    {
        $m = (string) ($this->config['fallback_model'] ?? '');
        return $m !== '' ? $m : null;
    }

    public function heavyModel(): ?string
    {
        $m = (string) ($this->config['heavy_model'] ?? '');
        return $m !== '' ? $m : null;
    }

    public function safetyModel(): ?string
    {
        $m = (string) ($this->config['safety_model'] ?? '');
        return $m !== '' ? $m : null;
    }

    /**
     * Resolve the model id for a tier label. Falls back to the primary
     * model if a tier is unconfigured.
     */
    public function modelFor(string $tier): string
    {
        return match ($tier) {
            self::TIER_HEAVY => $this->heavyModel() ?? $this->primaryModel(),
            self::TIER_SAFETY => $this->safetyModel() ?? $this->primaryModel(),
            default => $this->primaryModel(),
        };
    }

    /**
     * Tier-aware chat. Routes to the configured model for the tier and
     * applies per-tier timeout / temperature / max_tokens defaults.
     * On heavy-tier failure the client does NOT auto-fall-back to the
     * fast tier — heavy and fast have different output expectations
     * and silently downgrading would mask quality regressions. Callers
     * who want a safety net should invoke {@see chat()} explicitly.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{
     *   model: string, model_used: string, content: string,
     *   raw: array<string, mixed>, prompt_hash: string,
     *   latency_ms: int, attempts: int, fell_back: bool,
     * }
     */
    public function chatTier(string $tier, array $messages, array $options = []): array
    {
        $model = $this->modelFor($tier);
        $opts = $options;
        $opts['model'] = $model;

        if ($tier === self::TIER_HEAVY) {
            $opts['temperature'] = $opts['temperature']
                ?? (float) ($this->config['heavy_temperature'] ?? 0.15);
            $opts['max_tokens'] = $opts['max_tokens']
                ?? (int) ($this->config['heavy_max_tokens'] ?? 4096);
            $opts['timeout_seconds_override'] = (int) ($this->config['heavy_timeout_seconds'] ?? 180);
            // Heavy tier has no auto-fallback (see method docblock).
            $opts['fallback_model'] = false;
        }

        return $this->chat($messages, $opts);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{data: array<string,mixed>, meta: array<string,mixed>}|null
     */
    public function chatTierJsonOrNull(string $tier, array $messages, array $options = []): ?array
    {
        $opts = $options;
        $opts['model'] = $this->modelFor($tier);

        if ($tier === self::TIER_HEAVY) {
            $opts['temperature'] = $opts['temperature']
                ?? (float) ($this->config['heavy_temperature'] ?? 0.15);
            $opts['max_tokens'] = $opts['max_tokens']
                ?? (int) ($this->config['heavy_max_tokens'] ?? 4096);
            $opts['timeout_seconds_override'] = (int) ($this->config['heavy_timeout_seconds'] ?? 180);
            $opts['fallback_model'] = false;
        }

        return $this->chatJsonOrNull($messages, $opts);
    }

    /**
     * Run a chat-completion request. Returns the decoded response body
     * on success. Throws on hard failure (auth, exhausted retries on
     * both models, malformed response).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  optional overrides:
     *   - model: string (defaults to primary_model)
     *   - temperature: float
     *   - max_tokens: int
     *   - json_mode: bool (toggles response_format)
     *   - extra: array<string, mixed> merged into payload
     * @return array{
     *   model: string,
     *   model_used: string,
     *   content: string,
     *   raw: array<string, mixed>,
     *   prompt_hash: string,
     *   latency_ms: int,
     *   attempts: int,
     *   fell_back: bool,
     * }
     */
    public function chat(array $messages, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('nvidia_nim_not_configured');
        }

        $primary = (string) ($options['model'] ?? $this->primaryModel());

        // fallback_model semantics:
        //   omitted        → use configured fallback model (default)
        //   string         → use this model as fallback
        //   null / false   → disable fallback for this call
        $fallback = array_key_exists('fallback_model', $options)
            ? $options['fallback_model']
            : $this->fallbackModel();
        if ($fallback === false) {
            $fallback = null;
        }

        $promptHash = self::hashMessages($messages);

        try {
            $result = $this->callModel($primary, $messages, $options, $promptHash);
            $result['fell_back'] = false;
            return $result;
        } catch (Throwable $primaryFail) {
            if ($fallback === null || $fallback === '' || $fallback === $primary) {
                throw $primaryFail;
            }
            Log::warning('nvidia_nim.primary_failed_falling_back', [
                'primary_model' => $primary,
                'fallback_model' => $fallback,
                'prompt_hash' => $promptHash,
                'error' => self::redactedError($primaryFail),
            ]);
        }

        $result = $this->callModel((string) $fallback, $messages, $options, $promptHash);
        $result['fell_back'] = true;
        return $result;
    }

    /**
     * Convenience wrapper that requests JSON mode and returns the
     * decoded JSON content along with metadata. Returns null instead
     * of throwing when the backend is unavailable or the response
     * is unparseable — designed for graceful failure paths.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{
     *   data: array<string, mixed>,
     *   meta: array<string, mixed>,
     * }|null
     */
    public function chatJsonOrNull(array $messages, array $options = []): ?array
    {
        $options['json_mode'] = true;

        try {
            $result = $this->chat($messages, $options);
        } catch (Throwable $e) {
            Log::warning('nvidia_nim.chat_failed_returning_null', [
                'error' => self::redactedError($e),
                'prompt_hash' => self::hashMessages($messages),
            ]);
            return null;
        }

        $decoded = self::decodeJsonContent($result['content']);
        if ($decoded === null) {
            Log::warning('nvidia_nim.json_parse_failed', [
                'prompt_hash' => $result['prompt_hash'],
                'model_used' => $result['model_used'],
                'content_preview' => mb_substr($result['content'], 0, 200),
            ]);
            return null;
        }

        return [
            'data' => $decoded,
            'meta' => [
                'model_requested' => $result['model'],
                'model_used' => $result['model_used'],
                'prompt_hash' => $result['prompt_hash'],
                'latency_ms' => $result['latency_ms'],
                'attempts' => $result['attempts'],
                'fell_back' => $result['fell_back'],
                'usage' => $result['raw']['usage'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{model: string, model_used: string, content: string, raw: array<string, mixed>, prompt_hash: string, latency_ms: int, attempts: int}
     */
    private function callModel(string $model, array $messages, array $options, string $promptHash): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? $this->config['temperature'] ?? 0.2),
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->config['max_tokens'] ?? 1500),
        ];

        if (! empty($options['json_mode']) || ! empty($this->config['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if (isset($options['extra']) && is_array($options['extra'])) {
            $payload = array_merge($payload, $options['extra']);
        }

        $maxRetries = max(0, (int) ($this->config['max_retries'] ?? 2));
        $baseMs = max(50, (int) ($this->config['retry_base_ms'] ?? 500));
        $timeoutSec = max(1, (int) (
            $options['timeout_seconds_override']
            ?? $this->config['timeout_seconds']
            ?? 30
        ));
        $connectTimeoutSec = max(1, (int) ($this->config['connect_timeout_seconds'] ?? 5));

        $url = rtrim((string) $this->config['base_url'], '/').'/chat/completions';

        $startedAt = microtime(true);
        $attempts = 0;
        $lastException = null;
        $lastResponse = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $attempts++;
            try {
                $response = Http::withToken((string) $this->config['api_key'])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'User-Agent' => 'AegisCore/1.0 (+nvidia-nim-client)',
                    ])
                    ->timeout($timeoutSec)
                    ->connectTimeout($connectTimeoutSec)
                    ->post($url, $payload);

                if ($response->successful()) {
                    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
                    $body = (array) $response->json();
                    $content = self::extractContent($body);
                    if ($content === null) {
                        Log::warning('nvidia_nim.empty_content_diagnostic', [
                            'model_requested' => $model,
                            'model_used' => (string) ($body['model'] ?? $model),
                            'prompt_hash' => $promptHash,
                            'finish_reason' => $body['choices'][0]['finish_reason'] ?? null,
                            'message_keys' => is_array($body['choices'][0]['message'] ?? null)
                                ? array_keys($body['choices'][0]['message'])
                                : null,
                            'usage' => $body['usage'] ?? null,
                        ]);
                        throw new RuntimeException('nvidia_nim_response_missing_content');
                    }

                    Log::info('nvidia_nim.chat_ok', [
                        'model_requested' => $model,
                        'model_used' => (string) ($body['model'] ?? $model),
                        'prompt_hash' => $promptHash,
                        'latency_ms' => $latencyMs,
                        'attempts' => $attempts,
                        'usage' => $body['usage'] ?? null,
                    ]);

                    return [
                        'model' => $model,
                        'model_used' => (string) ($body['model'] ?? $model),
                        'content' => $content,
                        'raw' => $body,
                        'prompt_hash' => $promptHash,
                        'latency_ms' => $latencyMs,
                        'attempts' => $attempts,
                    ];
                }

                $lastResponse = $response;

                // Auth / 4xx (except 408/429) — don't retry.
                if ($response->status() === 401 || $response->status() === 403) {
                    throw new RuntimeException('nvidia_nim_auth_failed_'.$response->status());
                }
                if ($response->status() >= 400 && $response->status() < 500
                    && ! in_array($response->status(), [408, 425, 429], true)) {
                    throw new RuntimeException('nvidia_nim_client_error_'.$response->status());
                }

                // 5xx / 408 / 429 → retry with backoff.
            } catch (ConnectionException $e) {
                $lastException = $e;
            } catch (Throwable $e) {
                // Hard error — re-raise. Auth failures land here.
                throw $e;
            }

            if ($attempt < $maxRetries) {
                $sleepMs = $baseMs * (2 ** $attempt) + random_int(0, $baseMs);
                usleep($sleepMs * 1000);
            }
        }

        $status = $lastResponse?->status();
        $message = sprintf(
            'nvidia_nim_exhausted_retries model=%s attempts=%d status=%s',
            $model,
            $attempts,
            $status === null ? 'transport_error' : (string) $status,
        );

        if ($lastException !== null) {
            throw new RuntimeException($message, 0, $lastException);
        }
        throw new RuntimeException($message);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function extractContent(array $body): ?string
    {
        $choices = $body['choices'] ?? null;
        if (! is_array($choices) || ! isset($choices[0])) {
            return null;
        }
        $msg = $choices[0]['message'] ?? null;
        if (! is_array($msg)) {
            return null;
        }
        $content = $msg['content'] ?? null;
        if (is_string($content) && $content !== '') {
            return $content;
        }
        // Some NIM responses return content as a structured array.
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
            if ($parts !== []) {
                return implode("\n", $parts);
            }
        }
        // Reasoning models (GLM4.7, deepseek-r1, etc.) sometimes drop the
        // final answer into reasoning_content when output budget is tight.
        // Fall back to it before declaring the response empty.
        $reasoning = $msg['reasoning_content'] ?? null;
        if (is_string($reasoning) && $reasoning !== '') {
            return $reasoning;
        }
        return null;
    }

    /**
     * Decode JSON content emitted by a JSON-mode completion. Strips
     * accidental ``` fences if the model leaks them despite the
     * response_format hint.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJsonContent(string $content): ?array
    {
        $trimmed = trim($content);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Stable hash over the prompt — used as the audit-log correlation
     * id and to detect cache hits across runs. Hashes the role/content
     * pairs only, never the API key or system metadata.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public static function hashMessages(array $messages): string
    {
        $normalised = array_map(
            static fn (array $m) => [
                'role' => (string) ($m['role'] ?? ''),
                'content' => (string) ($m['content'] ?? ''),
            ],
            $messages,
        );
        return hash('sha256', json_encode($normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function redactedError(Throwable $e): string
    {
        $msg = $e->getMessage();
        // Defensive: scrub any leaked bearer tokens.
        $msg = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/', 'Bearer [REDACTED]', $msg) ?? $msg;
        return mb_substr($msg, 0, 280);
    }
}
