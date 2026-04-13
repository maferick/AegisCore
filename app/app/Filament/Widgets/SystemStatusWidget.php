<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\System\SystemStatus;
use App\System\SystemStatusLevel;
use App\System\SystemStatusService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin dashboard widget: one-glance health of every backend AegisCore
 * depends on (MariaDB, Redis, Horizon, OpenSearch, InfluxDB, Neo4j).
 *
 * Each backend renders as a Filament Stat card coloured green / orange /
 * red / grey based on its {@see \App\System\SystemStatusLevel}. The heavy
 * lifting (probes, timeouts, caching) lives in {@see SystemStatusService}
 * so the widget stays a dumb view.
 *
 * Kept deliberately close to the shape of {@see SdeVersionStatusWidget}
 * (just `$heading` + `$description` + `getStats()`) — earlier revisions
 * overrode `$pollingInterval`, `$columnSpan`, and `getColumns()`, which
 * mis-shadowed Filament's own property declarations and 500'd the
 * Livewire polling endpoint. The default column layout and the Page's
 * own polling are plenty; any future tuning should go through Filament's
 * documented getter methods, not raw property overrides.
 */
class SystemStatusWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'System Status';

    protected ?string $description = 'Live health of every backend AegisCore depends on.';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        try {
            $service = app(SystemStatusService::class);
            /** @var array<int, SystemStatus> $statuses */
            $statuses = $service->snapshot();

            return array_map(
                fn (SystemStatus $status): Stat => Stat::make($status->name, $status->level->label())
                    ->description($status->detail)
                    ->descriptionIcon($status->level->icon())
                    ->color($status->level->color()),
                $statuses,
            );
        } catch (Throwable $e) {
            // A bug in the service must never 500 the admin panel —
            // render a single red card instead so the operator at
            // least knows the probe itself is broken. Log the full
            // exception so we can fix it.
            Log::error('SystemStatusWidget::getStats() failed', [
                'exception' => $e,
            ]);

            return [
                Stat::make('System Status', SystemStatusLevel::DOWN->label())
                    ->description('Status probe failed — check laravel.log')
                    ->descriptionIcon(SystemStatusLevel::DOWN->icon())
                    ->color(SystemStatusLevel::DOWN->color()),
            ];
        }
    }
}
