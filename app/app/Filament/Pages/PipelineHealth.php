<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\EnrichmentTrendChart;
use App\Filament\Widgets\IngestThroughputChart;
use App\Filament\Widgets\TheaterRateChart;
use App\Pipeline\PipelineHealthService;
use Filament\Pages\Page;

/**
 * /admin/pipeline-health — operator dashboard of pipeline throughput,
 * backlog, and derived-store freshness. Grafana-style uniform tile
 * grid with status-coloured borders + three trend charts below.
 *
 * Sections reflect the data flow:
 *   Ingest → Enrichment → Clustering → Battle pipeline →
 *   Derived stores → Queues → ESI coverage → Account-local.
 */
class PipelineHealth extends Page
{
    protected string $view = 'filament.pages.pipeline-health';

    protected static ?string $title = 'Pipeline Health';

    protected static ?string $navigationLabel = 'Pipeline Health';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'pipeline-health';

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $snapshot = app(PipelineHealthService::class)->snapshot();
        $sections = $this->sections($snapshot);

        $overall = 'ok';
        foreach ($snapshot as $m) {
            $lvl = $m['level'] ?? 'ok';
            if ($lvl === 'down') { $overall = 'down'; break; }
            if ($lvl === 'warn') $overall = 'warn';
        }

        return [
            'snapshot' => $snapshot,
            'sections' => $sections,
            'overall' => $overall,
            'computed_at' => now()->format('Y-m-d H:i:s T'),
        ];
    }

    /**
     * Layout definition: section title → ordered list of metric keys.
     * Rendering + status colours live in the blade.
     *
     * @param array<string,mixed> $snapshot
     * @return array<int, array{title:string, icon:string, keys:list<string>}>
     */
    private function sections(array $snapshot): array
    {
        return [
            [
                'title' => 'Ingest',
                'icon' => 'heroicon-o-arrow-down-tray',
                'keys' => ['ingest_lag', 'content_lag', 'r2z2_cursor', 'shells'],
            ],
            [
                'title' => 'Enrichment + clustering',
                'icon' => 'heroicon-o-cpu-chip',
                'keys' => ['enrich_backlog', 'cluster_lag'],
            ],
            [
                'title' => 'Battle pipeline',
                'icon' => 'heroicon-o-squares-plus',
                'keys' => ['battle_pipeline', 'combat_anomalies'],
            ],
            [
                'title' => 'Queues',
                'icon' => 'heroicon-o-queue-list',
                'keys' => ['horizon_queues', 'failed_jobs'],
            ],
            [
                'title' => 'Derived stores',
                'icon' => 'heroicon-o-server-stack',
                'keys' => ['neo4j_edges', 'opensearch_docs', 'market_history', 'hub_catchments'],
            ],
            [
                'title' => 'ESI coverage',
                'icon' => 'heroicon-o-globe-alt',
                'keys' => ['esi_backlog', 'corp_history', 'alliance_history'],
            ],
            [
                'title' => 'Account-local',
                'icon' => 'heroicon-o-user-circle',
                'keys' => ['personal_orders'],
            ],
        ];
    }

    /** @return array<int, class-string> */
    protected function getFooterWidgets(): array
    {
        return [
            IngestThroughputChart::class,
            TheaterRateChart::class,
            EnrichmentTrendChart::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 2;
    }
}
