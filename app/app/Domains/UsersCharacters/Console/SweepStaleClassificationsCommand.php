<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Console;

use App\Domains\UsersCharacters\Jobs\RecomputeDirtyClassifications;
use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Models\ViewerEntityClassification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Nightly sweep that marks stale classifications dirty and dispatches
 * per-viewer recompute jobs. Catches anything the event-driven
 * observers missed (e.g. a direct DB edit, a missed event, a race
 * between sync and classification write).
 *
 * Two-phase approach:
 *
 *   Phase 1 — mark dirty: find active viewers whose
 *     `last_recomputed_at` is older than the staleness window and
 *     bulk-UPDATE their classification rows to `is_dirty=1`.
 *
 *   Phase 2 — dispatch: for each distinct viewer_context_id that
 *     has at least one dirty row, dispatch a
 *     RecomputeDirtyClassifications job.
 *
 * This command is the "nightly rebuild" half of the hybrid
 * invalidation strategy documented in the viewer_entity_classifications
 * migration header.
 */
class SweepStaleClassificationsCommand extends Command
{
    protected $signature = 'classification:sweep-stale
                            {--dispatch : Dispatch recompute jobs for viewers with dirty rows (default: mark dirty only)}';

    protected $description = 'Mark stale classification rows dirty and optionally dispatch recompute jobs';

    public function handle(): int
    {
        $stalenessDays = (int) config('classification.recompute.staleness_days', 7);
        $cutoff = Carbon::now()->subDays($stalenessDays);

        // Phase 1: find stale active viewers and mark their rows dirty.
        $staleViewerIds = ViewerContext::query()
            ->where('is_active', true)
            ->where(function ($q) use ($cutoff): void {
                $q->whereNull('last_recomputed_at')
                    ->orWhere('last_recomputed_at', '<', $cutoff);
            })
            ->pluck('id');

        if ($staleViewerIds->isEmpty()) {
            $this->info('No stale viewer contexts found.');
            Log::debug('classification:sweep-stale — no stale viewers', [
                'staleness_days' => $stalenessDays,
            ]);

            return self::SUCCESS;
        }

        $marked = 0;
        foreach ($staleViewerIds->chunk(100) as $chunk) {
            $marked += ViewerEntityClassification::query()
                ->whereIn('viewer_context_id', $chunk)
                ->where('is_dirty', false)
                ->update(['is_dirty' => true]);
        }

        $this->info("Marked {$marked} classification rows dirty across {$staleViewerIds->count()} stale viewer(s).");
        Log::info('classification:sweep-stale marked dirty', [
            'stale_viewers' => $staleViewerIds->count(),
            'rows_marked' => $marked,
        ]);

        // Phase 2: optionally dispatch recompute jobs.
        if (! $this->option('dispatch')) {
            $this->info('Skipping dispatch (pass --dispatch to enqueue recompute jobs).');

            return self::SUCCESS;
        }

        $dirtyViewerIds = ViewerEntityClassification::query()
            ->where('is_dirty', true)
            ->distinct()
            ->pluck('viewer_context_id');

        $dispatched = 0;
        foreach ($dirtyViewerIds as $viewerContextId) {
            RecomputeDirtyClassifications::dispatch($viewerContextId);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} recompute job(s).");
        Log::info('classification:sweep-stale dispatched recompute', [
            'jobs_dispatched' => $dispatched,
        ]);

        return self::SUCCESS;
    }
}
