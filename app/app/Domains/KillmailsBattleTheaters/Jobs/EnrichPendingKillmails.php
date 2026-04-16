<?php

declare(strict_types=1);

namespace App\Domains\KillmailsBattleTheaters\Jobs;

use App\Domains\KillmailsBattleTheaters\Actions\EnrichKillmail;
use App\Domains\KillmailsBattleTheaters\Models\Killmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Horizon job that enriches killmails for a specific month.
 *
 * Partitioned by month so multiple workers process different date
 * ranges in parallel. Each month is ShouldBeUnique so the same month
 * doesn't run twice, but different months run concurrently across
 * Horizon's worker pool.
 *
 * The scheduler dispatches one job per unenriched month. Each job
 * self-dispatches until its month is fully enriched.
 */
final class EnrichPendingKillmails implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK_SIZE = 1000;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    private const NAME_RESOLVE_BACKLOG_THRESHOLD = 1000;

    /**
     * @param  string|null  $month  YYYY-MM to process, or null for oldest unenriched.
     */
    public function __construct(
        public readonly ?string $month = null,
    ) {}

    /**
     * Unique ID = the month, so different months run in parallel
     * but the same month doesn't overlap.
     */
    public function uniqueId(): string
    {
        return 'enrich:'.($this->month ?? 'auto');
    }

    public function handle(EnrichKillmail $action): void
    {
        $query = Killmail::unenriched()
            ->with(['items', 'attackers'])
            ->orderBy('killed_at')
            ->limit(self::CHUNK_SIZE);

        if ($this->month) {
            $start = $this->month.'-01';
            $end = date('Y-m-t', strtotime($start));
            $query->whereBetween('killed_at', [$start.' 00:00:00', $end.' 23:59:59']);
        }

        $killmails = $query->get();

        if ($killmails->isEmpty()) {
            return;
        }

        $pendingCount = Killmail::unenriched()->count();
        $resolveNames = $pendingCount <= self::NAME_RESOLVE_BACKLOG_THRESHOLD;

        $enriched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($killmails as $killmail) {
            try {
                $action->handle($killmail, resolveNames: $resolveNames);
                $enriched++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '1020')) {
                    $skipped++;
                } else {
                    $failed++;
                    Log::error('enrich-killmail: failed', [
                        'killmail_id' => $killmail->killmail_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('enrich-killmails: batch complete', [
            'month' => $this->month ?? 'auto',
            'enriched' => $enriched,
            'skipped' => $skipped,
            'failed' => $failed,
            'batch_size' => $killmails->count(),
        ]);

        // Self-dispatch immediately if more remain in this month.
        // No delay — the enrichment is pure DB work, no ESI calls.
        if ($killmails->count() === self::CHUNK_SIZE) {
            static::dispatch($this->month);
        }
    }

    /**
     * Dispatch one job per unenriched month. Called from the scheduler
     * or artisan command. Each month runs as its own unique job chain.
     */
    public static function dispatchAllMonths(): int
    {
        $months = DB::table('killmails')
            ->whereNull('enriched_at')
            ->selectRaw("DATE_FORMAT(killed_at, '%Y-%m') as month")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('month');

        foreach ($months as $month) {
            static::dispatch($month);
        }

        return $months->count();
    }
}
