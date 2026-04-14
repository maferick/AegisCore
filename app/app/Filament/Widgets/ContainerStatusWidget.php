<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\System\DockerStatusService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin dashboard widget: one-glance summary of the docker stack —
 * total containers, how many are running green, how many are in
 * trouble (degraded), how many stopped.
 *
 * Companion to the full {@see \App\Filament\Pages\ContainerStatus}
 * page. Operators landing on the dashboard see the headline counts;
 * the dedicated page has the per-container table.
 *
 * Heavy lifting (HTTP to the docker-socket-proxy, caching, parsing)
 * lives in {@see DockerStatusService} so the widget stays a dumb view.
 */
class ContainerStatusWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Containers';

    protected ?string $description = 'Docker stack status via read-only socket proxy.';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        try {
            $snapshot = app(DockerStatusService::class)->snapshot();
        } catch (Throwable $e) {
            Log::error('ContainerStatusWidget::getStats() failed', [
                'exception' => $e,
            ]);

            return [
                Stat::make('Containers', 'Probe failed')
                    ->description('check laravel.log')
                    ->descriptionIcon('heroicon-m-x-circle')
                    ->color('danger'),
            ];
        }

        if (! $snapshot->configured) {
            return [
                Stat::make('Containers', 'Not configured')
                    ->description('DOCKER_API_HOST is empty')
                    ->descriptionIcon('heroicon-m-question-mark-circle')
                    ->color('gray'),
            ];
        }

        if ($snapshot->isError()) {
            return [
                Stat::make('Containers', 'Unreachable')
                    ->description($snapshot->error ?? 'Unknown error')
                    ->descriptionIcon('heroicon-m-x-circle')
                    ->color('danger'),
            ];
        }

        $summary = $snapshot->summary();

        return [
            Stat::make('Total', (string) $summary['total'])
                ->description('Known to Docker')
                ->descriptionIcon('heroicon-m-cube-transparent')
                ->color('gray'),

            Stat::make('Running', (string) $summary['running'])
                ->description('Green & healthy')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($summary['running'] > 0 ? 'success' : 'gray'),

            Stat::make('Unhealthy', (string) $summary['unhealthy'])
                ->description('Restarting / paused / failing healthcheck')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($summary['unhealthy'] > 0 ? 'warning' : 'gray'),

            Stat::make('Stopped', (string) $summary['stopped'])
                ->description('Exited or dead')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($summary['stopped'] > 0 ? 'danger' : 'gray'),
        ];
    }
}
