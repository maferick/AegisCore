<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domains\Market\Services\MarketHubComparisonService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/market/{type} — per-item drill-in from the market overview.
 * Renders current Jita vs own-hub best prices plus 90 days of
 * market_history daily lowest/average/highest/volume for the own hub's
 * region.
 */
class MarketItemHistory extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $slug = 'market/{type}';

    protected static ?string $title = 'Market — item history';

    protected string $view = 'filament.portal.pages.market-item-history';

    public int $typeId;

    public function mount(int $type): void
    {
        $this->typeId = $type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $typeRow = DB::table('ref_item_types')
            ->where('id', $this->typeId)
            ->select('id', 'name', 'group_id')
            ->first();
        if ($typeRow === null) {
            return ['not_found' => true];
        }

        $hubs = DB::table('market_hubs')->where('is_active', 1)->get();
        $jita = $hubs->firstWhere('location_id', 60003760);
        $ownHub = $hubs->first(fn ($h) => (int) $h->location_id !== 60003760);

        $svc = app(MarketHubComparisonService::class);
        $hubBook = $ownHub ? $svc->latestOrderbook((int) $ownHub->location_id) : [];
        $jitaBook = $jita ? $svc->latestOrderbook((int) $jita->location_id) : [];

        $hubEntry = $hubBook[$this->typeId] ?? null;
        $jitaEntry = $jitaBook[$this->typeId] ?? null;

        // History — use own hub's region. Fall back to Jita's region
        // (The Forge, 10000002) when own hub has no region set.
        $historyRegion = $ownHub && (int) $ownHub->region_id > 0
            ? (int) $ownHub->region_id
            : (int) ($jita->region_id ?? 10000002);
        $history = $svc->priceHistory($this->typeId, $historyRegion, 90);
        $jitaHistory = $svc->priceHistory($this->typeId, (int) ($jita->region_id ?? 10000002), 90);

        return [
            'not_found' => false,
            'type' => [
                'id' => (int) $typeRow->id,
                'name' => (string) $typeRow->name,
            ],
            'own_hub' => $ownHub ? [
                'name' => (string) ($ownHub->structure_name ?? "Hub #{$ownHub->location_id}"),
                'region_id' => (int) $ownHub->region_id,
                'sell' => is_array($hubEntry) ? ($hubEntry['sell'] ?? null) : null,
                'buy' => is_array($hubEntry) ? ($hubEntry['buy'] ?? null) : null,
            ] : null,
            'jita' => $jita ? [
                'name' => (string) ($jita->structure_name ?? 'Jita IV-4'),
                'region_id' => (int) $jita->region_id,
                'sell' => is_array($jitaEntry) ? ($jitaEntry['sell'] ?? null) : null,
                'buy' => is_array($jitaEntry) ? ($jitaEntry['buy'] ?? null) : null,
            ] : null,
            'history_region' => $historyRegion,
            'history' => $history,
            'jita_history' => $jitaHistory,
        ];
    }

    public function getTitle(): string
    {
        $name = DB::table('ref_item_types')->where('id', $this->typeId)->value('name');
        return $name ? "Market — {$name}" : 'Market item';
    }
}
