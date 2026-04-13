<?php

declare(strict_types=1);

namespace App\Outbox;

use Illuminate\Support\Str;

/**
 * Base class for every event that crosses the Laravel ↔ Python plane
 * boundary via the outbox.
 *
 * Subclasses are plain value objects. They must:
 *   1. Declare a stable `EVENT_TYPE` string ("<aggregate>.<verb-past-tense>")
 *      following the naming rules in docs/CONTRACTS.md.
 *   2. Expose an `aggregateType()` and `aggregateId()` so consumers can
 *      filter/replay per entity.
 *   3. Implement `payload(): array` — the serialized body that ends up
 *      in the outbox_events.payload JSON column.
 *
 * The event_id is a ULID generated at construction time so downstream
 * consumers can dedupe on it (SKIP LOCKED only guarantees at-least-once).
 */
abstract class DomainEvent
{
    /** "<aggregate>.<verb-past-tense>", e.g. "killmail.ingested". */
    public const EVENT_TYPE = '';

    /** Bump when the payload shape changes in a non-additive way. */
    public const VERSION = 1;

    public readonly string $eventId;

    public function __construct()
    {
        $this->eventId = (string) Str::ulid();

        if (static::EVENT_TYPE === '') {
            throw new \LogicException(
                sprintf('%s must declare a non-empty EVENT_TYPE constant.', static::class),
            );
        }
    }

    abstract public function aggregateType(): string;

    abstract public function aggregateId(): string;

    /** @return array<string, mixed> */
    abstract public function payload(): array;
}
