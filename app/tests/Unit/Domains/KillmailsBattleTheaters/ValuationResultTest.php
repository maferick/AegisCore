<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\KillmailsBattleTheaters;

use App\Domains\KillmailsBattleTheaters\Data\ValuationResult;
use PHPUnit\Framework\TestCase;

final class ValuationResultTest extends TestCase
{
    public function test_from_market_history(): void
    {
        $r = ValuationResult::fromMarketHistory('1234567.89', '2025-06-15');

        self::assertSame('1234567.89', $r->unitPrice);
        self::assertSame('2025-06-15', $r->dateUsed);
        self::assertSame('jita_average', $r->source);
    }

    public function test_from_base_price(): void
    {
        $r = ValuationResult::fromBasePrice('500000.00');

        self::assertSame('500000.00', $r->unitPrice);
        self::assertNull($r->dateUsed);
        self::assertSame('base_price', $r->source);
    }

    public function test_unavailable(): void
    {
        $r = ValuationResult::unavailable();

        self::assertSame('0.00', $r->unitPrice);
        self::assertNull($r->dateUsed);
        self::assertSame('unavailable', $r->source);
    }
}
