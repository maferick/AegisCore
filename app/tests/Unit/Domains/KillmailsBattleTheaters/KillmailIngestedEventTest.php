<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\KillmailsBattleTheaters;

use App\Domains\KillmailsBattleTheaters\Events\KillmailIngested;
use PHPUnit\Framework\TestCase;

final class KillmailIngestedEventTest extends TestCase
{
    public function test_version_is_2(): void
    {
        self::assertSame(2, KillmailIngested::VERSION);
    }

    public function test_event_type(): void
    {
        self::assertSame('killmail.ingested', KillmailIngested::EVENT_TYPE);
    }

    public function test_payload_contains_all_v2_fields(): void
    {
        $event = new KillmailIngested(
            killmailId: 12345,
            killmailHash: 'abc123',
            solarSystemId: 30002187,
            regionId: 10000043,
            victimCharacterId: 111,
            victimCorporationId: 222,
            victimAllianceId: 333,
            victimShipTypeId: 587,
            attackerCharacterIds: [444, 555],
            totalValue: '123456789.00',
            attackerCount: 2,
            killedAt: '2025-06-15T12:00:00Z',
        );

        $payload = $event->payload();

        self::assertSame(12345, $payload['killmail_id']);
        self::assertSame('abc123', $payload['killmail_hash']);
        self::assertSame(30002187, $payload['solar_system_id']);
        self::assertSame(10000043, $payload['region_id']);
        self::assertSame(111, $payload['victim_character_id']);
        self::assertSame(222, $payload['victim_corporation_id']);
        self::assertSame(333, $payload['victim_alliance_id']);
        self::assertSame(587, $payload['victim_ship_type_id']);
        self::assertSame([444, 555], $payload['attacker_character_ids']);
        self::assertSame('123456789.00', $payload['total_value']);
        self::assertSame(2, $payload['attacker_count']);
        self::assertSame('2025-06-15T12:00:00Z', $payload['killed_at']);
    }

    public function test_nullable_victim_character(): void
    {
        $event = new KillmailIngested(
            killmailId: 99999,
            killmailHash: 'xyz',
            solarSystemId: 30000001,
            regionId: 10000001,
            victimCharacterId: null,
            victimCorporationId: null,
            victimAllianceId: null,
            victimShipTypeId: 35832,
            attackerCharacterIds: [],
            totalValue: '0.00',
            attackerCount: 0,
            killedAt: '2025-01-01T00:00:00Z',
        );

        $payload = $event->payload();

        self::assertNull($payload['victim_character_id']);
        self::assertNull($payload['victim_corporation_id']);
        self::assertNull($payload['victim_alliance_id']);
    }

    public function test_aggregate_type_and_id(): void
    {
        $event = new KillmailIngested(
            killmailId: 42,
            killmailHash: 'h',
            solarSystemId: 1,
            regionId: 1,
            victimCharacterId: null,
            victimCorporationId: null,
            victimAllianceId: null,
            victimShipTypeId: 1,
            attackerCharacterIds: [],
            totalValue: '0.00',
            attackerCount: 0,
            killedAt: '2025-01-01T00:00:00Z',
        );

        self::assertSame('killmail', $event->aggregateType());
        self::assertSame('42', $event->aggregateId());
    }
}
