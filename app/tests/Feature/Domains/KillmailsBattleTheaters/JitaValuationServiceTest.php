<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\KillmailsBattleTheaters;

use App\Domains\KillmailsBattleTheaters\Services\JitaValuationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integration test for JitaValuationService — uses the real MariaDB
 * with seeded market_history + ref_item_types rows. Rolls back after
 * each test via DatabaseTransactions.
 */
final class JitaValuationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const JITA_REGION = 10000002;

    private JitaValuationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new JitaValuationService();
    }

    public function test_exact_date_match_returns_jita_average(): void
    {
        $this->seedMarketHistory(587, '2025-06-15', '10000000.00');

        $result = $this->service->resolve([587], Carbon::parse('2025-06-15'));

        self::assertArrayHasKey(587, $result);
        self::assertSame('10000000.00', $result[587]->unitPrice);
        self::assertSame('2025-06-15', $result[587]->dateUsed);
        self::assertSame('jita_average', $result[587]->source);
    }

    public function test_walkback_uses_most_recent_within_7_days(): void
    {
        // Price exists 3 days before the kill date, not on the exact date.
        $this->seedMarketHistory(587, '2025-06-12', '9500000.00');

        $result = $this->service->resolve([587], Carbon::parse('2025-06-15'));

        self::assertArrayHasKey(587, $result);
        self::assertSame('9500000.00', $result[587]->unitPrice);
        self::assertSame('2025-06-12', $result[587]->dateUsed);
        self::assertSame('jita_average', $result[587]->source);
    }

    public function test_walkback_picks_closest_date(): void
    {
        $this->seedMarketHistory(587, '2025-06-10', '8000000.00');
        $this->seedMarketHistory(587, '2025-06-14', '9000000.00');

        $result = $this->service->resolve([587], Carbon::parse('2025-06-15'));

        // Should pick 2025-06-14 (closest), not 2025-06-10.
        self::assertSame('9000000.00', $result[587]->unitPrice);
        self::assertSame('2025-06-14', $result[587]->dateUsed);
    }

    public function test_beyond_7_day_window_falls_back_to_base_price(): void
    {
        // Price exists 10 days before — outside the 7-day window.
        $this->seedMarketHistory(587, '2025-06-05', '8000000.00');
        $this->seedRefItemType(587, '5000000.00');

        $result = $this->service->resolve([587], Carbon::parse('2025-06-15'));

        self::assertSame('5000000.00', $result[587]->unitPrice);
        self::assertNull($result[587]->dateUsed);
        self::assertSame('base_price', $result[587]->source);
    }

    public function test_no_market_or_base_price_returns_unavailable(): void
    {
        // No market history, no ref_item_types entry.
        $result = $this->service->resolve([99999], Carbon::parse('2025-06-15'));

        self::assertArrayHasKey(99999, $result);
        self::assertSame('0.00', $result[99999]->unitPrice);
        self::assertNull($result[99999]->dateUsed);
        self::assertSame('unavailable', $result[99999]->source);
    }

    public function test_base_price_fallback_when_no_market_history(): void
    {
        $this->seedRefItemType(587, '3000000.00');

        $result = $this->service->resolve([587], Carbon::parse('2025-06-15'));

        self::assertSame('3000000.00', $result[587]->unitPrice);
        self::assertSame('base_price', $result[587]->source);
    }

    public function test_batch_resolves_multiple_types(): void
    {
        $this->seedMarketHistory(587, '2025-06-15', '10000000.00');
        $this->seedMarketHistory(2488, '2025-06-15', '500.00');
        $this->seedRefItemType(9999, '100.00');

        $result = $this->service->resolve([587, 2488, 9999, 77777], Carbon::parse('2025-06-15'));

        self::assertCount(4, $result);
        self::assertSame('jita_average', $result[587]->source);
        self::assertSame('jita_average', $result[2488]->source);
        self::assertSame('base_price', $result[9999]->source);
        self::assertSame('unavailable', $result[77777]->source);
    }

    public function test_empty_input_returns_empty(): void
    {
        $result = $this->service->resolve([], Carbon::parse('2025-06-15'));

        self::assertSame([], $result);
    }

    private function seedMarketHistory(int $typeId, string $date, string $average): void
    {
        DB::table('market_history')->insert([
            'trade_date' => $date,
            'region_id' => self::JITA_REGION,
            'type_id' => $typeId,
            'average' => $average,
            'highest' => $average,
            'lowest' => $average,
            'volume' => 1000,
            'order_count' => 50,
            'source' => 'test',
            'observation_kind' => 'historical_dump',
        ]);
    }

    private function seedRefItemType(int $typeId, string $basePrice): void
    {
        DB::table('ref_item_types')->insert([
            'id' => $typeId,
            'name' => "Test Type {$typeId}",
            'group_id' => 1,
            'base_price' => $basePrice,
            'published' => true,
            'data' => '{}',
        ]);
    }
}
