<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Events;

use App\Outbox\DomainEvent;

/**
 * Emitted when Laravel accepts a new killmail into MariaDB and the
 * Python analytics plane should project it into OpenSearch (search),
 * Neo4j (combatant graph), and InfluxDB (per-theater kill-rate).
 *
 * V2 adds victim affiliation, ship type, region, and attacker count
 * so Python consumers can route and classify without re-querying
 * MariaDB. `victimCharacterId` is now nullable (structure/NPC kills)
 * and `totalValue` is a string for DECIMAL precision.
 */
final class KillmailIngested extends DomainEvent
{
    public const EVENT_TYPE = 'killmail.ingested';

    public const VERSION = 2;

    public function __construct(
        public readonly int $killmailId,
        public readonly string $killmailHash,
        public readonly int $solarSystemId,
        public readonly int $regionId,
        public readonly ?int $victimCharacterId,
        public readonly ?int $victimCorporationId,
        public readonly ?int $victimAllianceId,
        public readonly int $victimShipTypeId,
        /** @var list<int> */
        public readonly array $attackerCharacterIds,
        public readonly string $totalValue,
        public readonly int $attackerCount,
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
            'region_id' => $this->regionId,
            'victim_character_id' => $this->victimCharacterId,
            'victim_corporation_id' => $this->victimCorporationId,
            'victim_alliance_id' => $this->victimAllianceId,
            'victim_ship_type_id' => $this->victimShipTypeId,
            'attacker_character_ids' => $this->attackerCharacterIds,
            'total_value' => $this->totalValue,
            'attacker_count' => $this->attackerCount,
            'killed_at' => $this->killedAt,
        ];
    }
}
