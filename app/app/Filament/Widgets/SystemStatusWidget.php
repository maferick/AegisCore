<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\System\SystemStatusService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Admin dashboard widget: one-glance health of every backend AegisCore
 * depends on (MariaDB, Redis, Horizon, OpenSearch, InfluxDB, Neo4j).
 *
 * Each backend renders as a Filament Stat card coloured green / orange /
 * red / grey based on its {@see \App\System\SystemStatusLevel}. The heavy
 * lifting (probes, timeouts, caching) lives in {@see SystemStatusService}
 * so the widget stays a dumb view.
 *
 * Auto-polls every 15s so the page reflects a backend flipping during
 * an incident without the operator needing to refresh. The underlying
 * service caches for the same window, so polling is cheap after the
 * first hit.
 */
class SystemStatusWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'System Status';

    protected ?string $description = 'Live health of every backend AegisCore depends on.';

    protected ?string $pollingInterval = '15s';

    /**
     * Show all cards on one row on wide screens. Six backends fits a
     * 3-up grid comfortably.
     */
    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $service = app(SystemStatusService::class);
        $statuses = $service->snapshot();

        return array_map(
            fn ($status): Stat => Stat::make($status->name, $status->level->label())
                ->description($status->detail)
                ->descriptionIcon($status->level->icon())
                ->color($status->level->color()),
            $statuses,
        );
    }
}
