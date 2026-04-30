<?php

declare(strict_types=1);

namespace App\Domains\CounterIntel\Jobs;

use App\Services\Ai\HypothesisSynthesisService;
use App\Services\Ai\NvidiaNimClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operator-triggered "Refine with heavy model" action for the Counter-
 * Intel Command Surface. Calls HypothesisSynthesisService with the
 * heavy tier (mistral-large-3) for the requested hypothesis row.
 *
 * Plane-boundary exception: pipeline throughput jobs target p95 < 2s,
 * but this job is *operator-triggered*, single-row, and gated behind
 * an explicit click. It is not a fan-out / scheduled pipeline. The
 * external API call dominates wall time (30–180 s), so $timeout is
 * raised to 240 s. The job is ShouldBeUnique on (hypothesis_id) so
 * an impatient double-click cannot enqueue duplicate refinements.
 *
 * Failure mode: HypothesisSynthesisService returns null on graceful
 * NIM failure; the job logs a warning and exits 0. The caller never
 * blocks on success — the operator refreshes the surface to see the
 * refined output once it lands.
 */
class RefineHypothesisJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int seconds */
    public int $timeout = 240;

    public int $tries = 1;

    /** Unique-job lock TTL — must outlive the job's expected duration. */
    public int $uniqueFor = 300;

    public function __construct(public readonly int $hypothesisId)
    {
    }

    public function uniqueId(): string
    {
        return 'refine-hypothesis-'.$this->hypothesisId;
    }

    public function handle(HypothesisSynthesisService $svc): void
    {
        try {
            $result = $svc->synthesize($this->hypothesisId, NvidiaNimClient::TIER_HEAVY);
        } catch (Throwable $e) {
            Log::warning('refine_hypothesis_job.threw', [
                'hypothesis_id' => $this->hypothesisId,
                'error' => mb_substr($e->getMessage(), 0, 280),
            ]);
            return;
        }

        if ($result === null) {
            Log::info('refine_hypothesis_job.graceful_skip', [
                'hypothesis_id' => $this->hypothesisId,
            ]);
            return;
        }

        Log::info('refine_hypothesis_job.ok', [
            'hypothesis_id' => $this->hypothesisId,
            'model_used' => $result['meta']['model_used'] ?? null,
            'tier' => $result['meta']['tier'] ?? null,
            'latency_ms' => $result['meta']['latency_ms'] ?? null,
            'evidence_count' => is_array($result['data']['key_evidence'] ?? null)
                ? count($result['data']['key_evidence']) : 0,
        ]);
    }
}
