<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\HypothesisSynthesisService;
use App\Services\Ai\NvidiaNimClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * counter-intel:ai-summarize-hypotheses — enrich the top hypothesis
 * rows for a viewer bloc using the NVIDIA NIM backend.
 *
 * What it does:
 *   - reads the top N counter_intel_hypotheses rows (active, by
 *     confidence band → severity → suspicion_score)
 *   - calls HypothesisSynthesisService for each (graceful per-row
 *     failure — one bad row never blocks the batch)
 *   - persists synthesis to the row + intel_audit_log
 *
 * What it does NOT do (ADR 0013):
 *   - never raises a row's confidence band
 *   - never attaches an action
 *   - never makes a verdict-shaped claim
 *
 * CI-safe: exits 0 even when NIM is unconfigured or unavailable.
 */
class CounterIntelAiSummarizeHypothesesCommand extends Command
{
    protected $signature = 'counter-intel:ai-summarize-hypotheses
        {--viewer-bloc=1 : viewer bloc id}
        {--limit=20 : maximum rows to enrich}
        {--min-band=medium : minimum confidence band (low|medium|high|confirmed)}
        {--include-archived : include rows with status=archived}
        {--id=* : restrict to specific hypothesis ids (repeatable)}
        {--tier=fast : model tier (fast=primary+fallback, heavy=mistral-large-3 final summary)}
        {--dry-run : load + render plan but skip the AI call}';

    protected $description = 'Enrich top counter-intel hypothesis rows with AI synthesis (ADR 0013, hypothesis-shaped, no action attached).';

    public function handle(NvidiaNimClient $nim, HypothesisSynthesisService $svc): int
    {
        $blocId = (int) $this->option('viewer-bloc');
        $limit = max(1, (int) $this->option('limit'));
        $minBand = (string) $this->option('min-band');
        $includeArchived = (bool) $this->option('include-archived');
        $ids = array_filter(array_map('intval', (array) $this->option('id')));
        $dryRun = (bool) $this->option('dry-run');
        $tier = strtolower((string) $this->option('tier'));
        if (! in_array($tier, [NvidiaNimClient::TIER_FAST, NvidiaNimClient::TIER_HEAVY], true)) {
            $this->error('invalid_tier — must be fast or heavy');
            return self::INVALID;
        }

        if (! $nim->isConfigured() && ! $dryRun) {
            $this->warn('nvidia_nim_not_configured — set NVIDIA_NIM_API_KEY in .env. Exiting 0 (CI-safe).');
            return self::SUCCESS;
        }

        $bands = match ($minBand) {
            'low' => ['low', 'medium', 'high', 'confirmed'],
            'medium' => ['medium', 'high', 'confirmed'],
            'high' => ['high', 'confirmed'],
            'confirmed' => ['confirmed'],
            default => ['medium', 'high', 'confirmed'],
        };

        $q = DB::table('counter_intel_hypotheses')
            ->where('viewer_bloc_id', $blocId)
            ->whereIn('confidence', $bands);

        if (! $includeArchived) {
            $q->where('status', '<>', 'archived');
        }
        if ($ids !== []) {
            $q->whereIn('id', $ids);
        }

        $rows = $q
            ->orderByRaw("FIELD(confidence,'confirmed','high','medium','low')")
            ->orderByRaw("FIELD(severity,'critical','elevated','watch','info')")
            ->orderByDesc('suspicion_score')
            ->orderByDesc('last_strengthened_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('no_rows_match — nothing to enrich. Exiting 0.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'enriching %d row(s) viewer_bloc=%d min_band=%s tier=%s%s',
            $rows->count(),
            $blocId,
            $minBand,
            $tier,
            $dryRun ? ' (dry-run)' : '',
        ));

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        $totalLatencyMs = 0;

        foreach ($rows as $row) {
            $label = sprintf('#%d band=%s sev=%s score=%.2f',
                (int) $row->id,
                (string) $row->confidence,
                (string) $row->severity,
                (float) $row->suspicion_score,
            );

            if ($dryRun) {
                $this->line('plan '.$label.' -> would call NIM');
                $skipped++;
                continue;
            }

            try {
                $result = $svc->synthesizeRow($row, $tier);
            } catch (Throwable $e) {
                $this->warn('failed '.$label.' :: '.mb_substr($e->getMessage(), 0, 240));
                $failed++;
                continue;
            }

            if ($result === null) {
                $this->warn('skipped '.$label.' :: graceful failure (see logs)');
                $skipped++;
                continue;
            }

            $latency = (int) ($result['meta']['latency_ms'] ?? 0);
            $totalLatencyMs += $latency;
            $modelUsed = (string) ($result['meta']['model_used'] ?? '');
            $fell = ! empty($result['meta']['fell_back']);

            $this->line(sprintf(
                'ok  %s :: model=%s%s latency_ms=%d band_out=%s evidence=%d',
                $label,
                $modelUsed,
                $fell ? ' (fallback)' : '',
                $latency,
                (string) ($result['data']['confidence_band'] ?? '?'),
                is_array($result['data']['key_evidence'] ?? null) ? count($result['data']['key_evidence']) : 0,
            ));
            $ok++;
        }

        $this->info(sprintf(
            'done ok=%d skipped=%d failed=%d total_latency_ms=%d',
            $ok,
            $skipped,
            $failed,
            $totalLatencyMs,
        ));

        return self::SUCCESS;
    }
}
