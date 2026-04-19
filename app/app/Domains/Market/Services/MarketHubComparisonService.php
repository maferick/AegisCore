<?php

declare(strict_types=1);

namespace App\Domains\Market\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Compare latest orderbook snapshots between two market hubs (typically
 * Jita vs a player-structure hub the operator runs). Reads from the
 * InfluxDB `market_orderbook` measurement written by market_poller /
 * market_importer — skipping the 186M-row `market_orders` MariaDB
 * table entirely. Python owns Influx per ADR-0003; this is a read-only
 * Flux query from Laravel.
 *
 * Cached for 5 minutes per hub-pair so repeated dashboard loads don't
 * hammer Influx.
 */
final class MarketHubComparisonService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Categories we exclude from the overview because they're not
     * fleet-ops / supply-chain relevant:
     *   8   Charge (ammo, scripts — fleets carry own, not market item)
     *   9   Blueprint (industrialist domain, not fleet ops)
     *   25  Asteroid (raw ores — mining chain, not fleet ops)
     *   30  Apparel (clothing, tattoos, portraits)
     *   42  Planetary Resources (raw PI input)
     *   43  Planetary Commodities (PI chain goods)
     *   63  Special Edition Assets (collectibles)
     *   91  SKINs (cosmetics)
     *   2118 Personalization (ship SKIN design elements)
     */
    private const EXCLUDED_CATEGORIES = [8, 9, 25, 30, 42, 43, 63, 91, 2118];

    /**
     * Specific groups within otherwise-useful categories that are
     * player/mission trash or mining-chain inputs, not fleet logistics.
     * Keeps the rest of Commodity (filaments, mutaplasmids, capital-
     * construction components) visible.
     *   280  General — Tobacco, Spirits, Antibiotics, Quafe
     *   526  Commodities — mission loot, corpses, books, vouchers
     *   422  Gas Isotopes (Material cat — raw gas, harvester output)
     *   427  Moon Materials (raw moon goo pre-reaction)
     *   967  Wormhole Minerals (raw, site-harvested)
     *   4168 Compressed Gas (Celestial cat — harvester yields)
     *   4932 Unrefined Mineral (raw)
     */
    private const EXCLUDED_GROUPS = [
        // Food / livestock / consumables
        280,   // General — Tobacco, Spirits, Antibiotics, Quafe
        281,   // Frozen — Dairy, Frozen Plant Seeds, Protein Delicacies
        283,   // Livestock — Slaver Hound
        284,   // Biohazard — Hydrochloric Acid, raw medical
        879,   // Slave Reception — Freed Slaves, Kruul's DNA

        // Mission / loot trash
        314,   // Miscellaneous — random oddments
        526,   // Commodities — mission loot, corpses, books, vouchers
        652,   // Lease — Starbase Charters
        966,   // Ancient Salvage — Sansha/Blood loot
        1676,  // Named Components — mission-reward components

        // Raw / mining chain
        422,   // Gas Isotopes
        427,   // Moon Materials (pre-reaction)
        711,   // Harvestable Cloud (Celestial) — Fullerite-C*, gas cloud yields
        754,   // Salvaged Materials
        967,   // Wormhole Minerals — raw
        4168,  // Compressed Gas
        4915,  // Prismaticite (compressed ore grouped under Material)
        4932,  // Unrefined Mineral

        // Industrial reagents / reaction inputs / PI-style commodities
        334,   // Construction Components — player-mfg intermediate
        530,   // Materials and Compounds — trade-chain junk + salvage
        712,   // Biochemical Material — booster precursor
        964,   // Hybrid Tech Components — T3C/reaction components
        974,   // Hybrid Polymers — polymer reaction output
        1995,  // Triglavian Data
        4716,  // Abyssal Battlefield Filament Materials

        // Research / blueprint-adjacent
        333,   // Datacores

        // Mission loot / tags / containers / drugs / starter junk
        101,   // Mining Drone
        237,   // Corvette — starter ships
        282,   // Radioactive — Toxic Waste etc
        313,   // Drugs — Blue Pill, Exile, Sooth Sayer, Drop
        370,   // Criminal Tags — pirate bounty tags
        409,   // Empire Insignia Drops — faction navy tags
        448,   // Audit Log Secure Container
        5067,  // Fabricator Data

        // Abyssal / reaction raw
        1996,  // Abyssal Materials (incl. mutaplasmid residue)
        4096,  // Molecular-Forged Materials
    ];

    /**
     * Best price per (type_id, side) for a hub. Keyed by the hub's
     * location_id (e.g. 60003760 for Jita IV-4). Returns
     *   [type_id => ['sell' => ['price'=>..., 'volume'=>...], 'buy' => ...]]
     *
     * @return array<int, array<string, array{price: float, volume: int, order_count: int}>>
     */
    public function latestOrderbook(int $locationId, int $windowMinutes = 10): array
    {
        return Cache::remember(
            sprintf('market.orderbook.latest.%d.%d', $locationId, $windowMinutes),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->queryLatestOrderbook($locationId, $windowMinutes),
        );
    }

    /**
     * @return array<int, array<string, array{price: float, volume: int, order_count: int}>>
     */
    private function queryLatestOrderbook(int $locationId, int $windowMinutes): array
    {
        $flux = <<<FLUX
            from(bucket: "%s")
              |> range(start: -%dm)
              |> filter(fn: (r) => r._measurement == "market_orderbook")
              |> filter(fn: (r) => r.location_id == "%d")
              |> filter(fn: (r) => r._field == "best_price" or r._field == "total_volume_remain" or r._field == "order_count")
              |> group(columns: ["type_id", "side", "_field"])
              |> last()
              |> keep(columns: ["type_id", "side", "_field", "_value"])
            FLUX;
        $flux = sprintf($flux, config('aegiscore.influxdb.bucket'), $windowMinutes, $locationId);

        $rows = $this->runFlux($flux);
        if ($rows === null) return [];

        // Flux result is row-per-(type, side, field). Pivot to
        // type_id => side => {price, volume, order_count}.
        $out = [];
        foreach ($rows as $r) {
            $tid = (int) ($r['type_id'] ?? 0);
            $side = (string) ($r['side'] ?? '');
            $field = (string) ($r['_field'] ?? '');
            $val = $r['_value'] ?? null;
            if ($tid === 0 || $side === '' || $field === '' || $val === null) continue;
            $out[$tid][$side] ??= ['price' => 0.0, 'volume' => 0, 'order_count' => 0];
            match ($field) {
                'best_price' => $out[$tid][$side]['price'] = (float) $val,
                'total_volume_remain' => $out[$tid][$side]['volume'] = (int) $val,
                'order_count' => $out[$tid][$side]['order_count'] = (int) $val,
                default => null,
            };
        }
        return $out;
    }

    /**
     * Compare two hubs and return per-type rollup:
     *   [type_id => [
     *     'type_name' => str,
     *     'hub_sell_price' => float|null,
     *     'hub_sell_volume' => int|null,
     *     'jita_sell_price' => float|null,
     *     'jita_sell_volume' => int|null,
     *     'markup_ratio' => float|null,        // hub / jita, sell side
     *     'missing_on_hub' => bool,
     *   ]]
     *
     * @return array<int, array<string, mixed>>
     */
    public function compare(int $hubLocationId, int $jitaLocationId = 60003760): array
    {
        $hub = $this->latestOrderbook($hubLocationId);
        $jita = $this->latestOrderbook($jitaLocationId);

        $allTypeIds = array_values(array_unique(array_merge(array_keys($hub), array_keys($jita))));
        if ($allTypeIds === []) return [];

        // Resolve names + filter noise categories/groups in one pass.
        $rows = DB::table('ref_item_types AS rit')
            ->join('ref_item_groups AS rig', 'rig.id', '=', 'rit.group_id')
            ->whereIn('rit.id', $allTypeIds)
            ->whereNotIn('rig.category_id', self::EXCLUDED_CATEGORIES)
            ->whereNotIn('rit.group_id', self::EXCLUDED_GROUPS)
            ->select('rit.id', 'rit.name')
            ->get();
        $names = $rows->pluck('name', 'id')->all();
        // After filtering, only keep the type_ids we kept.
        $allTypeIds = array_map('intval', array_keys($names));

        $out = [];
        foreach ($allTypeIds as $tid) {
            $hSell = $hub[$tid]['sell'] ?? null;
            $jSell = $jita[$tid]['sell'] ?? null;

            $markup = ($hSell && $jSell && ($jSell['price'] ?? 0) > 0)
                ? (float) $hSell['price'] / (float) $jSell['price']
                : null;

            $out[$tid] = [
                'type_id' => $tid,
                'type_name' => (string) ($names[$tid] ?? "type {$tid}"),
                'hub_sell_price' => $hSell['price'] ?? null,
                'hub_sell_volume' => $hSell['volume'] ?? null,
                'jita_sell_price' => $jSell['price'] ?? null,
                'jita_sell_volume' => $jSell['volume'] ?? null,
                'markup_ratio' => $markup,
                'missing_on_hub' => $jSell !== null && $hSell === null,
                'only_on_hub' => $hSell !== null && $jSell === null,
            ];
        }
        return $out;
    }

    /**
     * Daily price series from market_history (region-level, not hub-
     * specific since CCP only exposes per-region history).
     *
     * @return list<array{date:string, lowest:float, average:float, highest:float, volume:int}>
     */
    public function priceHistory(int $typeId, int $regionId, int $days = 30): array
    {
        return Cache::remember(
            sprintf('market.history.%d.%d.%d', $typeId, $regionId, $days),
            self::CACHE_TTL_SECONDS,
            function () use ($typeId, $regionId, $days): array {
                $rows = DB::table('market_history')
                    ->where('type_id', $typeId)
                    ->where('region_id', $regionId)
                    ->where('trade_date', '>=', now()->subDays($days)->toDateString())
                    ->orderBy('trade_date')
                    ->select('trade_date', 'lowest', 'average', 'highest', 'volume')
                    ->get();
                return $rows->map(fn ($r) => [
                    'date' => (string) $r->trade_date,
                    'lowest' => (float) $r->lowest,
                    'average' => (float) $r->average,
                    'highest' => (float) $r->highest,
                    'volume' => (int) $r->volume,
                ])->all();
            },
        );
    }

    /**
     * Raw Flux query via Influx HTTP v2 API. Returns decoded CSV as
     * assoc rows, or null on failure.
     *
     * @return list<array<string, mixed>>|null
     */
    private function runFlux(string $flux): ?array
    {
        $host = (string) config('aegiscore.influxdb.host');
        $org = (string) config('aegiscore.influxdb.org');
        $token = (string) config('aegiscore.influxdb.token');

        // Laravel's Http::post($url, $string) doesn't send a raw body —
        // must use withBody() so Influx receives the Flux script as the
        // request body rather than form-encoded. Without this, Influx
        // returns 400 "Flux script returns no streaming data" because
        // it never sees the `from(...)` pipeline.
        $response = Http::withHeaders([
                'Authorization' => "Token {$token}",
                'Accept' => 'application/csv',
            ])
            ->withBody($flux, 'application/vnd.flux')
            ->timeout(45)
            ->post("{$host}/api/v2/query?org=" . urlencode($org));

        if (! $response->ok()) {
            Log::warning('market-compare: influx query failed', [
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);
            return null;
        }

        return $this->parseFluxCsv((string) $response->body());
    }

    /**
     * Influx v2 annotated CSV parser. Skips annotation + empty rows,
     * uses the first non-annotation line as column headers.
     *
     * @return list<array<string, mixed>>
     */
    private function parseFluxCsv(string $csv): array
    {
        $lines = preg_split("/\r?\n/", $csv) ?: [];
        $headers = null;
        $out = [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            // Annotation rows start with '#' in first column.
            if ($line[0] === '#') continue;
            $cols = str_getcsv($line, ',', '"', '\\');
            if ($headers === null) {
                $headers = $cols;
                continue;
            }
            if (count($cols) !== count($headers)) continue;
            $row = array_combine($headers, $cols);
            $out[] = $row;
        }
        return $out;
    }
}
