<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\PipelineHealthWidget;
use Filament\Pages\Page;

/**
 * /admin/pipeline-health — operator view of data-pipeline throughput.
 *
 * Sits next to System Status in the Monitoring group. System Status
 * answers "is the infrastructure up"; this page answers "is the
 * pipeline keeping up" — ingest lag, enrichment backlog, clustering
 * freshness, derived-store sync, queue pressure.
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

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            PipelineHealthWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
