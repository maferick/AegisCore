<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Enriched vs pending per month for the last 6 months. Stacked bars
 * so the total bar height = total killmails in that month, and the
 * amber slice = unenriched backlog. A growing amber stack at the
 * right edge means enrichment isn't keeping up with ingest.
 */
class EnrichmentTrendChart extends ChartWidget
{
    protected ?string $heading = 'Enrichment progress (last 6 months)';

    protected ?string $description = 'Green = enriched · Amber = still pending';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $since = now()->subMonths(5)->startOfMonth();
        $rows = DB::select(<<<'SQL'
            SELECT DATE_FORMAT(killed_at, '%Y-%m') AS m,
                   SUM(enriched_at IS NOT NULL) AS enriched,
                   SUM(enriched_at IS NULL)     AS pending
            FROM killmails
            WHERE killed_at >= ?
            GROUP BY m
            ORDER BY m
        SQL, [$since->toDateTimeString()]);

        $labels = [];
        $enriched = [];
        $pending = [];
        foreach ($rows as $r) {
            $labels[] = $r->m;
            $enriched[] = (int) $r->enriched;
            $pending[] = (int) $r->pending;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Enriched',
                    'data' => $enriched,
                    'backgroundColor' => 'rgba(74,222,128,0.7)',
                ],
                [
                    'label' => 'Pending',
                    'data' => $pending,
                    'backgroundColor' => 'rgba(229,169,0,0.8)',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ];
    }
}
