<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Battle-theater creations per hour (by end_time) for the last 48h.
 * Lets an operator see clustering throughput — flatlining while
 * killmails keep flowing signals a stuck clusterer. Spike patterns
 * also hint at cap fight / fleet fight timing for ops review.
 */
class TheaterRateChart extends ChartWidget
{
    protected ?string $heading = 'Battle theaters per hour (last 48h)';

    protected ?string $description = 'Bucket by theater end_time. Flat = clusterer stalled.';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $since = now()->subDays(2)->startOfHour();

        $hourly = DB::table('battle_theaters')
            ->where('end_time', '>=', $since)
            ->selectRaw("DATE_FORMAT(end_time, '%Y-%m-%d %H:00') as h, COUNT(*) as n")
            ->groupBy('h')
            ->pluck('n', 'h')
            ->toArray();

        $labels = [];
        $series = [];
        $cursor = $since->copy();
        $end = now()->startOfHour();
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d H:00');
            $labels[] = $cursor->format('M d H:i');
            $series[] = (int) ($hourly[$key] ?? 0);
            $cursor->addHour();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Theaters',
                    'data' => $series,
                    'backgroundColor' => 'rgba(79,208,208,0.6)',
                    'borderColor' => '#4fd0d0',
                ],
            ],
        ];
    }
}
