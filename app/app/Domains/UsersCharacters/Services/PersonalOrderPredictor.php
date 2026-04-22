<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Predict what items are worth stocking at a station based on the
 * user's (main + alts) personal order history + broader market
 * history for the region the station sits in.
 *
 * CCP's /orders/history/ doesn't carry per-sale timestamps and the
 * state enum collapses "listing fully sold" into 'expired' along
 * with "listing timed out unsold", so we decode sell-success from
 * volume_remain:
 *   - volume_remain == 0            → fully filled
 *   - volume_remain == volume_total → listing never moved
 *   - otherwise                     → partial fill
 *
 * Signals per type:
 *   - units_sold        — sum(volume_total - volume_remain) across
 *                         finalised sell orders.
 *   - listings_closed   — number of sell orders that finalised with
 *                         any fill (fully + partial).
 *   - listings_unsold   — finalised sell orders with zero fill.
 *   - sell_through_rate — units_sold ÷ units_listed.
 *   - avg_listing_days  — median (last_observed_at − issued) across
 *                         listings that had any fill (proxy for
 *                         time-to-sell when no sale timestamp).
 *   - relist_rate       — (total sell listings ÷ distinct_days_seen)
 *                         — high relist rate = item moves and the
 *                         donor keeps topping up.
 *   - realised_price    — average price across filled listings.
 *
 * Recommendation bands:
 *   stock_more  — sell_through ≥ 0.70 AND listings ≥ 3 AND
 *                 (no-data-fallback: regional volume ≥ median) →
 *                 suggested_qty = round up to a 14d runway at the
 *                 observed fill rate.
 *   reduce      — sell_through ≤ 0.30 AND listings ≥ 3.
 *   hold        — 0.30-0.70 sell_through.
 *   try_new     — type user hasn't sold here but regional volume
 *                 puts it in the top 50 items / 30d.
 *   low_data    — < 3 finalised listings at the station; not enough
 *                 to recommend either direction.
 *
 * confidence = listings count × sell_through clarity:
 *   high   ≥ 10 listings
 *   medium 4-9 listings
 *   low    1-3 listings
 */
final class PersonalOrderPredictor
{
    public const WINDOW_DAYS = 90;        // history depth CCP gives us
    public const REGIONAL_DAYS = 30;      // market_history window
    public const RUNWAY_DAYS = 14;        // target stock coverage
    public const JITA_LOCATION_ID = 60003760;
    public const JITA_REGION_ID = 10000002;
    public const MARKUP_LOW = 0.10;       // +10%
    public const MARKUP_HIGH = 0.15;      // +15%

    /**
     * @return array{
     *   station: array<string,mixed>,
     *   region: array<string,mixed>|null,
     *   user_types: list<array<string,mixed>>,
     *   opportunity_types: list<array<string,mixed>>,
     *   totals: array<string,mixed>,
     * }
     */
    public function predict(User $user, int $locationId): array
    {
        $characterIds = $user->characters()->pluck('character_id')->map(fn ($v) => (int) $v)->all();
        $station = $this->stationMeta($locationId);
        $regionId = $station['region_id'];

        $charList = $characterIds === [] ? '0' : implode(',', $characterIds);
        $myRows = DB::select(
            "SELECT type_id, is_buy, state, price, volume_total, volume_remain, issued,
                    first_observed_at, last_observed_at
               FROM personal_market_orders
              WHERE character_id IN ({$charList})
                AND location_id = ?
                AND issued >= ?",
            [$locationId, now()->subDays(self::WINDOW_DAYS)->toDateTimeString()],
        );

        $userTypes = $this->analyseUserRows($myRows);
        // Show only types with at least one finalised sell listing
        // at this station — the user's ask: "items I sold there",
        // not "every item ever touched".
        $userTypes = array_filter($userTypes, fn ($r) => ($r['listings'] ?? 0) >= 1);

        $regional = $this->regionalSignal($regionId, array_keys($userTypes));
        $jita = $this->jitaSellFloor(array_keys($userTypes));
        foreach ($userTypes as $tid => &$row) {
            $row['regional_daily_volume'] = $regional[$tid]['daily_volume'] ?? null;
            $row['regional_avg_price'] = $regional[$tid]['avg_price'] ?? null;
            $row['jita_sell'] = $jita[$tid] ?? null;
            if ($row['jita_sell'] !== null) {
                $row['jita_upmarket_low'] = round($row['jita_sell'] * (1 + self::MARKUP_LOW), 2);
                $row['jita_upmarket_high'] = round($row['jita_sell'] * (1 + self::MARKUP_HIGH), 2);
            } else {
                $row['jita_upmarket_low'] = null;
                $row['jita_upmarket_high'] = null;
            }
            $row += $this->recommendation($row);
        }
        unset($row);
        // Stable ordering: stock_more first, then reduce, then hold, then low_data.
        $bandRank = ['stock_more' => 0, 'try_new' => 1, 'hold' => 2, 'reduce' => 3, 'low_data' => 4];
        $userTypes = collect($userTypes)
            ->sortBy(fn ($r) => ($bandRank[$r['band']] ?? 99) * 1000 - ($r['listings'] ?? 0))
            ->values()
            ->all();

        $opportunityTypes = $this->opportunityCandidates($regionId, array_keys(array_flip(array_column($userTypes, 'type_id'))));

        $totals = [
            'types' => count($userTypes),
            'opportunity_types' => count($opportunityTypes),
            'band_counts' => $this->bandCounts($userTypes),
        ];

        return [
            'station' => $station,
            'region' => $regionId ? $this->regionMeta($regionId) : null,
            'user_types' => $userTypes,
            'opportunity_types' => $opportunityTypes,
            'totals' => $totals,
        ];
    }

