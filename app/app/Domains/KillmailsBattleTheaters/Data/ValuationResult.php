<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Data;

/**
 * Immutable result of a single type_id valuation lookup.
 *
 * Uses `string` for `unitPrice` to preserve DECIMAL precision through
 * the pipeline (matching Laravel's `decimal:2` cast behaviour).
 */
final class ValuationResult
{
    public function __construct(
        /** Per-unit price as a decimal string (e.g. "1234567.89"). */
        public readonly string $unitPrice,
        /** The trade_date used (Y-m-d), null for base_price/unavailable. */
        public readonly ?string $dateUsed,
        /** 'jita_average' | 'base_price' | 'unavailable'. */
        public readonly string $source,
    ) {}

    public static function fromMarketHistory(string $average, string $tradeDate): self
    {
        return new self(
            unitPrice: $average,
            dateUsed: $tradeDate,
            source: 'jita_average',
        );
    }

    public static function fromBasePrice(string $basePrice): self
    {
        return new self(
            unitPrice: $basePrice,
            dateUsed: null,
            source: 'base_price',
        );
    }

    public static function unavailable(): self
    {
        return new self(
            unitPrice: '0.00',
            dateUsed: null,
            source: 'unavailable',
        );
    }
}
