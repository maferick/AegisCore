<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\Market\Services\MarketHubComparisonService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/market — side-by-side hub comparison between Jita and the
 * operator's own player-structure hub. Jita pricing is the reference;
 * own hub markup, missing SKUs, hub-only SKUs, and rough volumes
 * surface at a glance. All reads go through Influx via
 * MarketHubComparisonService — no hits on the 186M-row market_orders
 * MariaDB table.
 */
class Market extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Market';

    protected static string|UnitEnum|null $navigationGroup = 'Market';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Market — Jita vs our hub';

    protected static ?string $slug = 'market';

    protected string $view = 'filament.portal.pages.market';

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $hubs = DB::table('market_hubs')
            ->where('is_active', 1)
            ->select('id', 'location_id', 'location_type', 'region_id', 'structure_name', 'last_sync_at')
            ->get();

        // Jita = NPC hub; own hub = first non-Jita active hub.
        $jita = $hubs->firstWhere('location_id', 60003760);
        $ownHub = $hubs->first(fn ($h) => (int) $h->location_id !== 60003760);

        if ($jita === null || $ownHub === null) {
            return ['hubs_ready' => false, 'rows' => [], 'summary' => null, 'own_hub' => null, 'jita' => null];
        }

        $svc = app(MarketHubComparisonService::class);
        $rows = $svc->compare((int) $ownHub->location_id, (int) $jita->location_id);

        $onBoth = 0;
        $missingOnHub = 0;
        $onlyOnHub = 0;
        foreach ($rows as $r) {
            if ($r['missing_on_hub']) $missingOnHub++;
            elseif ($r['only_on_hub']) $onlyOnHub++;
            elseif ($r['hub_sell_price'] !== null && $r['jita_sell_price'] !== null) $onBoth++;
        }
        $skuCount = count($rows);

        // Top markup (hub sell >= Jita sell * 1.2), biggest deltas first.
        $markup = array_values(array_filter($rows, fn ($r) => ($r['markup_ratio'] ?? 0) >= 1.2));
        usort($markup, fn ($a, $b) => ($b['markup_ratio'] ?? 0) <=> ($a['markup_ratio'] ?? 0));
        $markup = array_slice($markup, 0, 20);

        // Cheaper here (ratio < 0.9, non-null).
        $cheaper = array_values(array_filter($rows, fn ($r) => $r['markup_ratio'] !== null && $r['markup_ratio'] < 0.9));
        usort($cheaper, fn ($a, $b) => ($a['markup_ratio'] ?? 0) <=> ($b['markup_ratio'] ?? 0));
        $cheaper = array_slice($cheaper, 0, 20);

        // Missing on own hub (not sold here, sold on Jita). Sort by Jita
        // sell volume desc so the busiest untapped items surface first.
        $missing = array_values(array_filter($rows, fn ($r) => $r['missing_on_hub']));
        usort($missing, fn ($a, $b) => ($b['jita_sell_volume'] ?? 0) <=> ($a['jita_sell_volume'] ?? 0));
        $missing = array_slice($missing, 0, 30);

        return [
            'hubs_ready' => true,
            'own_hub' => [
                'id' => (int) $ownHub->id,
                'location_id' => (int) $ownHub->location_id,
                'region_id' => (int) $ownHub->region_id,
                'name' => (string) ($ownHub->structure_name ?? "Hub #{$ownHub->location_id}"),
                'last_sync_at' => $ownHub->last_sync_at,
            ],
            'jita' => [
                'location_id' => (int) $jita->location_id,
                'region_id' => (int) $jita->region_id,
                'name' => (string) ($jita->structure_name ?? 'Jita IV-4'),
            ],
            'summary' => [
                'sku_count' => $skuCount,
                'on_both' => $onBoth,
                'missing_on_hub' => $missingOnHub,
                'only_on_hub' => $onlyOnHub,
            ],
            'markup' => $markup,
            'cheaper' => $cheaper,
            'missing' => $missing,
        ];
    }
}
