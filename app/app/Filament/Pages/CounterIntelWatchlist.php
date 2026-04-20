<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * /admin/counter-intel/watchlist — operator-managed pilot case file.
 *
 * Lists the current user's ci_review_watchlist rows joined with
 * latest anomaly signals, so a director can scan their own saved
 * investigations in one place. CSV export lands at ?export=csv.
 */
class CounterIntelWatchlist extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationLabel = 'My Watchlist';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 41;

    protected static ?string $title = 'Counter-Intel · Watchlist';

    protected static ?string $slug = 'counter-intel/watchlist';

    protected string $view = 'filament.pages.counter-intel-watchlist';

    public function mount(): void
    {
        if (request()->query('export') === 'csv') {
            $resp = $this->buildCsvResponse();
            $resp->send();
            exit; // Filament's Livewire boot runs after return; explicit exit stops it.
        }
    }

    public function getViewData(): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return ['rows' => [], 'count' => 0];
        }
        $rows = $this->rowsFor($userId);
        return ['rows' => $rows, 'count' => count($rows)];
    }

    /** @return list<array<string,mixed>> */
    private function rowsFor(int $userId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT w.character_id, w.note, w.added_at,
                   en.name AS character_name,
                   a.review_priority_band, a.review_priority_score,
                   a.hostile_overlap_pct, a.bridge_anomaly_pct,
                   a.affiliation_anomaly_pct, a.recent_hostile_join,
                   a.cohort_confidence
              FROM ci_review_watchlist w
              LEFT JOIN esi_entity_names en
                ON en.entity_id = w.character_id AND en.category = 'character'
              LEFT JOIN (
                SELECT a.*
                  FROM ci_character_anomalies_rolling a
                  JOIN (
                    SELECT character_id, MAX(window_end_date) AS mx
                      FROM ci_character_anomalies_rolling
                     GROUP BY character_id
                  ) m ON m.character_id = a.character_id AND m.mx = a.window_end_date
              ) a ON a.character_id = w.character_id
             WHERE w.user_id = ?
             ORDER BY w.added_at DESC
        SQL, [$userId]);
        return array_map(fn ($r) => (array) $r, $rows);
    }

    private function buildCsvResponse(): StreamedResponse
    {
        $userId = Auth::id() ?? 0;
        $rows = $this->rowsFor($userId);
        $filename = 'ci-watchlist-' . now()->format('Ymd-Hi') . '.csv';
        return new StreamedResponse(function () use ($rows) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, [
                'character_id', 'name', 'band', 'score',
                'hostile_overlap', 'bridge', 'affiliation', 'recent_hostile_join',
                'cohort_confidence', 'note', 'added_at',
            ]);
            foreach ($rows as $r) {
                fputcsv($fh, [
                    $r['character_id'],
                    $r['character_name'] ?? '',
                    $r['review_priority_band'] ?? '',
                    $r['review_priority_score'] ?? '',
                    $r['hostile_overlap_pct'] ?? '',
                    $r['bridge_anomaly_pct'] ?? '',
                    $r['affiliation_anomaly_pct'] ?? '',
                    ($r['recent_hostile_join'] ?? 0) ? 'Y' : '',
                    $r['cohort_confidence'] ?? '',
                    $r['note'] ?? '',
                    $r['added_at'] ?? '',
                ]);
            }
            fclose($fh);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
