<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\HypothesisSynthesisService;
use App\Services\Ai\NvidiaNimClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * counter-intel:ai-refresh-stale — hourly fast-tier auto-refresh.
 *
 * Eligibility (any of):
 *   - top 20 active hypotheses for the bloc
 *   - row strengthened since last AI synthesis
 *   - existing AI summary in stale/expired band
 *   - never synthesised yet (ai_summary_generated_at IS NULL)
 *
 * Skip rules (enforced by service layer too — defence in depth):
 *   - NIM not configured / provider unavailable → skip
 *   - evidence hash unchanged AND ai_summary_generated_at < 24h → skip
 *   - circuit breaker open (≥3 failures in last 30 min) → abort run
 *   - daily cap reached (60 successful syntheses today) → abort run
 *
 * Tier: ALWAYS fast. Heavy tier is reserved for the operator's
 * manual "Refine with heavy model" button (ADR 0013 reversibility +
 * the heavy model's known 502 instability).
 *
 * Audit: every attempt — success or failure — writes to
 * intel_audit_log so review can reconstruct exactly what the
 * platform decided and when.
 *
 * CI-safe: exits 0 on every path. Never blocks downstream cron.
 */
class CounterIntelAiRefreshStaleCommand extends Command
{
    protected $signature = 'counter-intel:ai-refresh-stale
        {--viewer-bloc=1 : viewer bloc id}
        {--limit=10 : maximum syntheses per run}
        {--daily-cap=60 : aborts when this many succeeded today}
        {--circuit-window-minutes=30 : minutes for circuit breaker check}
        {--circuit-failure-threshold=3 : failures within window that trip the breaker}
        {--dry-run : print eligibility plan but skip the AI calls}';

    protected $description = 'Auto-refresh fast-tier AI summaries on stale/changed CI hypotheses (hourly cadence).';

    public function handle(NvidiaNimClient $nim, HypothesisSynthesisService $svc): int
    {
        $blocId = (int) $this->option('viewer-bloc');
        $limit = max(1, min(50, (int) $this->option('limit')));
        $dailyCap = max(1, (int) $this->option('daily-cap'));
        $circuitWindow = max(1, (int) $this->option('circuit-window-minutes'));
        $circuitThreshold = max(1, (int) $this->option('circuit-failure-threshold'));
        $dryRun = (bool) $this->option('dry-run');

        if (! $nim->isConfigured() && ! $dryRun) {
            $this->warn('nvidia_nim_not_configured — exit 0');
            return self::SUCCESS;
        }

        // Daily cap.
        $todayCount = DB::table('intel_audit_log')
            ->where('surface', 'ai_hypothesis')
            ->where('action', 'synthesize')
            ->whereDate('created_at', Carbon::now()->toDateString())
            ->count();
        if ($todayCount >= $dailyCap) {
            $this->warn(sprintf('daily_cap_reached today=%d cap=%d — exit 0', $todayCount, $dailyCap));
            return self::SUCCESS;
        }
        $remainingDailyBudget = $dailyCap - $todayCount;
        $effectiveLimit = min($limit, $remainingDailyBudget);

        // Circuit breaker.
        $recentFailures = DB::table('intel_audit_log')
            ->where('surface', 'ai_hypothesis')
            ->where('action', 'synthesize_failed')
            ->where('created_at', '>=', Carbon::now()->subMinutes($circuitWindow))
            ->count();
        if ($recentFailures >= $circuitThreshold) {
            $this->warn(sprintf(
                'circuit_breaker_open recent_failures=%d threshold=%d window=%dm — exit 0',
                $recentFailures, $circuitThreshold, $circuitWindow,
            ));
            return self::SUCCESS;
        }

        $candidates = $this->loadEligible($blocId, $effectiveLimit);

        if ($candidates->isEmpty()) {
            $this->info(sprintf(
                'no_eligible_rows viewer_bloc=%d daily_used=%d/%d',
                $blocId, $todayCount, $dailyCap,
            ));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'auto-refresh viewer_bloc=%d eligible=%d limit=%d daily_used=%d/%d circuit_failures_30m=%d%s',
            $blocId,
            $candidates->count(),
            $effectiveLimit,
            $todayCount,
            $dailyCap,
            $recentFailures,
            $dryRun ? ' (dry-run)' : '',
        ));

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        $totalLatency = 0;

        foreach ($candidates as $row) {
            $reason = $this->describeEligibility($row);
            $label = sprintf(
                '#%d band=%s sev=%s score=%.2f reason=%s',
                (int) $row->id, $row->confidence, $row->severity,
                (float) $row->suspicion_score, $reason,
            );

            if ($dryRun) {
                $this->line('plan '.$label);
                $skipped++;
                continue;
            }

            try {
                $result = $svc->synthesizeRow($row, NvidiaNimClient::TIER_FAST, skipIfFresh: true);
            } catch (Throwable $e) {
                Log::warning('auto_refresh.threw', [
                    'hypothesis_id' => (int) $row->id,
                    'error' => mb_substr($e->getMessage(), 0, 240),
                ]);
                $this->warn('failed '.$label.' :: '.mb_substr($e->getMessage(), 0, 200));
                $failed++;
                continue;
            }

            if ($result === null) {
                $this->line('skipped '.$label);
                $skipped++;
                // Keep going — graceful skip is expected when a row
                // squeaks past SQL eligibility but the service-layer
                // gate catches it (e.g. service marked it failed).
                continue;
            }

            $latency = (int) ($result['meta']['latency_ms'] ?? 0);
            $totalLatency += $latency;
            $this->line(sprintf(
                'ok  %s :: model=%s latency_ms=%d evidence=%d',
                $label,
                (string) ($result['meta']['model_used'] ?? '?'),
                $latency,
                is_array($result['data']['key_evidence'] ?? null)
                    ? count($result['data']['key_evidence']) : 0,
            ));
            $ok++;
        }

        $this->info(sprintf(
            'done ok=%d skipped=%d failed=%d total_latency_ms=%d',
            $ok, $skipped, $failed, $totalLatency,
        ));

        return self::SUCCESS;
    }

    /**
     * SQL-side eligibility filter. Joins the union of:
     *   (A) top N by score (active, not archived)
     *   (B) rows where last_strengthened_at > ai_summary_generated_at
     *   (C) rows whose ai_summary_freshness_state in (stale, expired)
     *   (D) rows with NULL ai_summary_generated_at
     */
    private function loadEligible(int $blocId, int $limit): \Illuminate\Support\Collection
    {
        // Eligibility window for "stale" — anything older than the
        // AGING ceiling becomes a refresh candidate.
        $staleCutoff = Carbon::now()->subHours(HypothesisSynthesisService::AGING_HOURS);

        $base = DB::table('counter_intel_hypotheses')
            ->where('viewer_bloc_id', $blocId)
            ->where('status', '<>', 'archived');

        $rows = (clone $base)
            ->where(function ($q) use ($staleCutoff) {
                $q->whereNull('ai_summary_generated_at')
                  ->orWhere('ai_summary_generated_at', '<', $staleCutoff)
                  ->orWhereColumn('last_strengthened_at', '>', 'ai_summary_generated_at');
            })
            ->orderByRaw("FIELD(confidence,'confirmed','high','medium','low')")
            ->orderByRaw("FIELD(severity,'critical','elevated','watch','info')")
            ->orderByDesc('suspicion_score')
            ->orderByDesc('last_strengthened_at')
            ->limit($limit)
            ->get();

        if ($rows->count() >= $limit) {
            return $rows;
        }

        // Top up from the absolute top-20 active set so the surface
        // never has a "blank top of queue".
        $existingIds = $rows->pluck('id')->all();
        $topUp = (clone $base)
            ->when($existingIds, fn ($q) => $q->whereNotIn('id', $existingIds))
            ->orderByRaw("FIELD(confidence,'confirmed','high','medium','low')")
            ->orderByRaw("FIELD(severity,'critical','elevated','watch','info')")
            ->orderByDesc('suspicion_score')
            ->limit($limit - $rows->count())
            ->get();

        return $rows->concat($topUp);
    }

    private function describeEligibility(object $row): string
    {
        if ($row->ai_summary_generated_at === null) {
            return 'never_synthesised';
        }
        if ($row->last_strengthened_at > $row->ai_summary_generated_at) {
            return 'strengthened_since_synth';
        }
        $state = HypothesisSynthesisService::freshnessState((string) $row->ai_summary_generated_at);
        if ($state === 'stale' || $state === 'expired') {
            return 'summary_'.$state;
        }
        return 'top_n';
    }
}