    private function stationMeta(int $locationId): array
    {
        $row = DB::table('ref_npc_stations')
            ->leftJoin('ref_solar_systems', 'ref_solar_systems.id', '=', 'ref_npc_stations.solar_system_id')
            ->where('ref_npc_stations.id', $locationId)
            ->select(
                'ref_npc_stations.id AS station_id',
                'ref_solar_systems.name AS system_name',
                'ref_solar_systems.region_id AS region_id',
            )->first();
        if ($row) {
            return [
                'location_id' => (int) $row->station_id,
                'name' => ($row->system_name ? $row->system_name . ' station' : ("Station #" . $row->station_id)),
                'region_id' => $row->region_id ? (int) $row->region_id : null,
                'kind' => 'station',
            ];
        }
        $hub = DB::table('market_hubs')->where('location_id', $locationId)->first();
        if ($hub) {
            return [
                'location_id' => $locationId,
                'name' => $hub->structure_name ?? ('Structure #' . $locationId),
                'region_id' => (int) $hub->region_id ?: null,
                'kind' => 'structure',
            ];
        }
        return [
            'location_id' => $locationId,
            'name' => 'Unknown structure ' . substr((string) $locationId, -8),
            'region_id' => null,
            'kind' => 'unknown',
        ];
    }

    private function regionMeta(int $regionId): ?array
    {
        $row = DB::table('ref_regions')->where('id', $regionId)->first(['id', 'name']);
        return $row ? ['id' => $regionId, 'name' => $row->name] : ['id' => $regionId, 'name' => "Region #{$regionId}"];
    }

    /**
     * @param list<object> $rows
     * @return array<int,array<string,mixed>>  keyed by type_id
     */
    private function analyseUserRows(array $rows): array
    {
        $byType = [];
        foreach ($rows as $r) {
            $tid = (int) $r->type_id;
            if (! isset($byType[$tid])) {
                $byType[$tid] = [
                    'type_id' => $tid,
                    'sell_listings' => 0, 'buy_listings' => 0,
                    'sell_listings_closed' => 0,
                    'sell_listings_unsold' => 0,
                    'sell_listings_partial' => 0,
                    'units_listed' => 0, 'units_sold' => 0,
                    'listing_days' => [],
                    'realised_prices' => [],
                    'failed_prices' => [],
                    'first_seen' => null, 'last_seen' => null,
                ];
            }
            $row = &$byType[$tid];
            $isBuy = (int) $r->is_buy === 1;
            $isOpen = $r->state === 'open';

            if ($isBuy) {
                $row['buy_listings']++;
                continue;
            }
            $row['sell_listings']++;

            if ($isOpen) {
                continue;
            }
            $volTotal = (int) $r->volume_total;
            $volRemain = (int) $r->volume_remain;
            $volFilled = max(0, $volTotal - $volRemain);
            $row['units_listed'] += $volTotal;
            $row['units_sold'] += $volFilled;

            if ($volRemain === 0) {
                $row['sell_listings_closed']++;
                $row['realised_prices'][] = (float) $r->price;
            } elseif ($volRemain === $volTotal) {
                $row['sell_listings_unsold']++;
                $row['failed_prices'][] = (float) $r->price;
            } else {
                $row['sell_listings_partial']++;
                $row['realised_prices'][] = (float) $r->price;
            }

            $issued = strtotime((string) $r->issued);
            $last = strtotime((string) $r->last_observed_at);
            if ($issued > 0 && $last > 0 && $last >= $issued) {
                $row['listing_days'][] = ($last - $issued) / 86400;
            }
            if ($row['first_seen'] === null || $issued < $row['first_seen']) $row['first_seen'] = $issued;
            if ($row['last_seen'] === null || $issued > $row['last_seen']) $row['last_seen'] = $issued;
            unset($row);
        }

        foreach ($byType as $tid => &$row) {
            $finalised = $row['sell_listings_closed'] + $row['sell_listings_unsold'] + $row['sell_listings_partial'];
            $row['listings'] = $finalised;
            $row['sell_through_rate'] = $row['units_listed'] > 0 ? $row['units_sold'] / $row['units_listed'] : null;
            $row['avg_listing_days'] = $this->median($row['listing_days']);
            $row['realised_price_median'] = $this->median($row['realised_prices']);
            $row['realised_price_p25'] = $this->percentile($row['realised_prices'], 0.25);
            $row['realised_price_p75'] = $this->percentile($row['realised_prices'], 0.75);
            // units/day fill observed at this station — useful for sizing runway.
            if ($row['avg_listing_days'] !== null && $row['avg_listing_days'] > 0 && $row['units_sold'] > 0) {
                $row['daily_fill'] = $row['units_sold'] / ($row['avg_listing_days'] * max(1, $row['sell_listings_closed'] + $row['sell_listings_partial']));
            } else {
                $row['daily_fill'] = null;
            }
        }
        // Resolve names in bulk.
        $names = DB::table('ref_item_types')->whereIn('id', array_keys($byType))->pluck('name', 'id');
        foreach ($byType as $tid => &$row) {
            $row['type_name'] = $names[$tid] ?? ("type " . $tid);
        }
        return $byType;
    }

