<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Events;

use App\Outbox\DomainEvent;

/**
 * Emitted when Laravel accepts a new killmail into MariaDB and the
 * Python analytics plane should project it into OpenSearch (search),
 * Neo4j (combatant graph), and InfluxDB (per-theater kill-rate).
 *
 * Reference implementation: subclass DomainEvent, declare EVENT_TYPE,
 * fill aggregate{Type,Id}, return a JSON-serializable payload.
 */
final class KillmailIngested extends DomainEvent
{
    public const EVENT_TYPE = 'killmail.ingested';

    public function __construct(
        public readonly int $killmailId,
        public readonly string $killmailHash,
        public readonly int $solarSystemId,
        public readonly int $victimCharacterId,
        /** @var list<int> */
        public readonly array $attackerCharacterIds,
        public readonly int $totalValue,
        public readonly string $killedAt,
    ) {
        parent::__construct();
    }

    public function aggregateType(): string
    {
        return 'killmail';
    }

    public function aggregateId(): string
    {
        return (string) $this->killmailId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'killmail_id' => $this->killmailId,
            'killmail_hash' => $this->killmailHash,
            'solar_system_id' => $this->solarSystemId,
            'victim_character_id' => $this->victimCharacterId,
            'attacker_character_ids' => $this->attackerCharacterIds,
            'total_value' => $this->totalValue,
            'killed_at' => $this->killedAt,
        ];
    }
}
