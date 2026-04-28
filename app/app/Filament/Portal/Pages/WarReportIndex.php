<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * /portal/war-report — landing page with one card per active conflict.
 *
 * Each card opens its own scoped report at /portal/war-report/{conflict}
 * (handled by the WarReport page class). Cards show the conflict's
 * total km/ISK as a hook so the operator can pick at a glance.
 */
class WarReportIndex extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'War Report';

    protected static string|UnitEnum|null $navigationGroup = 'Strategic';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'War Report';

    protected static ?string $slug = 'war-report';

    protected string $view = 'filament.portal.pages.war-report-index';

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return self::buildIndexData();
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildIndexData(): array
    {
        $conflicts = [];
        foreach (array_keys(WarReport::CONFLICTS) as $key) {
            $cacheKey = WarReport::VIEW_CACHE_KEY . '.' . $key;
            $payload = Cache::get($cacheKey);
            $totals = $payload['totals'] ?? null;
            $conflicts[] = [
                'key' => $key,
                'label' => WarReport::displayLabel($key),
                'opposing_label' => WarReport::CONFLICTS[$key]['opposing_label'],
                'opposing_tint' => WarReport::CONFLICTS[$key]['opposing_tint'],
                'totals' => $totals,
                'has_data' => $totals !== null,
            ];
        }
        return ['conflicts' => $conflicts];
    }
}