    /**
     * @param list<int> $typeIds
     * @return array<int, array{daily_volume:float,avg_price:float}>
     */
    private function regionalSignal(?int $regionId, array $typeIds): array
    {
        if (! $regionId || $typeIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $rows = DB::select(<<<SQL
            SELECT type_id,
                   AVG(volume) AS daily_volume,
                   AVG(average) AS avg_price
              FROM market_history
             WHERE region_id = ?
               AND type_id IN ($placeholders)
               AND trade_date >= ?
             GROUP BY type_id
        SQL, array_merge([$regionId], $typeIds, [now()->subDays(self::REGIONAL_DAYS)->toDateString()]));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->type_id] = [
                'daily_volume' => (float) $r->daily_volume,
                'avg_price' => (float) $r->avg_price,
            ];
        }
        return $out;
    }

    /**
     * Top 30 region-wide moving items that the user has NOT listed.
     *
     * @param array<int,int> $userTypeIds  types the user already traded
     * @return list<array<string,mixed>>
     */
    private function opportunityCandidates(?int $regionId, array $userTypeIds): array
    {
        if (! $regionId) return [];
        $since = now()->subDays(self::REGIONAL_DAYS)->toDateString();
        $skipClause = '';
        $params = [$regionId, $since];
        if ($userTypeIds) {
            $skipClause = ' AND type_id NOT IN (' . implode(',', array_fill(0, count($userTypeIds), '?')) . ')';
            $params = array_merge($params, array_values($userTypeIds));
        }
        $rows = DB::select(<<<SQL
            SELECT type_id,
                   AVG(volume) AS daily_volume,
                   AVG(average) AS avg_price
              FROM market_history
             WHERE region_id = ?
               AND trade_date >= ?
               $skipClause
             GROUP BY type_id
             HAVING daily_volume >= 10
             ORDER BY daily_volume DESC
             LIMIT 30
        SQL, $params);
        if ($rows === []) return [];
        $typeIds = array_map(fn ($r) => (int) $r->type_id, $rows);
        $names = DB::table('ref_item_types')->whereIn('id', $typeIds)->pluck('name', 'id');
        $out = [];
        foreach ($rows as $r) {
            $tid = (int) $r->type_id;
            $out[] = [
                'type_id' => $tid,
                'type_name' => $names[$tid] ?? ("type " . $tid),
                'daily_volume' => (float) $r->daily_volume,
                'avg_price' => (float) $r->avg_price,
                'suggested_qty' => (int) ceil(((float) $r->daily_volume) * 0.05 * self::RUNWAY_DAYS),
            ];
        }
        return $out;
    }

    /**
     * Banding + operational recommendation for one user-type row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function recommendation(array $row): array
    {
        $listings = (int) $row['listings'];
        $rate = $row['sell_through_rate'];

        // Band uses the rate; confidence carries the data quality so
        // 1-2 listings at 100% sell-through still reads as stock_more
        // with low confidence rather than shrugging at real signal.
        $band = 'hold';
        $confidence = 'low';
        if ($listings >= 10) $confidence = 'high';
        elseif ($listings >= 4) $confidence = 'medium';

        if ($listings < 1 || $rate === null) {
            $band = 'low_data';
        } elseif ($rate >= 0.70) {
            $band = 'stock_more';
        } elseif ($rate <= 0.30 && $listings >= 2) {
            // Require ≥ 2 before calling "reduce" — a single failed
            // listing at an off price isn't enough to tell the donor
            // to stop stocking entirely.
            $band = 'reduce';
        }

        $suggestedQty = null;
        if ($band === 'stock_more' && $row['daily_fill'] !== null && $row['daily_fill'] > 0) {
            $suggestedQty = (int) ceil($row['daily_fill'] * self::RUNWAY_DAYS);
        } elseif ($band === 'stock_more' && $row['regional_daily_volume']) {
            // Fallback: 5% of regional flow, 14d runway.
            $suggestedQty = (int) ceil($row['regional_daily_volume'] * 0.05 * self::RUNWAY_DAYS);
        }

        $expectedDays = null;
        if ($row['avg_listing_days'] !== null) $expectedDays = round($row['avg_listing_days'], 1);
        elseif ($suggestedQty && $row['regional_daily_volume']) {
            $expectedDays = round($suggestedQty / max(0.01, $row['regional_daily_volume']), 1);
        }

        $reason = match ($band) {
            'stock_more' => $listings >= 4
                ? sprintf('Sell-through %.0f%% across %d listings — item moves at this station.', (float) ($rate ?? 0) * 100, $listings)
                : sprintf('Sell-through %.0f%% on %d listing%s — small sample, expand cautiously.', (float) ($rate ?? 0) * 100, $listings, $listings === 1 ? '' : 's'),
            'reduce'     => sprintf('Only %.0f%% of listed volume sold across %d listings — pulls capital.', (float) ($rate ?? 0) * 100, $listings),
            'hold'       => sprintf('Middle ground (%.0f%% sell-through, %d listings) — current cadence works.', (float) ($rate ?? 0) * 100, $listings),
            'low_data'   => 'No finalised listings in window — observe first, decide later.',
            default      => '',
        };

        return [
            'band' => $band,
            'confidence' => $confidence,
            'suggested_qty' => $suggestedQty,
            'suggested_price_low' => $row['realised_price_p25'],
            'suggested_price_mid' => $row['realised_price_median'],
            'suggested_price_high' => $row['realised_price_p75'],
            'expected_days_to_sell' => $expectedDays,
            'reason' => $reason,
        ];
    }

    /**
     * Cheapest live Jita 4-4 sell price per type. Falls back to
     * the 7-day Jita-region market_history average when no live
     * snapshot exists (the market poller may not cover every type).
     *
     * @param list<int> $typeIds
     * @return array<int, float>
     */
    private function jitaSellFloor(array $typeIds): array
    {
        if ($typeIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $since = now()->subHours(6)->toDateTimeString();
        $rows = DB::select(<<<SQL
            SELECT type_id, MIN(price) AS price
              FROM market_orders
             WHERE location_id = ?
               AND is_buy = 0
               AND type_id IN ($placeholders)
               AND observed_at >= ?
               AND volume_remain > 0
             GROUP BY type_id
        SQL, array_merge([self::JITA_LOCATION_ID], $typeIds, [$since]));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->type_id] = (float) $r->price;
        }

        $missing = array_values(array_diff($typeIds, array_keys($out)));
        if ($missing === []) return $out;

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $sinceDate = now()->subDays(7)->toDateString();
        $fallback = DB::select(<<<SQL
            SELECT type_id, AVG(average) AS avg_price
              FROM market_history
             WHERE region_id = ?
               AND type_id IN ($placeholders)
               AND trade_date >= ?
             GROUP BY type_id
        SQL, array_merge([self::JITA_REGION_ID], $missing, [$sinceDate]));
        foreach ($fallback as $r) {
            $out[(int) $r->type_id] = (float) $r->avg_price;
        }
        return $out;
    }

    /** @param list<int> $userTypeIds */
    private function bandCounts(array $userTypes): array
    {
        $c = ['stock_more' => 0, 'reduce' => 0, 'hold' => 0, 'low_data' => 0];
        foreach ($userTypes as $t) {
            $b = $t['band'] ?? null;
            if ($b && isset($c[$b])) $c[$b]++;
        }
        return $c;
    }

    private function median(array $values): ?float
    {
        if ($values === []) return null;
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function percentile(array $values, float $q): ?float
    {
        if ($values === []) return null;
        sort($values);
        $pos = ($q * (count($values) - 1));
        $low = (int) floor($pos);
        $high = (int) ceil($pos);
        if ($low === $high) return (float) $values[$low];
        return $values[$low] + ($values[$high] - $values[$low]) * ($pos - $low);
    }
}
