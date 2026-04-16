<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Jobs;

use App\Domains\UsersCharacters\Models\ViewerContext;
use App\Domains\UsersCharacters\Models\ViewerEntityClassification;
use App\Domains\UsersCharacters\Services\ViewerEntityClassificationResolverService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recomputes dirty classification rows for a single viewer context.
 *
 * Dispatched by the dirty-sweep console command or by the stale-sweep
 * command. One job per viewer context keeps each dispatch within the
 * plane boundary (≤ 100 rows, < 2 s p95).
 *
 * The job loads up to `max_dirty_per_viewer` dirty targets and feeds
 * them to the resolver's `resolveManyForViewerContext()`, which clears
 * `is_dirty` and stamps `last_recomputed_at` as a side effect. If
 * more dirty rows remain after this batch, they'll be picked up on
 * the next sweep.
 */
class RecomputeDirtyClassifications implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $viewerContextId,
    ) {}

    public function handle(ViewerEntityClassificationResolverService $resolver): void
    {
        $viewerContext = ViewerContext::query()->find($this->viewerContextId);
        if ($viewerContext === null || ! $viewerContext->is_active) {
            Log::debug('classification:recompute-dirty skipped — viewer context missing or inactive', [
                'viewer_context_id' => $this->viewerContextId,
            ]);

            return;
        }

        $limit = (int) config('classification.recompute.max_dirty_per_viewer', 50);

        $dirtyRows = ViewerEntityClassification::query()
            ->where('viewer_context_id', $this->viewerContextId)
            ->where('is_dirty', true)
            ->orderBy('computed_at')
            ->limit($limit)
            ->get(['target_entity_type', 'target_entity_id']);

        if ($dirtyRows->isEmpty()) {
            Log::debug('classification:recompute-dirty — no dirty rows for viewer', [
                'viewer_context_id' => $this->viewerContextId,
            ]);

            return;
        }

        $targets = $dirtyRows->map(fn ($row) => [
            'target_entity_type' => $row->target_entity_type,
            'target_entity_id' => $row->target_entity_id,
        ])->all();

        try {
            $resolver->resolveManyForViewerContext($viewerContext, $targets);
            Log::info('classification:recompute-dirty completed', [
                'viewer_context_id' => $this->viewerContextId,
                'targets_resolved' => count($targets),
            ]);
        } catch (Throwable $e) {
            Log::warning('classification:recompute-dirty failed', [
                'viewer_context_id' => $this->viewerContextId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
