<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Pipeline\PipelineHealthService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * /admin/pipeline-health dashboard widget.
 *
 * Surfaces the metrics an operator wants when triaging ingest/enrich/
 * cluster/derived-store issues. One Stat card per probe; each card
 * carries a short summary line plus a green/amber/red colour from
 * the service's level output.
 *
 * Infrastructure "is it up" lives in {@see SystemStatusWidget}; this
 * widget covers "is the pipeline keeping up".
 */
class PipelineHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Pipeline Health';

    protected ?string $description = 'Ingest, enrichment, clustering, and derived-store throughput in one view.';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        try {
            $snapshot = app(PipelineHealthService::class)->snapshot();
        } catch (Throwable $e) {
            Log::error('PipelineHealthWidget::getStats() failed', ['exception' => $e]);
            return [
                Stat::make('Pipeline Health', 'probe failed')
                    ->description('Check laravel.log')
                    ->color('danger'),
            ];
        }

        $labels = [
            'ingest_lag' => 'Ingest lag',
            'content_lag' => 'Content lag',
            'r2z2_cursor' => 'R2Z2 cursor',
            'shells' => 'Shell rows (7d)',
            'enrich_backlog' => 'Enrich backlog',
            'cluster_lag' => 'Cluster lag',
            'horizon_queues' => 'Horizon queues',
            'failed_jobs' => 'Failed jobs (24h)',
            'neo4j_edges' => 'Neo4j allegiance',
            'market_history' => 'Market history',
            'esi_backlog' => 'ESI name cache',
            'opensearch_docs' => 'OpenSearch docs',
        ];

        $cards = [];
        foreach ($labels as $key => $label) {
            $m = $snapshot[$key] ?? null;
            if ($m === null) {
                $cards[] = Stat::make($label, 'n/a')->color('gray');
                continue;
            }
            $cards[] = Stat::make($label, $m['detail'] ?? '—')
                ->color(match ($m['level'] ?? 'ok') {
                    'ok' => 'success',
                    'warn' => 'warning',
                    'down' => 'danger',
                    default => 'gray',
                });
        }

        return $cards;
    }
}
