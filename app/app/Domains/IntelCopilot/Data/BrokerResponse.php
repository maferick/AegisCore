<?php

declare(strict_types=1);

namespace App\Domains\IntelCopilot\Data;

/**
 * Immutable value object for a single broker round-trip.
 *
 * Keeps the view layer from having to know whether the broker answered
 * cleanly or with a 4xx error — both go through the same render path.
 * The chat page asks ``$response->ok`` first, then reads rows + plan
 * for a rendered message, or reads ``error`` for a friendly fallback.
 */
final readonly class BrokerResponse
{
    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $ok,
        public string $parser,          // 'heuristic' | 'llm' | 'dict' | ''
        public array $plan,
        public array $rows,
        public ?string $backend,
        public int|float|null $total,
        public ?int $tookMs,
        public ?string $error,
        public int $status,
        public array $raw,
    ) {}

    /** @param  array<string, mixed>  $body */
    public static function success(array $body): self
    {
        $result = $body['result'] ?? null;

        return new self(
            ok: true,
            parser: (string) ($body['parser'] ?? ''),
            plan: (array) ($body['plan'] ?? []),
            rows: is_array($result) ? (array) ($result['rows'] ?? []) : [],
            backend: is_array($result) ? ($result['backend'] ?? null) : null,
            total: is_array($result) ? ($result['total'] ?? null) : null,
            tookMs: is_array($result) ? ($result['took_ms'] ?? null) : null,
            error: null,
            status: 200,
            raw: $body,
        );
    }

    /** @param  array<string, mixed>  $raw */
    public static function failure(int $status, string $error, array $raw): self
    {
        return new self(
            ok: false,
            parser: (string) ($raw['parser'] ?? ''),
            plan: (array) ($raw['plan'] ?? []),
            rows: [],
            backend: null,
            total: null,
            tookMs: null,
            error: $error,
            status: $status,
            raw: $raw,
        );
    }
}
