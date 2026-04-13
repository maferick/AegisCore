<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Domains\KillmailsBattleTheaters\Events\KillmailIngested;
use App\Outbox\OutboxEvent;
use App\Outbox\OutboxRecorder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OutboxRecorderTest extends TestCase
{
    // DatabaseMigrations (not RefreshDatabase) on purpose: RefreshDatabase
    // wraps every test in an ambient DB transaction, which would make
    // DB::transactionLevel() always >= 1 and hide the "no ambient tx" guard
    // we verify in test_rejects_recording_outside_a_transaction.
    // DatabaseMigrations runs migrate:fresh between tests instead — slower,
    // but each test starts with transactionLevel() == 0, which is the real
    // state OutboxRecorder::record() expects.
    use DatabaseMigrations;

    public function test_records_event_inside_a_transaction(): void
    {
        $recorder = new OutboxRecorder();

        $event = new KillmailIngested(
            killmailId: 123_456,
            killmailHash: 'abc123',
            solarSystemId: 30_000_142, // Jita
            victimCharacterId: 95_465_499,
            attackerCharacterIds: [90_000_001, 90_000_002],
            totalValue: 10_500_000_000,
            killedAt: '2026-04-13T14:51:51Z',
        );

        DB::transaction(fn () => $recorder->record($event));

        $row = OutboxEvent::query()->sole();

        self::assertSame('killmail.ingested', $row->event_type);
        self::assertSame('killmail', $row->aggregate_type);
        self::assertSame('123456', $row->aggregate_id);
        self::assertSame($event->eventId, $row->event_id);
        self::assertSame(1, $row->version);
        self::assertNull($row->processed_at);
        self::assertSame(0, $row->attempts);
        self::assertSame('laravel', $row->producer);

        self::assertSame([
            'killmail_id' => 123_456,
            'killmail_hash' => 'abc123',
            'solar_system_id' => 30_000_142,
            'victim_character_id' => 95_465_499,
            'attacker_character_ids' => [90_000_001, 90_000_002],
            'total_value' => 10_500_000_000,
            'killed_at' => '2026-04-13T14:51:51Z',
        ], $row->payload);
    }

    public function test_rejects_recording_outside_a_transaction(): void
    {
        $recorder = new OutboxRecorder();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must be called inside a DB transaction/');

        $recorder->record(new KillmailIngested(
            killmailId: 1,
            killmailHash: 'h',
            solarSystemId: 1,
            victimCharacterId: 1,
            attackerCharacterIds: [],
            totalValue: 0,
            killedAt: '2026-04-13T00:00:00Z',
        ));
    }

    public function test_unprocessed_scope_returns_only_unprocessed_ordered_by_id(): void
    {
        $recorder = new OutboxRecorder();

        DB::transaction(function () use ($recorder) {
            $recorder->record(new KillmailIngested(1, 'a', 1, 1, [], 0, '2026-04-13T00:00:00Z'));
            $recorder->record(new KillmailIngested(2, 'b', 1, 1, [], 0, '2026-04-13T00:00:00Z'));
            $recorder->record(new KillmailIngested(3, 'c', 1, 1, [], 0, '2026-04-13T00:00:00Z'));
        });

        // Mark the middle one processed.
        OutboxEvent::query()->where('aggregate_id', '2')->update(['processed_at' => now()]);

        $unprocessedIds = OutboxEvent::query()->unprocessed()->pluck('aggregate_id')->all();

        self::assertSame(['1', '3'], $unprocessedIds);
    }
}
