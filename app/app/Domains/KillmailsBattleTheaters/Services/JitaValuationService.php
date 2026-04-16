<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Services;

use App\Domains\KillmailsBattleTheaters\Data\ValuationResult;
use App\Domains\Markets\Models\MarketHub;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic Jita-reference valuation for killmail items.
 *
 * Resolves historical daily-average Jita prices from `market_history`
 * for a set of type_ids at a given kill date. Falls back to
 * `ref_item_types.base_price` (SDE) when no market data exists, and
 * marks items as `unavailable` when neither source covers them.
 *
 * Two SQL queries maximum regardless of batch size: one to
 * `market_history`, one to `ref_item_types`. No N+1.
 *
 * The 7-day lookback window is a fixed constant (not configurable)
 * because the spec requires deterministic valuations — changing the
 * window would alter historical values.
 */
final class JitaValuationService
{
    /**
     * The Forge region ID (Jita's region). Reuses the constant from
     * the Markets domain.
     */
    private const JITA_REGION_ID = MarketHub::JITA_REGION_ID; // 10000002

    /**
     * Maximum days to walk back when the exact kill date has no
     * market data. 7 days covers weekends and CCP downtime gaps.
     */
    private const LOOKBACK_DAYS = 7;

    /**
     * Resolve Jita historical prices for a batch of type IDs.
     *
     * @param  list<int>  $typeIds  CCP type IDs to price.
     * @param  CarbonInterface  $killDate  The killmail timestamp (date portion used).
     * @return array<int, ValuationResult>  Keyed by type_id.
     */
    public function resolve(array $typeIds, CarbonInterface $killDate): array
    {
        $typeIds = array_values(array_unique(array_filter($typeIds, fn (int $id) => $id > 0)));

        if ($typeIds === []) {
            return [];
        }

        $result = [];
        $killDateStr = $killDate->toDateString();
        $lookbackStart = $killDate->copy()->subDays(self::LOOKBACK_DAYS)->toDateString();

        // Step 1: Query market_history for Jita region within the
        // lookback window. Fetch ALL candidate rows in one round-trip,
        // then pick the best per type_id in PHP.
        $marketRows = DB::table('market_history')
            ->where('region_id', self::JITA_REGION_ID)
            ->whereIn('type_id', $typeIds)
            ->whereBetween('trade_date', [$lookbackStart, $killDateStr])
            ->orderByDesc('trade_date')
            ->get(['type_id', 'trade_date', 'average']);

        // Group by type_id, pick the row closest to kill date (already
        // sorted DESC, so first row per type is the best match).
        $seen = [];
        foreach ($marketRows as $row) {
            $tid = (int) $row->type_id;
            if (isset($seen[$tid])) {
                continue;
            }
            $seen[$tid] = true;

            $result[$tid] = ValuationResult::fromMarketHistory(
                average: (string) $row->average,
                tradeDate: (string) $row->trade_date,
            );
        }

        // Step 2: For any type_ids not resolved from market_history,
        // fall back to ref_item_types.base_price.
        $missing = array_values(array_diff($typeIds, array_keys($result)));

        if ($missing !== []) {
            $basePrices = DB::table('ref_item_types')
                ->whereIn('id', $missing)
                ->whereNotNull('base_price')
                ->where('base_price', '>', 0)
                ->pluck('base_price', 'id');

            foreach ($basePrices as $tid => $price) {
                $result[(int) $tid] = ValuationResult::fromBasePrice((string) $price);
            }
        }

        // Step 3: Any still-unresolved types get unavailable.
        foreach ($typeIds as $tid) {
            if (! isset($result[$tid])) {
                $result[$tid] = ValuationResult::unavailable();
            }
        }

        return $result;
    }
}
