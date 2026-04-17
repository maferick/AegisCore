<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Killmails per hour, last 24h — two series overlaid:
 *
 *   - "created" = when the row landed in our DB (ingest rate)
 *   - "killed"  = when the fight actually happened (content rate)
 *
 * The series diverging means R2Z2 is publishing with lag (big fight
 * backlog) or our stream is catching up after a stall. Overlaid so
 * an operator can eyeball "ingest is tracking reality" without
 * opening Horizon.
 */
class IngestThroughputChart extends ChartWidget
{
    protected ?string $heading = 'Killmails per hour (last 24h)';

    protected ?string $description = 'Orange = ingested (created_at) · Cyan = happened (killed_at)';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $since = now()->subDay()->startOfHour();

        $created = DB::table('killmails')
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as h, COUNT(*) as n")
            ->groupBy('h')
            ->pluck('n', 'h')
            ->toArray();

        $killed = DB::table('killmails')
            ->where('killed_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(killed_at, '%Y-%m-%d %H:00') as h, COUNT(*) as n")
            ->groupBy('h')
            ->pluck('n', 'h')
            ->toArray();

        $labels = [];
        $createdSeries = [];
        $killedSeries = [];
        $cursor = $since->copy();
        $end = now()->startOfHour();
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d H:00');
            $labels[] = $cursor->format('H:i');
            $createdSeries[] = (int) ($created[$key] ?? 0);
            $killedSeries[] = (int) ($killed[$key] ?? 0);
            $cursor->addHour();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Ingested',
                    'data' => $createdSeries,
                    'borderColor' => '#e5a900',
                    'backgroundColor' => 'rgba(229,169,0,0.15)',
                    'tension' => 0.2,
                ],
                [
                    'label' => 'Killed (real time)',
                    'data' => $killedSeries,
                    'borderColor' => '#4fd0d0',
                    'backgroundColor' => 'rgba(79,208,208,0.15)',
                    'tension' => 0.2,
                ],
            ],
        ];
    }
}
